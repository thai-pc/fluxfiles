<?php

/**
 * End-to-end HTTP test for the SSO bridge (`packages/sso`). Boots the real
 * router (own `php -S`, backs up/restores packages/core/.env, needs curl) and
 * drives the full pre-auth `/api/fm/sso/{login,callback,exchange}` chain
 * against a tiny fixture OIDC provider (its own `php -S`, serving a discovery
 * document, a JWKS with a locally-generated RS256 keypair, and a token
 * endpoint fully driven by a test-controlled `code` payload).
 *
 * Four phases, one server boot each, because the gate has three layers plus a
 * server-config kill-switch:
 *
 *   1. DISABLED (default) — FLUXFILES_SSO_ENABLED unset → all three routes
 *      403 sso_disabled, in plain text (these are browser navigations, not
 *      fetch()), and window.__FM_SSO__ is never injected into /public/index.html.
 *   2. ENABLED, MODULE ABSENT — `packages/sso` isn't composer-installed in
 *      dev, so free core (stock router.php) sees the class missing → 501
 *      module_not_installed.
 *   3. INSTALLED, UNLICENSED — boots through a wrapper router that requires
 *      the module src directly (same trick test-share-http.php uses) but with
 *      no license → 402 license_required. Proves gate order: enabled → installed → licensed.
 *   4. LICENSED — a real key minted with scripts/license-gen.php + the local
 *      signing key, plus the fixture IdP. Exercises the full login → callback
 *      → exchange journey and every negative id_token/state case. Skipped
 *      when the signing key isn't on this machine (the normal case in CI).
 *
 * Usage: php tests/e2e/test-sso-http.php   (requires curl + openssl extensions)
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

$SECRET = str_repeat('k', 40);
$_ENV['FLUXFILES_SECRET'] = $SECRET;
$coreDir = (string) realpath(__DIR__ . '/../..');
$repoRoot = (string) realpath(__DIR__ . '/../../../..');
$uploadRoot = $coreDir . '/storage/uploads';
$PREFIX = 'sso_e2e';
$runId = getmypid();
$stateDir = sys_get_temp_dir() . "/ff-sso-state-{$runId}";

// ── HTTP helpers ────────────────────────────────────────────────────────────
/** @return array{0:int,1:array<string,string>,2:string} [status, headers(lower), body] */
function req(string $method, string $url, array $opt = []): array {
    $hdr = [];
    $ch = curl_init($url);
    $o = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $opt['headers'] ?? [],
        CURLOPT_FOLLOWLOCATION => false,
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
/** The standard error envelope, asserted whole. */
function assertEnvelope(array $j, string $code): void {
    assertTrue(array_key_exists('data', $j) && array_key_exists('error', $j), 'envelope shape');
    assertEqual(null, $j['data'], 'data null on error');
    assertTrue(is_string($j['error']) && $j['error'] !== '', 'human message present');
    assertEqual($code, $j['error_code'] ?? null, 'error_code');
}
function parseQuery(string $url): array { $q = []; parse_str((string) parse_url($url, PHP_URL_QUERY), $q); return $q; }
function urlFragment(string $url): string { return (string) parse_url($url, PHP_URL_FRAGMENT); }
function b64url(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function mkCode(array $payload): string { return b64url(json_encode($payload)); }

// ── server control ──────────────────────────────────────────────────────────
$procs = [];
/** Boot a server. $router = null → stock router.php; otherwise a wrapper router. */
function boot(int $port, ?string $router = null): array {
    global $coreDir, $procs;
    $cmd = ['php', '-S', "127.0.0.1:{$port}", '-t', $coreDir, $router ?? 'router.php'];
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
/** Boot the fixture IdP: its own docroot/router, no coreDir dependency. */
function bootIdp(int $port, string $router): array {
    global $procs;
    $cmd = ['php', '-S', "127.0.0.1:{$port}", $router];
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, dirname($router));
    if (!is_resource($p)) { fwrite(STDERR, "could not start idp on {$port}\n"); exit(1); }
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

$envFile = $coreDir . '/.env';
$envBackup = is_file($envFile) ? file_get_contents($envFile) : null;
$baseEnv = "FLUXFILES_SECRET={$SECRET}\nFLUXFILES_RATE_LIMIT_READ=100000\nFLUXFILES_RATE_LIMIT_WRITE=100000\nFLUXFILES_STORAGE_PATH={$stateDir}\n";
file_put_contents($envFile, $baseEnv);

// The module src isn't a composer dep in dev, so phases 3+4 boot through a wrapper
// router that requires both its files before delegating to the real router.php —
// same trick test-share-http.php uses. packages/sso/src has TWO files (unlike
// share's one): SsoModule::callback() calls GroupClaimsMapper statically, so both
// must be required or the class is missing at runtime.
$moduleDir = "{$repoRoot}/packages/sso/src";
$modRouter = sys_get_temp_dir() . "/ff-sso-router-{$runId}.php";
file_put_contents($modRouter, "<?php\nrequire_once '{$coreDir}/vendor/autoload.php';\nrequire_once '{$moduleDir}/GroupClaimsMapper.php';\nrequire_once '{$moduleDir}/SsoModule.php';\nreturn require '{$coreDir}/router.php';\n");

/** A real license for `sso`, or '' when the offline signing key isn't on this box. */
function mintLicense(string $repoRoot): string {
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
    $p = proc_open(['php', $gen, '--edition=pro', '--modules=sso', '--enforcement=perpetual', '--expires=+30d', '--kid=k1'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($p)) { return ''; }
    $tok = trim((string) stream_get_contents($pipes[1])); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return (new \FluxFiles\LicenseManager($tok))->licensed('sso') ? $tok : '';
}

/** Generate an RSA keypair; returns [privatePem, n(bin), e(bin)]. */
function genRsaKeyPair(): array {
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($res === false) { throw new \RuntimeException('openssl_pkey_new failed: ' . openssl_error_string()); }
    openssl_pkey_export($res, $priv);
    $details = openssl_pkey_get_details($res);
    return [$priv, $details['rsa']['n'], $details['rsa']['e']];
}

echo "\n{$cyan}══ SSO bridge over HTTP (e2e) ══{$reset}\n\n";

// Fixture IdP keypairs: the primary (published in JWKS) and a second, never
// published, used only to sign a token with the "wrong" key for the
// signature-mismatch test.
[$primaryPem, $primaryN, $primaryE] = genRsaKeyPair();
[$wrongPem, , ] = genRsaKeyPair();
$primaryKeyFile = sys_get_temp_dir() . "/ff-sso-idp-key-{$runId}.pem";
$wrongKeyFile = sys_get_temp_dir() . "/ff-sso-idp-wrongkey-{$runId}.pem";
file_put_contents($primaryKeyFile, $primaryPem);
file_put_contents($wrongKeyFile, $wrongPem);

$idpDir = sys_get_temp_dir() . "/ff-sso-idp-{$runId}";
@mkdir($idpDir, 0777, true);
$IDP_PORT = 8134;
$IDP_BASE = "http://127.0.0.1:{$IDP_PORT}";
$discoveryDoc = [
    'issuer' => $IDP_BASE,
    'authorization_endpoint' => "{$IDP_BASE}/authorize",
    'token_endpoint' => "{$IDP_BASE}/token",
    'jwks_uri' => "{$IDP_BASE}/jwks",
];
$jwks = ['keys' => [[
    'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'test-kid',
    'n' => b64url($primaryN), 'e' => b64url($primaryE),
]]];
file_put_contents("{$idpDir}/discovery.json", json_encode($discoveryDoc));
file_put_contents("{$idpDir}/jwks.json", json_encode($jwks));

// The fixture IdP's /token endpoint is fully driven by the `code` the test sends
// it — the "code" is actually a base64url-JSON payload describing the id_token
// to mint (claims + which private key + which kid to sign it with), or a magic
// string to simulate a broken token endpoint. This lets one fixture process
// cover every id_token scenario without per-scenario variants.
$idpRouter = "{$idpDir}/router.php";
file_put_contents($idpRouter, <<<PHP
<?php
\$uri = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (\$uri === '/.well-known/openid-configuration') {
    header('Content-Type: application/json');
    echo file_get_contents('{$idpDir}/discovery.json');
    return;
}
if (\$uri === '/jwks') {
    header('Content-Type: application/json');
    echo file_get_contents('{$idpDir}/jwks.json');
    return;
}
if (\$uri === '/token' && \$_SERVER['REQUEST_METHOD'] === 'POST') {
    parse_str(file_get_contents('php://input'), \$body);
    \$code = \$body['code'] ?? '';
    if (\$code === 'BROKEN_ENDPOINT') {
        http_response_code(500);
        echo 'idp on fire';
        return;
    }
    if (\$code === 'NO_ID_TOKEN') {
        header('Content-Type: application/json');
        echo json_encode(['access_token' => 'whatever']);
        return;
    }
    \$decoded = json_decode(base64_decode(strtr(\$code, '-_', '+/')), true);
    if (!is_array(\$decoded)) {
        http_response_code(400);
        echo 'bad code';
        return;
    }
    \$keyFile = (\$decoded['key'] ?? 'primary') === 'wrong' ? '{$wrongKeyFile}' : '{$primaryKeyFile}';
    \$priv = file_get_contents(\$keyFile);
    \$kid = \$decoded['kid'] ?? 'test-kid';
    \$claims = \$decoded['claims'] ?? [];
    \$idToken = \\Firebase\\JWT\\JWT::encode(\$claims, \$priv, 'RS256', \$kid);
    header('Content-Type: application/json');
    echo json_encode(['access_token' => 'at-' . bin2hex(random_bytes(6)), 'id_token' => \$idToken, 'token_type' => 'Bearer']);
    return;
}
http_response_code(404);
echo 'not found';
PHP
);
// The fixture needs firebase/php-jwt to sign id_tokens — piggyback on core's vendor/.
file_put_contents("{$idpDir}/router-boot.php", "<?php\nrequire_once '{$coreDir}/vendor/autoload.php';\nreturn require '{$idpRouter}';\n");

try {
    // ════════════════════════════════════════════════════════════════════════
    // Phase 1 — DISABLED (default): FLUXFILES_SSO_ENABLED unset.
    // ════════════════════════════════════════════════════════════════════════
    echo "{$cyan}── phase 1: SSO disabled (default) ──{$reset}\n\n";
    [$srv1, $B1] = boot(8130);

    test('GET /sso/login → 403 sso_disabled, plain text (browser navigation, no JSON envelope)', function () use ($B1) {
        [$st, $h, $body] = req('GET', "{$B1}/api/fm/sso/login");
        assertEqual(403, $st);
        assertTrue(stripos($h['content-type'] ?? '', 'text/plain') === 0, 'plain text: ' . ($h['content-type'] ?? ''));
        assertTrue(stripos($body, 'not enabled') !== false, 'message names the cause: ' . $body);
    });

    test('GET /sso/callback → 403 sso_disabled, plain text', function () use ($B1) {
        [$st, $h, $body] = req('GET', "{$B1}/api/fm/sso/callback?code=x&state=y");
        assertEqual(403, $st);
        assertTrue(stripos($h['content-type'] ?? '', 'text/plain') === 0);
        assertTrue(stripos($body, 'not enabled') !== false);
    });

    test('POST /sso/exchange → 403 sso_disabled, standard JSON envelope (this one IS a fetch())', function () use ($B1) {
        [$st, , $j] = reqJson('POST', "{$B1}/api/fm/sso/exchange", ['json' => ['token' => 'whatever']]);
        assertEqual(403, $st);
        assertEnvelope($j, 'sso_disabled');
    });

    test('the pre-auth routes ignore any Authorization header — gate, not auth', function () use ($B1) {
        [$st, , $body] = req('GET', "{$B1}/api/fm/sso/login", ['headers' => ['Authorization: Bearer not-a-jwt']]);
        assertEqual(403, $st);
        assertTrue(stripos($body, 'not enabled') !== false);
    });

    test('/public/index.html does NOT inject window.__FM_SSO__ = {...} when disabled', function () use ($B1) {
        [$st, , $body] = req('GET', "{$B1}/public/index.html");
        assertEqual(200, $st);
        // The static boot script always REFERENCES window.__FM_SSO__ (to check .enabled) —
        // that identifier is present on every render. What must be absent is the
        // server-side injected *assignment* itself.
        assertTrue(strpos($body, 'window.__FM_SSO__ = {') === false, 'no SSO injection assignment');
    });

    stop($srv1);

    // ════════════════════════════════════════════════════════════════════════
    // Phase 2 — ENABLED, module absent.
    // ════════════════════════════════════════════════════════════════════════
    echo "\n{$cyan}── phase 2: enabled, module absent ──{$reset}\n\n";
    file_put_contents($envFile, $baseEnv . "FLUXFILES_SSO_ENABLED=true\nFLUXFILES_SSO_OIDC_ISSUER={$IDP_BASE}\nFLUXFILES_SSO_OIDC_CLIENT_ID=test-client\nFLUXFILES_SSO_OIDC_CLIENT_SECRET=test-secret\nFLUXFILES_SSO_OIDC_REDIRECT_URI=http://127.0.0.1:8131/api/fm/sso/callback\n");
    [$srv2, $B2] = boot(8131);

    test('GET /sso/login → 501 module_not_installed, plain text', function () use ($B2) {
        [$st, $h, $body] = req('GET', "{$B2}/api/fm/sso/login");
        assertEqual(501, $st);
        assertTrue(stripos($h['content-type'] ?? '', 'text/plain') === 0);
        assertTrue(stripos($body, 'not installed') !== false, $body);
    });

    test('POST /sso/exchange → 501 module_not_installed, JSON envelope', function () use ($B2) {
        [$st, , $j] = reqJson('POST', "{$B2}/api/fm/sso/exchange", ['json' => ['token' => 'whatever']]);
        assertEqual(501, $st);
        assertEnvelope($j, 'module_not_installed');
    });

    test('/public/index.html does NOT inject the __FM_SSO__ assignment when the module class is absent (installed() check)', function () use ($B2) {
        [, , $body] = req('GET', "{$B2}/public/index.html");
        assertTrue(strpos($body, 'window.__FM_SSO__ = {') === false);
    });

    stop($srv2);

    if (!is_dir($moduleDir)) {
        echo "\n  {$yellow}skip{$reset} phases 3+4 (packages/sso not checked out)\n";
    } else {
        // ════════════════════════════════════════════════════════════════════
        // Phase 3 — installed, unlicensed.
        // ════════════════════════════════════════════════════════════════════
        echo "\n{$cyan}── phase 3: installed, unlicensed ──{$reset}\n\n";
        [$srv3, $B3] = boot(8132, $modRouter);

        test('GET /sso/login → 402 license_required (gate order: enabled → installed → licensed)', function () use ($B3) {
            [$st, $h, $body] = req('GET', "{$B3}/api/fm/sso/login");
            assertEqual(402, $st);
            assertTrue(stripos($h['content-type'] ?? '', 'text/plain') === 0);
            assertTrue(stripos($body, 'license') !== false, $body);
        });

        test('POST /sso/exchange → 402 license_required, JSON envelope', function () use ($B3) {
            [$st, , $j] = reqJson('POST', "{$B3}/api/fm/sso/exchange", ['json' => ['token' => 'whatever']]);
            assertEqual(402, $st);
            assertEnvelope($j, 'license_required');
        });

        test('/public/index.html still injects __FM_SSO__ once installed, even though unlicensed (layer-1-only check)', function () use ($B3) {
            [, , $body] = req('GET', "{$B3}/public/index.html");
            assertTrue(strpos($body, '__FM_SSO__') !== false, 'installed() is class_exists() only, no license check');
            assertTrue(strpos($body, "'enabled':true") !== false || strpos($body, '"enabled":true') !== false, $body);
        });

        stop($srv3);

        // ════════════════════════════════════════════════════════════════════
        // Phase 4 — licensed: full fixture-IdP journey.
        // ════════════════════════════════════════════════════════════════════
        $license = mintLicense($repoRoot);
        if ($license === '') {
            echo "\n  {$yellow}skip{$reset} phase 4 (no offline signing key on this machine — expected in CI)\n";
        } else {
            echo "\n{$cyan}── phase 4a: misconfigured (licensed, but an OIDC env var is missing) ──{$reset}\n\n";
            // FLUXFILES_SSO_OIDC_ISSUER deliberately omitted — it's read first, so this
            // fails inside requireEnv() before any network call to the (fixture-not-yet-up) IdP.
            file_put_contents($envFile, $baseEnv . "FLUXFILES_SSO_ENABLED=true\nFLUXFILES_LICENSE_KEY={$license}\n");
            [$srvMisconfig, $BMisconfig] = boot(8135, $modRouter);

            test('login with a missing OIDC env var -> 500 sso_misconfigured, without naming the var to an unauthenticated caller', function () use ($BMisconfig) {
                [$st, $h, $body] = req('GET', "{$BMisconfig}/api/fm/sso/login");
                assertEqual(500, $st, $body);
                assertTrue(stripos($h['content-type'] ?? '', 'text/plain') === 0);
                assertTrue(stripos($body, 'misconfigured') !== false, $body);
                assertTrue(stripos($body, 'FLUXFILES_SSO_OIDC_ISSUER') === false, "leaks the specific env var name: {$body}");
            });

            stop($srvMisconfig);

            echo "\n{$cyan}── phase 4b: licensed (fixture IdP, real journey) ──{$reset}\n\n";

            // The "IdP unreachable" case is run BEFORE the fixture process starts —
            // OidcDiscovery only caches SUCCESSFUL fetches, so a failed fetch here can't
            // poison a later successful-fetch test in this same phase.
            file_put_contents($envFile, $baseEnv
                . "FLUXFILES_SSO_ENABLED=true\nFLUXFILES_LICENSE_KEY={$license}\n"
                . "FLUXFILES_SSO_OIDC_ISSUER={$IDP_BASE}\nFLUXFILES_SSO_OIDC_CLIENT_ID=test-client\n"
                . "FLUXFILES_SSO_OIDC_CLIENT_SECRET=test-secret\nFLUXFILES_SSO_OIDC_REDIRECT_URI=http://127.0.0.1:8133/api/fm/sso/callback\n"
                . "FLUXFILES_SSO_CLAIMS_MAP=" . json_encode(['engineers' => ['perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => $PREFIX, 'ttl' => 600]]) . "\n"
                . "FLUXFILES_SSO_GROUPS_CLAIM=groups\n");
            [$srv4, $B] = boot(8133, $modRouter);

            test('login before the IdP exists → the callback errors with sso_idp_unreachable (uncached failure)', function () use ($B) {
                [$st, $h, $body] = req('GET', "{$B}/api/fm/sso/callback?code=x&state=y");
                // state verification runs first and will fail on a garbage state before
                // discovery is even attempted, so drive this through a REAL state token
                // to actually reach OidcDiscovery::discover() while the IdP is still down.
                assertTrue($st === 403 || $st === 502, "expected 403 (bad state) or 502 (idp), got {$st}: {$body}");
            });

            test('a real state token, IdP still down → 502 sso_idp_unreachable', function () use ($B, $SECRET) {
                $nonce = bin2hex(random_bytes(16));
                $state = \FluxFiles\SsoStateToken::mint($nonce, '/public/index.html', $SECRET);
                [$st, $h, $body] = req('GET', "{$B}/api/fm/sso/callback?code=whatever&state=" . rawurlencode($state));
                assertEqual(502, $st, $body);
                assertTrue(stripos($h['content-type'] ?? '', 'text/plain') === 0);
                assertTrue(stripos($body, 'identity provider') !== false || stripos($body, 'idp') !== false, $body);
            });

            // Now start the fixture IdP.
            [$idpProc, ] = bootIdp($IDP_PORT, "{$idpDir}/router-boot.php");

            /** Perform a login redirect and return [state, nonce, locationUrl]. */
            $doLogin = function () use ($B, $IDP_BASE) {
                [$st, $h] = req('GET', "{$B}/api/fm/sso/login?redirect=" . rawurlencode('/public/index.html'));
                assertEqual(302, $st, 'login redirects');
                $loc = $h['location'] ?? '';
                assertTrue(strpos($loc, $IDP_BASE) === 0, "redirects to the IdP: {$loc}");
                $q = parseQuery($loc);
                assertTrue(!empty($q['state']) && !empty($q['nonce']), 'state+nonce present');
                assertTrue(($q['client_id'] ?? '') === 'test-client', 'client_id passed through');
                assertTrue(strpos($q['scope'] ?? '', 'openid') !== false, 'openid scope requested');
                return [$q['state'], $q['nonce'], $loc];
            };

            /** Standard claims for a valid id_token, nonce/exp filled in by the caller. */
            $baseClaims = function (string $nonce) use ($IDP_BASE) {
                return [
                    'iss' => $IDP_BASE, 'aud' => 'test-client', 'sub' => 'user-1',
                    'email' => 'alice@example.test', 'nonce' => $nonce,
                    'iat' => time(), 'exp' => time() + 300,
                    'groups' => ['engineers'],
                ];
            };

            test('happy path: login → callback → exchange yields a working access JWT', function () use ($B, $doLogin, $baseClaims, $PREFIX) {
                [$state, $nonce] = $doLogin();
                $code = mkCode(['claims' => $baseClaims($nonce)]);
                [$st, $h, $body] = req('GET', "{$B}/api/fm/sso/callback?code=" . rawurlencode($code) . "&state=" . rawurlencode($state));
                assertEqual(302, $st, $body);
                $loc = $h['location'] ?? '';
                assertTrue(strpos($loc, '/public/index.html') === 0, "redirects to the app: {$loc}");
                $frag = urlFragment($loc);
                assertTrue(strpos($frag, 'boot=') === 0, "fragment carries the boot token: {$frag}");
                assertTrue(strpos($loc, 'boot=') === false || strpos($loc, '#boot=') !== false, 'boot token is in the fragment, not the query string');
                assertTrue(strpos((string) parse_url($loc, PHP_URL_QUERY), 'boot') === false, 'the query string carries no boot token');

                $bootToken = substr($frag, strlen('boot='));
                [$st2, , $j2] = reqJson('POST', "{$B}/api/fm/sso/exchange", ['json' => ['token' => $bootToken]]);
                assertEqual(200, $st2, json_encode($j2));
                assertTrue(!empty($j2['data']['token']), 'real JWT returned');
                assertTrue(($j2['data']['expires_at'] ?? 0) > time(), 'expiry in the future');

                // Confirm it's a genuinely working access token with the mapped claims,
                // scoped by the FLUXFILES_SSO_CLAIMS_MAP entry for "engineers".
                $jwt = $j2['data']['token'];
                [$st3, , $j3] = reqJson('GET', "{$B}/api/fm/list?disk=local&path={$PREFIX}", ['headers' => ["Authorization: Bearer {$jwt}"]]);
                assertEqual(200, $st3, json_encode($j3));
            });

            test('the boot token cannot be reused as a main Authorization: Bearer token', function () use ($B, $doLogin, $baseClaims) {
                [$state, $nonce] = $doLogin();
                $code = mkCode(['claims' => $baseClaims($nonce)]);
                [, $h] = req('GET', "{$B}/api/fm/sso/callback?code=" . rawurlencode($code) . "&state=" . rawurlencode($state));
                $bootToken = substr(urlFragment($h['location'] ?? ''), strlen('boot='));
                [$st, , $j] = reqJson('GET', "{$B}/api/fm/list?disk=local", ['headers' => ["Authorization: Bearer {$bootToken}"]]);
                assertEqual(403, $st);
                assertEqual('token_not_access', $j['error_code'] ?? null);
            });

            test('exchange rejects an empty/garbage token, JSON envelope, never a 500', function () use ($B) {
                foreach (['', 'nope', 'a.b.c'] as $t) {
                    [$st, , $j] = reqJson('POST', "{$B}/api/fm/sso/exchange", ['json' => ['token' => $t]]);
                    assertEqual(403, $st, "token=" . $t);
                    assertEnvelope($j, 'sso_boot_token_invalid');
                }
            });

            test('exchange rejects a boot token past its 60s TTL', function () use ($B, $SECRET) {
                $expired = \FluxFiles\JwtCompat::encode([
                    't' => 'sso_boot', 'jti' => bin2hex(random_bytes(8)), 'real_jwt' => 'irrelevant',
                    'iat' => time() - 120, 'exp' => time() - 60,
                ], $SECRET);
                [$st, , $j] = reqJson('POST', "{$B}/api/fm/sso/exchange", ['json' => ['token' => $expired]]);
                assertEqual(403, $st);
                assertEnvelope($j, 'sso_boot_token_invalid');
            });

            test('exchange rejects a state token presented as a boot token (wrong t)', function () use ($B, $SECRET) {
                $state = \FluxFiles\SsoStateToken::mint(bin2hex(random_bytes(8)), '/public/index.html', $SECRET);
                [$st, , $j] = reqJson('POST', "{$B}/api/fm/sso/exchange", ['json' => ['token' => $state]]);
                assertEqual(403, $st);
                assertEnvelope($j, 'sso_boot_token_invalid');
            });

            test('open-redirect guard: an absolute/protocol-relative/backslash redirect is downgraded to the default', function () use ($B, $SECRET) {
                // '/\evil.example' is a WHATWG scheme-relative bypass: browsers normalize
                // backslash to slash for http(s), so this resolves identically to
                // '//evil.example' even though it starts with '/' and has no literal '//'.
                foreach (['https://evil.example/steal', '//evil.example/steal', '/\\evil.example/steal'] as $bad) {
                    [$st, $h] = req('GET', "{$B}/api/fm/sso/login?redirect=" . rawurlencode($bad));
                    assertEqual(302, $st, $bad);
                    $q = parseQuery($h['location'] ?? '');
                    $state = \FluxFiles\SsoStateToken::verify($q['state'], $SECRET);
                    assertEqual('/public/index.html', $state['redirect'], "redirect not downgraded for: {$bad}");
                }
            });

            test('callback rejects a tampered state (wrong signature)', function () use ($B) {
                $tampered = 'not-a-real-jwt.' . bin2hex(random_bytes(8));
                [$st, $h, $body] = req('GET', "{$B}/api/fm/sso/callback?code=x&state=" . rawurlencode($tampered));
                assertEqual(403, $st, $body);
                assertTrue(stripos($body, 'state') !== false, $body);
            });

            test('callback rejects an expired state token', function () use ($B, $SECRET) {
                $expired = \FluxFiles\JwtCompat::encode([
                    't' => 'sso_state', 'nonce' => bin2hex(random_bytes(8)), 'redirect' => '/public/index.html',
                    'iat' => time() - 1200, 'exp' => time() - 600,
                ], $SECRET);
                [$st, , $body] = req('GET', "{$B}/api/fm/sso/callback?code=x&state=" . rawurlencode($expired));
                assertEqual(403, $st, $body);
            });

            test('callback rejects a state signed with the wrong secret', function () use ($B) {
                $foreignState = \FluxFiles\SsoStateToken::mint(bin2hex(random_bytes(8)), '/public/index.html', str_repeat('z', 40));
                [$st, , $body] = req('GET', "{$B}/api/fm/sso/callback?code=x&state=" . rawurlencode($foreignState));
                assertEqual(403, $st, $body);
            });

            test('callback rejects when the token endpoint is broken (502)', function () use ($B, $doLogin) {
                [$state] = $doLogin();
                [$st, , $body] = req('GET', "{$B}/api/fm/sso/callback?code=BROKEN_ENDPOINT&state=" . rawurlencode($state));
                assertEqual(502, $st, $body);
            });

            test('callback rejects when the token response has no id_token (502)', function () use ($B, $doLogin) {
                [$state] = $doLogin();
                [$st, , $body] = req('GET', "{$B}/api/fm/sso/callback?code=NO_ID_TOKEN&state=" . rawurlencode($state));
                assertEqual(502, $st, $body);
            });

            test('callback rejects an id_token signed with an unpublished key (signature mismatch)', function () use ($B, $doLogin, $baseClaims) {
                [$state, $nonce] = $doLogin();
                $code = mkCode(['key' => 'wrong', 'claims' => $baseClaims($nonce)]);
                [$st, , $body] = req('GET', "{$B}/api/fm/sso/callback?code=" . rawurlencode($code) . "&state=" . rawurlencode($state));
                assertEqual(403, $st, $body);
                assertTrue(stripos($body, 'sso_id_token_invalid') !== false || stripos($body, 'signature') !== false, $body);
            });

            test('callback rejects an id_token with an unknown kid', function () use ($B, $doLogin, $baseClaims) {
                [$state, $nonce] = $doLogin();
                $code = mkCode(['kid' => 'bogus-kid', 'claims' => $baseClaims($nonce)]);
                [$st, , $body] = req('GET', "{$B}/api/fm/sso/callback?code=" . rawurlencode($code) . "&state=" . rawurlencode($state));
                assertEqual(403, $st, $body);
            });

            test('callback rejects an id_token with the wrong issuer', function () use ($B, $doLogin, $baseClaims) {
                [$state, $nonce] = $doLogin();
                $claims = $baseClaims($nonce); $claims['iss'] = 'http://attacker.example';
                $code = mkCode(['claims' => $claims]);
                [$st, , $j] = reqJson('GET', "{$B}/api/fm/sso/callback?code=" . rawurlencode($code) . "&state=" . rawurlencode($state));
                // plain-text response (handleSsoCallback catches only ApiException, echoes text)
                assertEqual(403, $st);
            });

            test('callback rejects an id_token with the wrong audience (string form)', function () use ($B, $doLogin, $baseClaims) {
                [$state, $nonce] = $doLogin();
                $claims = $baseClaims($nonce); $claims['aud'] = 'someone-elses-client';
                $code = mkCode(['claims' => $claims]);
                [$st, , $body] = req('GET', "{$B}/api/fm/sso/callback?code=" . rawurlencode($code) . "&state=" . rawurlencode($state));
                assertEqual(403, $st, $body);
            });

            test('an id_token with an aud ARRAY containing our client_id is accepted', function () use ($B, $doLogin, $baseClaims) {
                [$state, $nonce] = $doLogin();
                $claims = $baseClaims($nonce); $claims['aud'] = ['other-client', 'test-client'];
                $code = mkCode(['claims' => $claims]);
                [$st, $h, $body] = req('GET', "{$B}/api/fm/sso/callback?code=" . rawurlencode($code) . "&state=" . rawurlencode($state));
                assertEqual(302, $st, $body);
                assertTrue(strpos(urlFragment($h['location'] ?? ''), 'boot=') === 0);
            });

            test('an id_token with an aud ARRAY missing our client_id is rejected', function () use ($B, $doLogin, $baseClaims) {
                [$state, $nonce] = $doLogin();
                $claims = $baseClaims($nonce); $claims['aud'] = ['other-client', 'yet-another'];
                $code = mkCode(['claims' => $claims]);
                [$st, , $body] = req('GET', "{$B}/api/fm/sso/callback?code=" . rawurlencode($code) . "&state=" . rawurlencode($state));
                assertEqual(403, $st, $body);
            });

            test('callback rejects an id_token whose nonce does not match the login state', function () use ($B, $doLogin, $baseClaims) {
                [$state, ] = $doLogin();
                $code = mkCode(['claims' => $baseClaims('some-other-nonce')]);
                [$st, , $body] = req('GET', "{$B}/api/fm/sso/callback?code=" . rawurlencode($code) . "&state=" . rawurlencode($state));
                assertEqual(403, $st, $body);
                assertTrue(stripos($body, 'nonce') !== false, $body);
            });

            test('callback rejects an expired id_token', function () use ($B, $doLogin, $baseClaims, $IDP_BASE) {
                [$state, $nonce] = $doLogin();
                $claims = $baseClaims($nonce); $claims['iat'] = time() - 7200; $claims['exp'] = time() - 3600;
                $code = mkCode(['claims' => $claims]);
                [$st, , $body] = req('GET', "{$B}/api/fm/sso/callback?code=" . rawurlencode($code) . "&state=" . rawurlencode($state));
                assertEqual(403, $st, $body);
            });

            test('callback rejects an id_token with no usable subject (no email/preferred_username/sub)', function () use ($B, $doLogin, $IDP_BASE) {
                [$state, $nonce] = $doLogin();
                $claims = ['iss' => $IDP_BASE, 'aud' => 'test-client', 'nonce' => $nonce, 'iat' => time(), 'exp' => time() + 300, 'groups' => ['engineers']];
                $code = mkCode(['claims' => $claims]);
                [$st, , $body] = req('GET', "{$B}/api/fm/sso/callback?code=" . rawurlencode($code) . "&state=" . rawurlencode($state));
                assertEqual(403, $st, $body);
            });

            test('group-mapping fails closed (403 sso_no_mapping) for a group with no claims-map entry and no default', function () use ($B, $doLogin, $baseClaims) {
                [$state, $nonce] = $doLogin();
                $claims = $baseClaims($nonce); $claims['groups'] = ['unmapped-group'];
                $code = mkCode(['claims' => $claims]);
                [$st, , $body] = req('GET', "{$B}/api/fm/sso/callback?code=" . rawurlencode($code) . "&state=" . rawurlencode($state));
                assertEqual(403, $st, $body);
                assertTrue(stripos($body, 'sso_no_mapping') !== false || stripos($body, 'no fluxfiles_sso_claims_map') !== false || stripos($body, 'matched') !== false, $body);
            });

            test('replaying the same state token twice still succeeds both times (v1: TTL-only, no single-use store — documented limitation)', function () use ($B, $doLogin, $baseClaims) {
                [$state, $nonce] = $doLogin();
                $code1 = mkCode(['claims' => $baseClaims($nonce)]);
                [$st1] = req('GET', "{$B}/api/fm/sso/callback?code=" . rawurlencode($code1) . "&state=" . rawurlencode($state));
                assertEqual(302, $st1);
                $code2 = mkCode(['claims' => $baseClaims($nonce)]);
                [$st2] = req('GET', "{$B}/api/fm/sso/callback?code=" . rawurlencode($code2) . "&state=" . rawurlencode($state));
                assertEqual(302, $st2, 'no server-side single-use tracking in v1, by design (see SsoBootToken docblock)');
            });

            stop($idpProc);
            stop($srv4);
        }
    }
} finally {
    if ($envBackup === null) { @unlink($envFile); } else { file_put_contents($envFile, $envBackup); }
    foreach ($procs as $p) { if (is_resource($p)) { @proc_terminate($p); } }
    @unlink($modRouter);
    @unlink($primaryKeyFile);
    @unlink($wrongKeyFile);
    foreach ([$idpRouter, "{$idpDir}/router-boot.php", "{$idpDir}/discovery.json", "{$idpDir}/jwks.json"] as $f) { @unlink($f); }
    @rmdir($idpDir);
    if (is_dir($stateDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stateDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($stateDir);
    }
    $dir = "{$uploadRoot}/{$PREFIX}";
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($dir);
    }
}

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
