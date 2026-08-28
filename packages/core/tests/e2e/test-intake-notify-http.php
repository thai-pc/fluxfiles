<?php

/**
 * End-to-end HTTP test for Intake "notify-on-receipt" — an intake portal upload
 * firing a Webhooks `intake_received` event. Boots the real router (own `php -S`,
 * backs up/restores packages/core/.env, needs curl) plus a tiny local HTTP receiver
 * that records the last webhook POST it got.
 *
 * This does NOT re-test the Intake module gate itself (see test-intake-http.php for
 * the full 501/402/403 3-layer coverage). It only exercises the cross-module wiring
 * added in PublicLinks.php's POST /api/fm/intake/upload branch:
 *
 *   1. Intake licensed, Webhooks module ABSENT entirely            → upload succeeds,
 *      no webhook fires (installed() gate false, no class to dispatch with).
 *   2. Both installed, Webhooks UNLICENSED                          → upload succeeds,
 *      no webhook fires (licensed() gate false).
 *   3. Both installed + licensed — the real journey:
 *      a. portal with no baked webhook config                       → no webhook.
 *      b. portal with webhook config (explicit secret)               → a signed
 *         `intake_received` POST lands at the receiver with the right payload.
 *      c. webhook_events filter excludes `intake_received`           → no webhook.
 *      d. webhook_url points at a closed port (unreachable)          → upload STILL
 *         succeeds 200 (the fail-open guarantee — a dead receiver must never break
 *         the anonymous sender's own response).
 *      e. portal with no explicit webhook_secret                     → the signature
 *         falls back to signing with the server secret (FLUXFILES_SECRET).
 *
 * Skipped entirely when either packages/intake or packages/webhooks isn't checked
 * out on this machine, or when the offline license-signing key isn't present — the
 * normal case in CI (both are gitignored private packages).
 *
 * Usage: php tests/e2e/test-intake-notify-http.php   (requires the curl extension)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../embed.php';

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $yellow = "\033[33m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $name, callable $fn): void {
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

$SECRET = str_repeat('n', 40);
$_ENV['FLUXFILES_SECRET'] = $SECRET; // fluxfiles_token() (embed.php) signs with this in-process
$coreDir = (string) realpath(__DIR__ . '/../..');
$repoRoot = (string) realpath(__DIR__ . '/../../../..');
$uploadRoot = $coreDir . '/storage/uploads';
$PREFIX = 'intakewh_e2e';

// ── HTTP helpers (same shape as test-intake-http.php) ───────────────────────
/** @return array{0:int,1:array<string,string>,2:string} [status, headers(lower), body] */
function req(string $method, string $url, array $opt = []): array {
    $hdr = [];
    $ch = curl_init($url);
    $o = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $opt['headers'] ?? [],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$hdr) {
            $p = explode(':', $line, 2);
            if (count($p) === 2) { $hdr[strtolower(trim($p[0]))] = trim($p[1]); }
            return strlen($line);
        },
    ];
    if (isset($opt['json'])) {
        $o[CURLOPT_POSTFIELDS] = json_encode($opt['json']);
        $o[CURLOPT_HTTPHEADER] = array_merge($o[CURLOPT_HTTPHEADER], ['Content-Type: application/json']);
    }
    if (isset($opt['form'])) {
        $o[CURLOPT_POSTFIELDS] = $opt['form']; // array => multipart/form-data, incl. CURLFile entries
    }
    curl_setopt_array($ch, $o);
    $body = (string) curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, $hdr, $body];
}
/** @return array{0:int,1:array<string,string>,2:array<string,mixed>|null} */
function reqJson(string $method, string $url, array $opt = []): array {
    [$st, $h, $body] = req($method, $url, $opt);
    return [$st, $h, json_decode($body, true)];
}

// ── server control ──────────────────────────────────────────────────────────
$procs = [];
function boot(int $port, string $router): array {
    global $coreDir, $procs;
    $cmd = ['php', '-S', "127.0.0.1:{$port}", '-t', $coreDir, $router];
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $coreDir);
    if (!is_resource($p)) { fwrite(STDERR, "could not start server on {$port}\n"); exit(1); }
    $procs[] = $p;
    for ($i = 0; $i < 60; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
        if ($c) { fclose($c); break; }
        usleep(100000);
    }
    return [$p, "http://127.0.0.1:{$port}"];
}
function stop($p): void {
    global $procs;
    $procs = array_values(array_filter($procs, static fn ($x) => $x !== $p));
    @proc_terminate($p);
    @proc_close($p);
}

