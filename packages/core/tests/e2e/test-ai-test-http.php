<?php

declare(strict_types=1);

/**
 * POST /api/fm/ai/test (docs/design/CONNECTION-TEST-DESIGN.md), end to end against a fake
 * provider: gate parity with /ai-tag, classified failures answered as 200 {ok:false}, the
 * 2/min bucket, audit entry, `detail` only for unscoped tokens, no key in any response, the
 * Doctor `ai` block, and /ai-tag + upload surfacing the classified code. Two core servers
 * (provider configured / not) and the fake provider all bind ephemeral ports.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\JwtCompat;

if (!extension_loaded('curl')) {
    echo "SKIP: curl required\n";
    exit(0);
}
$core = realpath(__DIR__ . '/../..');
$secret = str_repeat('ai-test-http-secret-', 3);
$apiKey = 'sk-test-e2e-secret-KEY1234567890';
$runId = bin2hex(random_bytes(6));
$scenarioFile = tempnam(sys_get_temp_dir(), 'ff-ai-scn');
file_put_contents($scenarioFile, 'ok');

function freeAddress(): string
{
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $a = stream_socket_get_name($s, false);
    fclose($s);
    return $a;
}
function boot(array $argv, array $env, string $cwd)
{
    $p = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $cwd, $env);
    if (!is_resource($p)) {
        throw new RuntimeException('Could not start a test server');
    }
    return $p;
}
function waitFor(string $address): void
{
    for ($i = 0; $i < 50; $i++) {
        $s = @stream_socket_client('tcp://' . $address, $e, $m, .2);
        if ($s) {
            fclose($s);
            return;
        }
        usleep(100000);
    }
}

$fakeAddr = freeAddress();
$onAddr = freeAddress();
$offAddr = freeAddress();
$baseEnv = array_merge(getenv(), [
    'FLUXFILES_SECRET' => $secret,
    'FLUXFILES_RATE_LIMIT_READ' => '100000',
    'FLUXFILES_RATE_LIMIT_WRITE' => '100000',
    'FLUXFILES_AI_PROVIDER' => '',
    'FLUXFILES_AI_API_KEY' => '',
    'FLUXFILES_AI_MODEL' => '',
    'FLUXFILES_AI_BASE_URL' => '',
    'FLUXFILES_AI_AUTO_TAG' => '',
]);
$procs = [];
$procs[] = boot([PHP_BINARY, '-S', $fakeAddr, __DIR__ . '/fixtures/fake-ai-provider.php'],
    array_merge(getenv(), ['FAKE_AI_SCENARIO_FILE' => $scenarioFile]), __DIR__);
$procs[] = boot([PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', $onAddr, 'router.php'], array_merge($baseEnv, [
    'FLUXFILES_AI_PROVIDER' => 'claude',
    'FLUXFILES_AI_API_KEY' => $apiKey,
    'FLUXFILES_AI_MODEL' => 'retired-model',
    'FLUXFILES_AI_BASE_URL' => 'http://' . $fakeAddr . '/v1',
]), $core);
$procs[] = boot([PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', $offAddr, 'router.php'], $baseEnv, $core);

$prefixes = [];
function token(string $user, array $extra = []): string
{
    global $secret, $prefixes, $runId;
    $prefix = array_key_exists('prefix', $extra) ? $extra['prefix'] : 'aitest-' . $runId;
    unset($extra['prefix']);
    if ($prefix !== '') {
        $prefixes[$prefix] = true;
    }
    return JwtCompat::encode(array_merge([
        'sub' => $user . '-' . $runId, 'iat' => time(), 'exp' => time() + 300,
        'perms' => ['read', 'write', 'delete', 'audit'], 'disks' => ['local'], 'prefix' => $prefix,
    ], $extra), $secret);
}
function request(string $addr, string $method, string $path, string $token, ?array $body = null, ?array $multipart = null): array
{
    $ch = curl_init('http://' . $addr . '/api/fm/' . $path);
    $headers = ['Authorization: Bearer ' . $token];
    if ($multipart === null) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers]);
    if ($multipart !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart);
    } elseif ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = (string) curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$status, json_decode($raw, true) ?? ['raw' => $raw], $raw];
}
function scenario(string $name): void
{
    global $scenarioFile;
    file_put_contents($scenarioFile, $name);
}
function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
$passed = 0;
$failed = 0;
function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "PASS $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL $name: {$e->getMessage()}\n";
    }
}

try {
    foreach ([$fakeAddr, $onAddr, $offAddr] as $a) {
        waitFor($a);
    }

    test('pass: write token gets 200 ok (ai_auto_tag unset AND false)', function () use ($onAddr) {
        scenario('ok');
        foreach ([[], ['ai_auto_tag' => false]] as $i => $extra) {
            [$st, $j, $raw] = request($onAddr, 'POST', 'ai/test', token('pass' . $i, $extra), ['disk' => 'local']);
            check($st === 200 && ($j['data']['ok'] ?? null) === true, "status $st: $raw");
            check($j['data']['code'] === null && $j['data']['check'] === 'vision' && $j['data']['provider'] === 'claude', 'result shape: ' . $raw);
            check(is_int($j['data']['latency_ms']), 'latency_ms');
        }
    });

    test('classified failure is 200 ok:false, never 401/403/429; key never leaks; scoped token gets no detail', function () use ($onAddr, $apiKey) {
        scenario('badkey');
        [$st, $j, $raw] = request($onAddr, 'POST', 'ai/test', token('scoped'), []);
        check($st === 200 && $j['data']['ok'] === false && $j['data']['code'] === 'ai_auth', "status $st: $raw");
        check($j['data']['detail'] === null, 'scoped token must not get detail');
        check(!str_contains($raw, $apiKey) && !str_contains($raw, 'KEY1234567890'), 'API key leaked in response');
        check(is_string($j['data']['hint']) && $j['data']['hint'] !== '', 'hint missing');
    });

    test('unscoped token gets the redacted detail (key masked)', function () use ($onAddr, $apiKey) {
        scenario('badkey');
        [$st, $j, $raw] = request($onAddr, 'POST', 'ai/test', token('admin', ['prefix' => '']), []);
        check($st === 200 && $j['data']['code'] === 'ai_auth', "status $st: $raw");
        check(is_string($j['data']['detail']) && $j['data']['detail'] !== '', 'unscoped token must get detail');
        check(!str_contains($raw, $apiKey) && !str_contains($raw, 'KEY1234567890'), 'API key leaked in detail');
    });

    test('quota failure is classified (402 -> ai_quota)', function () use ($onAddr) {
        scenario('quota');
        [$st, $j, $raw] = request($onAddr, 'POST', 'ai/test', token('quota'), []);
        check($st === 200 && $j['data']['code'] === 'ai_quota', "status $st: $raw");
    });

    test('gate: disk not in token -> 403 disk_denied; read-only -> 403 permission_denied', function () use ($onAddr) {
        [$st, $j] = request($onAddr, 'POST', 'ai/test', token('nodisk', ['disks' => ['s3']]), ['disk' => 'local']);
        check($st === 403 && $j['error_code'] === 'disk_denied', "disk: $st " . json_encode($j));
        [$st, $j] = request($onAddr, 'POST', 'ai/test', token('ro', ['perms' => ['read']]), []);
        check($st === 403 && $j['error_code'] === 'permission_denied', "perm: $st " . json_encode($j));
    });

    test('gate: no provider configured -> 400 ai_not_configured', function () use ($offAddr) {
        [$st, $j] = request($offAddr, 'POST', 'ai/test', token('off'), []);
        check($st === 400 && $j['error_code'] === 'ai_not_configured', "$st " . json_encode($j));
    });

    test('rate limit: 3rd call within a minute -> 429 rate_limited', function () use ($onAddr) {
        scenario('ok');
        $t = token('limited');
        [$a] = request($onAddr, 'POST', 'ai/test', $t, []);
        [$b] = request($onAddr, 'POST', 'ai/test', $t, []);
        [$c, $j] = request($onAddr, 'POST', 'ai/test', $t, []);
        check($a === 200 && $b === 200 && $c === 429 && $j['error_code'] === 'rate_limited', "$a/$b/$c " . json_encode($j));
    });

    test('audit: ai_test entry with verdict detail, never the key', function () use ($onAddr, $apiKey) {
        scenario('badkey');
        $t = token('audited');
        request($onAddr, 'POST', 'ai/test', $t, []);
        [$st, $j, $raw] = request($onAddr, 'GET', 'audit?disk=local&action=ai_test&limit=20', $t);
        check($st === 200, "audit status $st");
        check(str_contains($raw, '"ai_test"'), 'no ai_test audit entry: ' . substr($raw, 0, 300));
        check(in_array('ai_auth claude/retired-model', array_column($j['data'], 'detail'), true), 'audit detail missing: ' . substr($raw, 0, 400));
        check(!str_contains($raw, $apiKey), 'key in audit log');
    });

    test('/ai-tag with a retired model -> 502 ai_model_not_found + default_model', function () use ($onAddr) {
        scenario('retired');
        $t = token('tagger');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        [$st, $j, $raw] = request($onAddr, 'POST', 'upload', $t, null, [
            'disk' => 'local', 'path' => '',
            'file' => new CURLStringFile($png, 'pixel.png', 'image/png'),
        ]);
        check($st === 200, "upload $st $raw");
        [$st, $j, $raw] = request($onAddr, 'POST', 'ai-tag', $t, ['disk' => 'local', 'path' => 'pixel.png']);
        check($st === 502 && $j['error_code'] === 'ai_model_not_found', "$st $raw");
        check(($j['error_params']['default_model'] ?? '') === 'claude-sonnet-5-5', 'default_model param: ' . $raw);
    });

    test('upload with auto-tag + bad key still 200, carries ai_error: ai_auth', function () use ($onAddr) {
        scenario('badkey');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        [$st, $j, $raw] = request($onAddr, 'POST', 'upload', token('uploader', ['ai_auto_tag' => true]), null, [
            'disk' => 'local', 'path' => '',
            'file' => new CURLStringFile($png, 'pixel2.png', 'image/png'),
        ]);
        check($st === 200 && ($j['data']['ai_error'] ?? null) === 'ai_auth', "$st $raw");
    });

    test('doctor ai block: present for write+configured, absent for read-only / unconfigured ', function () use ($onAddr, $offAddr, $fakeAddr) {
        [$st, $j, $raw] = request($onAddr, 'GET', 'disk/doctor?disk=local', token('doc'));
        check($st === 200 && ($j['data']['ai']['configured'] ?? null) === true && ($j['data']['ai']['model'] ?? '') === 'retired-model', "$st " . substr($raw, 0, 300));
        check(!str_contains($raw, 'http://' . $fakeAddr), 'base URL leaked');
        [$st, $j, $raw] = request($onAddr, 'GET', 'disk/doctor?disk=local', token('docro', ['perms' => ['read']]));
        check($st === 403, "read-only doctor $st");
        [$st, $j, $raw] = request($offAddr, 'GET', 'disk/doctor?disk=local', token('docoff'));
        check($st === 200 && !str_contains($raw, '"ai"'), 'ai block present without a provider: ' . substr($raw, 0, 200));
    });
} finally {
    foreach ($procs as $p) {
        proc_terminate($p);
        proc_close($p);
    }
    @unlink($scenarioFile);
    foreach (array_keys($prefixes) as $prefix) {
        $dir = $core . '/storage/uploads/' . $prefix;
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($dir);
        }
    }
}
echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