// ── local webhook receiver: records the LAST request it got ────────────────
$RECEIVER_PORT = 8163;
$capture = sys_get_temp_dir() . '/ff-intake-wh-' . uniqid() . '.json';
$receiver = sys_get_temp_dir() . '/ff-intake-wh-recv-' . uniqid() . '.php';
file_put_contents($receiver, '<?php $h = function($k){ return $_SERVER[$k] ?? ""; };'
    . 'file_put_contents(' . var_export($capture, true) . ', json_encode(['
    . '"body" => file_get_contents("php://input"),'
    . '"sig" => $h("HTTP_X_FLUXFILES_SIGNATURE"),'
    . '"event" => $h("HTTP_X_FLUXFILES_EVENT"),'
    . '])); http_response_code(200); echo "ok";');
$receiverProc = proc_open(['php', '-S', "127.0.0.1:{$RECEIVER_PORT}", '-t', dirname($receiver), $receiver],
    [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $receiverPipes);
for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $RECEIVER_PORT, $e, $s, 0.2); if ($c) { fclose($c); break; } usleep(100000); }
$RECEIVER_URL = "http://127.0.0.1:{$RECEIVER_PORT}/" . basename($receiver);
/** @return array{body:string,sig:string,event:string}|null */
function lastCapture(string $f): ?array {
    if (!is_file($f)) { return null; }
    $d = json_decode((string) file_get_contents($f), true);
    return is_array($d) ? $d : null;
}
function resetCapture(string $f): void { @unlink($f); }

// ── fixtures / .env ──────────────────────────────────────────────────────────
@mkdir("{$uploadRoot}/{$PREFIX}/drop", 0777, true);
$envFile = $coreDir . '/.env';
$envBackup = is_file($envFile) ? file_get_contents($envFile) : null;
// localhost is SSRF-blocked by default — this test's receiver IS localhost.
$baseEnv = "FLUXFILES_SECRET={$SECRET}\nFLUXFILES_RATE_LIMIT_READ=100000\nFLUXFILES_RATE_LIMIT_WRITE=100000\n"
    . "FLUXFILES_INTAKE_UPLOAD_LIMIT=100000\nFLUXFILES_INTAKE_UPLOAD_TOTAL=100000\nFLUXFILES_INTAKE_RATE_LIMIT=100000\n"
    . "FLUXFILES_WEBHOOK_ALLOW_INTERNAL=true\n";
file_put_contents($envFile, $baseEnv);

$intakeSrc = "{$repoRoot}/packages/intake/src/IntakeModule.php";
$webhooksSrc = "{$repoRoot}/packages/webhooks/src/WebhooksModule.php";

if (!is_file($intakeSrc) || !is_file($webhooksSrc)) {
    echo "\n  {$yellow}skip{$reset} test-intake-notify-http.php (packages/intake or packages/webhooks not checked out)\n";
    if ($envBackup === null) { @unlink($envFile); } else { file_put_contents($envFile, $envBackup); }
    @proc_terminate($receiverProc);
    exit(0);
}

// Wrapper routers — the built-in server ignores auto_prepend_file for its router
// script, so pre-require the module(s) before delegating to the real router.php
// (same trick test-intake-http.php uses).
$routerIntakeOnly = sys_get_temp_dir() . '/ff-intake-only-router-' . getmypid() . '.php';
file_put_contents($routerIntakeOnly, "<?php\nrequire_once '{$coreDir}/vendor/autoload.php';\nrequire_once '{$intakeSrc}';\nreturn require '{$coreDir}/router.php';\n");
$routerBoth = sys_get_temp_dir() . '/ff-intake-webhooks-router-' . getmypid() . '.php';
file_put_contents($routerBoth, "<?php\nrequire_once '{$coreDir}/vendor/autoload.php';\nrequire_once '{$intakeSrc}';\nrequire_once '{$webhooksSrc}';\nreturn require '{$coreDir}/router.php';\n");

/** A real license, or '' when the offline signing key isn't on this box. */
function mintLicense(string $repoRoot, string $modules): string {
    $gen = "{$repoRoot}/scripts/license-gen.php";
    $keyFile = "{$repoRoot}/docs/license-signing-key.SECRET.txt";
    if (!is_file($gen) || !is_file($keyFile) || !function_exists('sodium_crypto_sign_detached')) { return ''; }
    $secret = '';
    foreach (preg_split('/\R/', (string) file_get_contents($keyFile)) ?: [] as $line) {
        $line = trim($line);
        if (preg_match('#^[A-Za-z0-9+/=]{80,}$#', $line) && strlen((string) base64_decode($line, true)) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $secret = $line;
        }
    }
    if ($secret === '') { return ''; }
    $env = array_merge(getenv(), ['FLUXFILES_LICENSE_PRIVATE_KEY' => $secret]);
    $p = proc_open(['php', $gen, '--edition=pro', "--modules={$modules}", '--enforcement=perpetual', '--expires=+30d', '--kid=k1'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($p)) { return ''; }
    $tok = trim((string) stream_get_contents($pipes[1])); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $tok;
}

echo "\n{$cyan}══ Intake notify-on-receipt over HTTP (e2e) ══{$reset}\n\n";

try {
    $licenseIntakeOnly = mintLicense($repoRoot, 'intake');
    $licenseBoth = mintLicense($repoRoot, 'intake,webhooks');
    if ($licenseIntakeOnly === '' || $licenseBoth === '') {
        echo "  {$yellow}skip{$reset} (no offline license-signing key on this machine — expected in CI)\n";
        throw new \RuntimeException('__skip__');
    }

    /** Mint a portal through the real operator route. @return array<string,mixed> */
    $mint = static function (string $B, array $opClaims, array $body) use ($PREFIX): array {
        $op = fluxfiles_token(['user' => $opClaims['user'] ?? 'whop', 'perms' => ['read', 'write'], 'disks' => ['local'],
            'prefix' => $PREFIX, 'ttl' => 600, 'claims' => array_merge(['allow_intake' => true], $opClaims['claims'] ?? [])]);
        [$st, , $j] = reqJson('POST', "{$B}/api/fm/intake", ['json' => array_merge(['disk' => 'local', 'path' => 'drop'], $body), 'headers' => ["Authorization: Bearer {$op}"]]);
        if ($st !== 200) { throw new \RuntimeException('mint failed: ' . $st . ' ' . json_encode($j)); }
        return $j['data'];
    };
    /** Upload one file to a portal over real multipart HTTP. */
    $upload = static function (string $B, string $token, string $filename, string $contents): array {
        $tmp = tempnam(sys_get_temp_dir(), 'ff_intakewh_');
        file_put_contents($tmp, $contents);
        $form = ['token' => $token, 'file' => new \CURLFile($tmp, 'application/octet-stream', $filename)];
        [$st, $h, $body] = req('POST', "{$B}/api/fm/intake/upload", ['form' => $form]);
        @unlink($tmp);
        return [$st, $h, json_decode($body, true)];
    };

    // ════════════════════════════════════════════════════════════════════════
    // Phase 1 — Intake licensed, Webhooks module ABSENT entirely.
    // ════════════════════════════════════════════════════════════════════════
    echo "{$cyan}── phase 1: webhooks module absent ──{$reset}\n\n";
    file_put_contents($envFile, $baseEnv . "FLUXFILES_LICENSE_KEY={$licenseIntakeOnly}\n");
    [$srv1, $B1] = boot(8116, $routerIntakeOnly);

    test('webhooks class absent: upload still succeeds, no webhook fires', function () use ($B1, $mint, $upload, $RECEIVER_URL, $capture) {
        resetCapture($capture);
        $p = $mint($B1, ['user' => 'whop1', 'claims' => ['allow_webhooks' => true, 'webhook_url' => $RECEIVER_URL]], []);
        [$st, , $j] = $upload($B1, $p['token'], 'a.txt', 'hello');
        assertEqual(200, $st);
        assertEqual(true, $j['data']['received']);
        assertEqual(null, lastCapture($capture), 'no webhook class -> ModuleRegistry::installed(webhooks) is false -> no dispatch');
    });

    stop($srv1);

    // ════════════════════════════════════════════════════════════════════════
    // Phase 2 — both installed, Webhooks UNLICENSED.
    // ════════════════════════════════════════════════════════════════════════
    echo "\n{$cyan}── phase 2: webhooks installed but unlicensed ──{$reset}\n\n";
    file_put_contents($envFile, $baseEnv . "FLUXFILES_LICENSE_KEY={$licenseIntakeOnly}\n"); // covers intake only
    [$srv2, $B2] = boot(8117, $routerBoth);

    test('webhooks class present but unlicensed: upload still succeeds, no webhook fires', function () use ($B2, $mint, $upload, $RECEIVER_URL, $capture) {
        resetCapture($capture);
        $p = $mint($B2, ['user' => 'whop2', 'claims' => ['allow_webhooks' => true, 'webhook_url' => $RECEIVER_URL]], []);
        [$st, , $j] = $upload($B2, $p['token'], 'b.txt', 'hello');
        assertEqual(200, $st);
        assertEqual(true, $j['data']['received']);
        assertEqual(null, lastCapture($capture), 'licensed(webhooks) is false -> no dispatch');
    });

    stop($srv2);

    // ════════════════════════════════════════════════════════════════════════
    // Phase 3 — both installed + licensed: the real journey.
    // ════════════════════════════════════════════════════════════════════════
    echo "\n{$cyan}── phase 3: both installed + licensed ──{$reset}\n\n";
    file_put_contents($envFile, $baseEnv . "FLUXFILES_LICENSE_KEY={$licenseBoth}\n");
    [$srv3, $B] = boot(8118, $routerBoth);

    test('no webhook config on the minting token -> no webhook fires', function () use ($B, $mint, $upload, $capture) {
        resetCapture($capture);
        $p = $mint($B, ['user' => 'whop3a', 'claims' => []], []);
        [$st, , $j] = $upload($B, $p['token'], 'c.txt', 'hello');
        assertEqual(200, $st);
        assertEqual(true, $j['data']['received']);
        assertEqual(null, lastCapture($capture), 'no allow_webhooks/webhook_url -> record baked webhook:null');
    });

    test('webhook config present (explicit secret): a signed `intake_received` POST lands with the right payload', function () use ($B, $mint, $upload, $RECEIVER_URL, $capture) {
        resetCapture($capture);
        $p = $mint($B, ['user' => 'whop3b', 'claims' => [
            'allow_webhooks' => true, 'webhook_url' => $RECEIVER_URL, 'webhook_secret' => 'sekrit-3b',
        ]], ['label' => 'Client drop-off']);
        [$st, , $j] = $upload($B, $p['token'], 'invoice.pdf', '%PDF-hello');
        assertEqual(200, $st);
        assertEqual(true, $j['data']['received']);
        usleep(200000);
        $c = lastCapture($capture);
        assertTrue($c !== null, 'receiver got a request');
        assertEqual('intake_received', $c['event'], 'X-FluxFiles-Event header');
        $body = json_decode($c['body'], true);
        assertEqual('sha256=' . hash_hmac('sha256', $c['body'], 'sekrit-3b'), $c['sig'], 'HMAC signed with the portal-configured secret');
        assertEqual('intake_received', $body['event']);
        assertEqual('whop3b', $body['user'], 'the portal OWNER, not the anonymous sender');
        assertEqual('local', $body['disk']);
        assertEqual("{$GLOBALS['PREFIX']}/drop", $body['path']);
        assertEqual($j['data']['name'], $body['name'], 'the STORED (possibly collision-renamed) filename');
        assertEqual('Client drop-off', $body['portal_label']);
        assertEqual($p['jti'], $body['portal_jti']);
    });

    test('webhook_events filter excludes `intake_received` -> no webhook fires', function () use ($B, $mint, $upload, $RECEIVER_URL, $capture) {
        resetCapture($capture);
        $p = $mint($B, ['user' => 'whop3c', 'claims' => [
            'allow_webhooks' => true, 'webhook_url' => $RECEIVER_URL, 'webhook_events' => ['upload'],
        ]], []);
        [$st, , $j] = $upload($B, $p['token'], 'd.txt', 'hello');
        assertEqual(200, $st);
        assertEqual(true, $j['data']['received']);
        assertEqual(null, lastCapture($capture), 'the event filter dropped intake_received');
    });

    test('unreachable webhook_url (closed port): the upload response is UNAFFECTED (fail-open)', function () use ($B, $mint, $upload) {
        $p = $mint($B, ['user' => 'whop3d', 'claims' => [
            'allow_webhooks' => true, 'webhook_url' => 'http://127.0.0.1:8199/', // nothing listens here
        ]], []);
        $started = microtime(true);
        [$st, , $j] = $upload($B, $p['token'], 'e.txt', 'hello');
        $elapsed = microtime(true) - $started;
        assertEqual(200, $st, 'a dead webhook endpoint never surfaces as an upload error');
        assertEqual(true, $j['data']['received']);
        assertTrue($elapsed < 8.0, 'a refused connection fails fast, not on the full curl timeout budget');
    });

    test('no explicit webhook_secret -> the signature falls back to the SERVER secret', function () use ($B, $mint, $upload, $RECEIVER_URL, $capture, $SECRET) {
        resetCapture($capture);
        $p = $mint($B, ['user' => 'whop3e', 'claims' => ['allow_webhooks' => true, 'webhook_url' => $RECEIVER_URL]], []);
        [$st] = $upload($B, $p['token'], 'f.txt', 'hello');
        assertEqual(200, $st);
        usleep(200000);
        $c = lastCapture($capture);
        assertTrue($c !== null, 'receiver got a request');
        assertEqual('sha256=' . hash_hmac('sha256', $c['body'], $SECRET), $c['sig'], 'falls back to FLUXFILES_SECRET when webhook_secret is empty');
    });

    stop($srv3);
} catch (\Throwable $e) {
    if ($e->getMessage() !== '__skip__') { throw $e; }
} finally {
    if ($envBackup === null) { @unlink($envFile); } else { file_put_contents($envFile, $envBackup); }
    foreach ($procs as $p) { if (is_resource($p)) { @proc_terminate($p); } }
    @proc_terminate($receiverProc);
    @unlink($routerIntakeOnly);
    @unlink($routerBoth);
    @unlink($receiver);
    @unlink($capture);
    $dir = "{$uploadRoot}/{$PREFIX}";
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($dir);
    }
}

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
