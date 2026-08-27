<?php

/**
 * End-to-end HTTP test for the public Intake (upload portal) routes. Boots the real
 * router (own `php -S`, backs up/restores packages/core/.env, needs curl) and drives
 * /api/fm/intake/{info,upload} plus the operator create/list/revoke/analytics routes
 * over the wire.
 *
 * Same three-phase structure as tests/e2e/test-share-http.php (the gate is identical:
 * 501 not-installed → 402 unlicensed → the real journey), because Intake is Share's
 * inbound twin and shares the same 3-layer ModuleRegistry gate:
 *
 *   1. FREE CORE (always runs, incl. CI) — `packages/intake` is not composer-installed,
 *      so the module class is absent → every public + operator route must 501 in the
 *      standard envelope, with NO Authorization header needed on the public ones.
 *   2. MODULE INSTALLED, UNLICENSED — boots through a wrapper router that requires the
 *      module src directly (same trick the module's own test uses) but gets no license
 *      → 402. Skipped when packages/intake isn't checked out.
 *   3. LICENSED — a real key minted with scripts/license-gen.php + the local signing
 *      key. Covers the real portal journey PLUS this feature's two additions: portal
 *      branding (intake_brand_*) echoed on /intake/info, and per-event analytics
 *      (intake_analytics) read back through GET /api/fm/intake/analytics. Skipped when
 *      the signing key isn't on this machine, which is the normal case in CI.
 *
 * Usage: php tests/e2e/test-intake-http.php   (requires the curl extension)
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
$PREFIX = 'intake_e2e';

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
/** The standard error envelope, asserted whole. */
function assertEnvelope(array $j, string $code): void {
    assertTrue(array_key_exists('data', $j) && array_key_exists('error', $j), 'envelope shape');
    assertEqual(null, $j['data'], 'data null on error');
    assertTrue(is_string($j['error']) && $j['error'] !== '', 'human message present');
    assertEqual($code, $j['error_code'] ?? null, 'error_code');
}

// ── server control ──────────────────────────────────────────────────────────
$procs = [];
/**
 * Boot a server. $router = null → the stock router.php (free core, module absent);
 * otherwise a wrapper router that pre-loads the module package.
 */
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
function stop($p): void {
    global $procs;
    $procs = array_values(array_filter($procs, static fn ($x) => $x !== $p));
    @proc_terminate($p);
    @proc_close($p);
}

// ── fixtures on the local disk ──────────────────────────────────────────────
@mkdir("{$uploadRoot}/{$PREFIX}/drop", 0777, true);

$envFile = $coreDir . '/.env';
$envBackup = is_file($envFile) ? file_get_contents($envFile) : null;
$baseEnv = "FLUXFILES_SECRET={$SECRET}\nFLUXFILES_RATE_LIMIT_READ=100000\nFLUXFILES_RATE_LIMIT_WRITE=100000\nFLUXFILES_INTAKE_UPLOAD_LIMIT=100000\nFLUXFILES_INTAKE_UPLOAD_TOTAL=100000\nFLUXFILES_INTAKE_RATE_LIMIT=100000\n";
file_put_contents($envFile, $baseEnv);

// The module src isn't a composer dep in dev, so phases 2+3 boot through a wrapper
// router that requires it before delegating to the real router.php — the same
// "require the module src directly" trick the module's own test uses. (The built-in
// server ignores auto_prepend_file for its router script, hence the wrapper.)
$moduleSrc = "{$repoRoot}/packages/intake/src/IntakeModule.php";
$modRouter = sys_get_temp_dir() . '/ff-intake-router-' . getmypid() . '.php';
file_put_contents($modRouter, "<?php\nrequire_once '{$coreDir}/vendor/autoload.php';\nrequire_once '{$moduleSrc}';\nreturn require '{$coreDir}/router.php';\n");

/** A real license for `intake`, or '' when the offline signing key isn't on this box. */
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
    $p = proc_open(['php', $gen, '--edition=pro', '--modules=intake', '--enforcement=perpetual', '--expires=+30d', '--kid=k1'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($p)) { return ''; }
    $tok = trim((string) stream_get_contents($pipes[1])); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return (new \FluxFiles\LicenseManager($tok))->licensed('intake') ? $tok : '';
}

echo "\n{$cyan}══ Intake public portal over HTTP (e2e) ══{$reset}\n\n";

try {
    // ════════════════════════════════════════════════════════════════════════
    // Phase 1 — FREE CORE: the module isn't installed.
    // ════════════════════════════════════════════════════════════════════════
    echo "{$cyan}── phase 1: free core (module absent) ──{$reset}\n\n";
    [$srv1, $B1] = boot(8113);

    // A syntactically real portal token, so nothing can pass merely for being junk.
    $fakePortal = \FluxFiles\JwtCompat::encode([
        't' => 'intake', 'sub' => 'intake:u1', 'iat' => time(), 'exp' => time() + 600, 'jti' => bin2hex(random_bytes(12)),
        'perms' => ['write'], 'disks' => ['local'], 'prefix' => "{$PREFIX}/drop",
        'intake' => true, 'store' => "{$PREFIX}/",
    ], $SECRET);

    test('GET /intake/info → 501 module_not_installed, standard envelope', function () use ($B1, $fakePortal) {
        [$st, $h, $j] = reqJson('GET', "{$B1}/api/fm/intake/info?token=" . rawurlencode($fakePortal));
        assertEqual(501, $st);
        assertEnvelope($j, 'module_not_installed');
        assertEqual('intake', $j['error_params']['module'] ?? null, 'names the module');
        assertTrue(stripos($h['content-type'] ?? '', 'application/json') === 0, 'json content-type');
    });

    test('POST /intake/upload → 501 (same envelope), never touches the disk', function () use ($B1, $fakePortal) {
        [$st, , $j] = reqJson('POST', "{$B1}/api/fm/intake/upload", ['json' => ['token' => $fakePortal]]);
        assertEqual(501, $st);
        assertEnvelope($j, 'module_not_installed');
    });

    test('the public routes are truly pre-auth: a garbage Bearer changes nothing', function () use ($B1, $fakePortal) {
        foreach (['Authorization: Bearer not-a-jwt', 'Authorization: Bearer ' . str_repeat('a.', 3)] as $auth) {
            [$st, , $j] = reqJson('GET', "{$B1}/api/fm/intake/info?token=" . rawurlencode($fakePortal), ['headers' => [$auth]]);
            assertEqual(501, $st, 'gate, not auth');
            assertEnvelope($j, 'module_not_installed');
        }
    });

    test('REPLAY: an intake portal token is refused on the MAIN API (403 token_not_access)', function () use ($B1, $fakePortal) {
        // A portal token is write-scoped and signed with FLUXFILES_SECRET — without
        // the guard it would be a working access JWT past cap/expiry/revocation.
        $h = ['headers' => ["Authorization: Bearer {$fakePortal}"]];
        foreach ([
            ['GET', '/api/fm/list?disk=local'],
            ['POST', '/api/fm/mkdir'],
        ] as [$m, $u]) {
            [$st, , $j] = reqJson($m, "{$B1}{$u}", $h);
            assertEqual(403, $st, "{$m} {$u}");
            assertEqual('token_not_access', $j['error_code'] ?? null, "{$m} {$u}");
        }
    });

    test('an ordinary access token is unaffected by the guard', function () use ($B1, $PREFIX) {
        $ok = fluxfiles_token(['user' => 'op', 'perms' => ['read'], 'disks' => ['local'], 'prefix' => $PREFIX, 'ttl' => 600]);
        [$st] = reqJson('GET', "{$B1}/api/fm/list?disk=local", ['headers' => ["Authorization: Bearer {$ok}"]]);
        assertEqual(200, $st, 'the guard denies only typed tokens');
    });

    test('by contrast an AUTHED route with no token → 401 (the pre-auth block is specific)', function () use ($B1) {
        [$st, , $j] = reqJson('GET', "{$B1}/api/fm/list?disk=local");
        assertEqual(401, $st);
        assertTrue(($j['error'] ?? '') !== '', 'auth error');
    });

    test('the operator create/list/revoke/analytics routes 501 the same way', function () use ($B1, $SECRET, $PREFIX) {
        $op = fluxfiles_token(['user' => 'op', 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => $PREFIX,
            'ttl' => 600, 'claims' => ['allow_intake' => true]]);
        $h = ["Authorization: Bearer {$op}"];
        [$st1, , $j1] = reqJson('POST', "{$B1}/api/fm/intake", ['json' => ['disk' => 'local', 'path' => 'drop'], 'headers' => $h]);
        assertEqual(501, $st1);
        assertEnvelope($j1, 'module_not_installed');
        [$st2, , $j2] = reqJson('GET', "{$B1}/api/fm/intake/list?disk=local", ['headers' => $h]);
        assertEqual(501, $st2);
        assertEnvelope($j2, 'module_not_installed');
        [$st3, , $j3] = reqJson('POST', "{$B1}/api/fm/intake/revoke", ['json' => ['disk' => 'local', 'jti' => 'x'], 'headers' => $h]);
        assertEqual(501, $st3);
        assertEnvelope($j3, 'module_not_installed');
        [$st4, , $j4] = reqJson('GET', "{$B1}/api/fm/intake/analytics?disk=local&jti=x", ['headers' => $h]);
        assertEqual(501, $st4);
        assertEnvelope($j4, 'module_not_installed');
    });

    test('a missing/garbage token still yields the envelope, never a 500 or a PHP notice', function () use ($B1) {
        foreach (['', 'nope', 'a.b.c', str_repeat('x', 4000)] as $t) {
            [$st, , $body] = req('GET', "{$B1}/api/fm/intake/info?token=" . rawurlencode($t));
            assertEqual(501, $st, "token=" . substr($t, 0, 12));
            $j = json_decode($body, true);
            assertTrue(is_array($j), 'valid JSON, not warning-prefixed: ' . substr($body, 0, 120));
            assertTrue(stripos($body, 'warning') === false && stripos($body, 'deprecated') === false, 'no PHP diagnostics in the body');
        }
    });

    test('an array-shaped token param does not corrupt the response', function () use ($B1) {
        [$st, , $body] = req('GET', "{$B1}/api/fm/intake/info?token[]=a&token[]=b");
        assertEqual(501, $st);
        assertTrue(is_array(json_decode($body, true)), 'still JSON: ' . substr($body, 0, 120));
    });

    test('CSRF still applies to the upload POST (foreign Origin → origin_denied)', function () use ($B1, $fakePortal) {
        [$st, , $j] = reqJson('POST', "{$B1}/api/fm/intake/upload", [
            'json' => ['token' => $fakePortal],
            'headers' => ['Origin: https://evil.example'],
        ]);
        assertEqual(403, $st);
        assertEqual('origin_denied', $j['error_code'] ?? null);
    });

    test('GET /public/intake.html is served: static, no-referrer, talks to the 2 public routes', function () use ($B1) {
        [$st, $h, $body] = req('GET', "{$B1}/public/intake.html");
        assertEqual(200, $st);
        assertTrue(stripos($h['content-type'] ?? '', 'text/html') === 0, 'html: ' . ($h['content-type'] ?? ''));
        assertTrue(strpos($body, '<meta name="referrer" content="no-referrer">') !== false, 'referrer meta');
        foreach (['/intake/info', '/intake/upload'] as $route) {
            assertTrue(strpos($body, $route) !== false, "wired to {$route}");
        }
        assertTrue(strpos($body, "'/api/fm'") !== false, 'defaults to the standalone API base');
        assertTrue(strpos($body, '<?php') === false, 'no PHP leaked into the landing');
        assertTrue(strpos($body, 'FluxFiles') === false, 'brand-neutral shell — brand comes from the paid payload');
    });

    stop($srv1);

    // ════════════════════════════════════════════════════════════════════════
    // Phase 2 — module installed, NOT licensed.
    // ════════════════════════════════════════════════════════════════════════
    if (!is_file($moduleSrc)) {
        echo "\n  {$yellow}skip{$reset} phases 2+3 (packages/intake not checked out)\n";
    } else {
        echo "\n{$cyan}── phase 2: module installed, unlicensed ──{$reset}\n\n";
        [$srv2, $B2] = boot(8114, $modRouter);

        test('both public routes → 402 license_required (gate order: installed → licensed)', function () use ($B2, $fakePortal) {
            [$st1, , $j1] = reqJson('GET', "{$B2}/api/fm/intake/info?token=" . rawurlencode($fakePortal));
            assertEqual(402, $st1);
            assertEnvelope($j1, 'license_required');
            assertEqual('intake', $j1['error_params']['module'] ?? null);
            [$st2, , $j2] = reqJson('POST', "{$B2}/api/fm/intake/upload", ['json' => ['token' => $fakePortal]]);
            assertEqual(402, $st2);
            assertEnvelope($j2, 'license_required');
        });

        test('the operator create route is gated the same way', function () use ($B2, $PREFIX) {
            $op = fluxfiles_token(['user' => 'op', 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => $PREFIX,
                'ttl' => 600, 'claims' => ['allow_intake' => true]]);
            [$st, , $j] = reqJson('POST', "{$B2}/api/fm/intake", [
                'json' => ['disk' => 'local', 'path' => 'drop'],
                'headers' => ["Authorization: Bearer {$op}"],
            ]);
            assertEqual(402, $st);
            assertEqual('license_required', $j['error_code'] ?? null);
        });

        stop($srv2);

        // ════════════════════════════════════════════════════════════════════
        // Phase 3 — licensed: the real portal journey + branding + analytics.
        // ════════════════════════════════════════════════════════════════════
        $license = mintLicense($repoRoot);
        if ($license === '') {
            echo "\n  {$yellow}skip{$reset} phase 3 (no offline signing key on this machine — expected in CI)\n";
        } else {
            echo "\n{$cyan}── phase 3: licensed (real intake journey) ──{$reset}\n\n";
            file_put_contents($envFile, $baseEnv . "FLUXFILES_LICENSE_KEY={$license}\n");
            [$srv3, $B] = boot(8115, $modRouter);

            $op = fluxfiles_token(['user' => 'op42', 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => $PREFIX,
                'ttl' => 600, 'claims' => ['allow_intake' => true]]);
            $opHdr = ["Authorization: Bearer {$op}"];
            /** Mint a portal through the real operator route. @return array<string,mixed> */
            $mint = static function (array $body, array $hdr = null) use ($B, $opHdr): array {
                [$st, , $j] = reqJson('POST', "{$B}/api/fm/intake", ['json' => array_merge(['disk' => 'local', 'path' => 'drop'], $body), 'headers' => $hdr ?? $opHdr]);
                if ($st !== 200) { throw new \RuntimeException('mint failed: ' . $st . ' ' . json_encode($j)); }
                return $j['data'];
            };
            /** Upload one file to a portal over real multipart HTTP. */
            $upload = static function (string $token, string $filename, string $contents, ?string $password = null) use ($B): array {
                $tmp = tempnam(sys_get_temp_dir(), 'ff_intake_');
                file_put_contents($tmp, $contents);
                $form = ['token' => $token, 'file' => new \CURLFile($tmp, 'application/octet-stream', $filename)];
                if ($password !== null) { $form['password'] = $password; }
                [$st, $h, $body] = req('POST', "{$B}/api/fm/intake/upload", ['form' => $form]);
                @unlink($tmp);
                return [$st, $h, json_decode($body, true)];
            };

            test('operator: POST /api/fm/intake → token + jti + a recipient URL on this origin', function () use ($mint, $B) {
                $p = $mint(['label' => 'Send us your files', 'ttl' => 3600]);
                assertTrue(!empty($p['token']) && !empty($p['jti']), 'token + jti');
                assertEqual(false, $p['has_password']);
                assertEqual('Send us your files', $p['label']);
                assertEqual("{$B}/public/intake.html?token=" . rawurlencode((string) $p['token']), $p['url'], 'landing URL');
            });

            test('recipient: GET /intake/info → the landing card, brand=null with no branding claims', function () use ($mint, $B) {
                $p = $mint(['label' => 'Send us your files']);
                [$st, , $j] = reqJson('GET', "{$B}/api/fm/intake/info?token=" . rawurlencode($p['token']));
                assertEqual(200, $st);
                $d = $j['data'];
                assertEqual('Send us your files', $d['label']);
                assertEqual(false, $d['has_password']);
                assertEqual(null, $d['remaining'], 'uncapped');
                assertEqual(null, $d['brand'], 'no branding claims => null');
            });

            test('operator: intake_brand_* claims are baked into the record and returned to the recipient', function () use ($B, $PREFIX) {
                $opBrand = fluxfiles_token(['user' => 'brandop', 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => $PREFIX,
                    'ttl' => 600, 'claims' => [
                        'allow_intake' => true,
                        'intake_brand_name' => 'Acme Inbox',
                        'intake_brand_logo_url' => 'https://acme.example/logo.png',
                        'intake_brand_color' => '#7c3aed',
                        'intake_brand_link_url' => 'https://acme.example',
                    ]]);
                [$st, , $j] = reqJson('POST', "{$B}/api/fm/intake", [
                    'json' => ['disk' => 'local', 'path' => 'drop'],
                    'headers' => ["Authorization: Bearer {$opBrand}"],
                ]);
                assertEqual(200, $st);
                $p = $j['data'];

                [$st2, , $j2] = reqJson('GET', "{$B}/api/fm/intake/info?token=" . rawurlencode($p['token']));
                assertEqual(200, $st2);
                assertEqual([
                    'name' => 'Acme Inbox',
                    'logo_url' => 'https://acme.example/logo.png',
                    'color' => '#7c3aed',
                    'link_url' => 'https://acme.example',
                ], $j2['data']['brand'], 'brand baked in at create time');
            });

            test('recipient: a successful upload lands the bytes on disk and decrements remaining', function () use ($mint, $upload, $uploadRoot, $PREFIX) {
                $p = $mint(['label' => 'cap test', 'max_files' => 5]);
                [$st, , $j] = $upload($p['token'], 'hello.txt', 'hello from the recipient');
                assertEqual(200, $st);
                assertEqual(true, $j['data']['received']);
                assertEqual('hello.txt', $j['data']['name']);
                assertEqual(4, $j['data']['remaining']);
                assertTrue(is_file("{$uploadRoot}/{$PREFIX}/drop/hello.txt"), 'bytes actually landed');
                assertEqual('hello from the recipient', file_get_contents("{$uploadRoot}/{$PREFIX}/drop/hello.txt"));
            });

            test('password: upload without a password is refused; the right one succeeds', function () use ($mint, $upload) {
                $p = $mint(['password' => 's3cret']);
                [$st1, , $j1] = $upload($p['token'], 'a.txt', 'x');
                assertEqual(401, $st1);
                assertEqual('intake_password', $j1['error_code'] ?? null);
                [$st2, , $j2] = $upload($p['token'], 'a.txt', 'x', 'wrong');
                assertEqual(401, $st2);
                assertEqual('intake_password_wrong', $j2['error_code'] ?? null);
                [$st3, , $j3] = $upload($p['token'], 'a.txt', 'x', 's3cret');
                assertEqual(200, $st3);
                assertEqual(true, $j3['data']['received']);
            });

            test('cap: hitting max_files → 410 intake_full, remaining reads 0 on the info card', function () use ($mint, $upload, $B) {
                $p = $mint(['max_files' => 1]);
                [$st1] = $upload($p['token'], 'first.txt', 'x');
                assertEqual(200, $st1);
                [$st2, , $j2] = $upload($p['token'], 'second.txt', 'x');
                assertEqual(410, $st2);
                assertEqual('intake_full', $j2['error_code'] ?? null);
                [, , $info] = reqJson('GET', "{$B}/api/fm/intake/info?token=" . rawurlencode($p['token']));
                assertEqual(0, $info['data']['remaining']);
            });

            test('operator: GET /intake/list returns records without password hashes', function () use ($mint, $B, $opHdr) {
                $p = $mint(['password' => 'pw', 'label' => 'listed']);
                [$st, , $j] = reqJson('GET', "{$B}/api/fm/intake/list?disk=local", ['headers' => $opHdr]);
                assertEqual(200, $st);
                $rec = null;
                foreach ($j['data'] as $r) { if ($r['jti'] === $p['jti']) { $rec = $r; } }
                assertTrue($rec !== null, 'portal listed');
                assertTrue(!array_key_exists('password_hash', $rec), 'hash never leaves the server');
                assertTrue(!array_key_exists('token', $rec), 'the token is returned once, never stored');
                assertEqual('op42', $rec['owner']);
            });

            test('operator: revoke kills the link → 404 intake_revoked, uploads refused too', function () use ($mint, $upload, $B, $opHdr) {
                $p = $mint([]);
                [$st, , $j] = reqJson('POST', "{$B}/api/fm/intake/revoke", ['json' => ['disk' => 'local', 'jti' => $p['jti']], 'headers' => $opHdr]);
                assertEqual(200, $st);
                assertEqual(true, $j['data']['revoked']);
                [$st2, , $j2] = reqJson('GET', "{$B}/api/fm/intake/info?token=" . rawurlencode($p['token']));
                assertEqual(404, $st2);
                assertEqual('intake_revoked', $j2['error_code'] ?? null);
                [$st3, , $j3] = $upload($p['token'], 'x.txt', 'x');
                assertEqual(404, $st3, 'revoked portal refuses uploads too');
                assertEqual('intake_revoked', $j3['error_code'] ?? null);
            });

            test('owner_only: another tenant on the same prefix cannot list or revoke my portals', function () use ($mint, $B, $PREFIX) {
                $p = $mint([]);
                $other = fluxfiles_token(['user' => 'u99', 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => $PREFIX,
                    'ttl' => 600, 'ownerOnly' => true, 'claims' => ['allow_intake' => true]]);
                $h = ["Authorization: Bearer {$other}"];
                [$st, , $j] = reqJson('GET', "{$B}/api/fm/intake/list?disk=local", ['headers' => $h]);
                assertEqual(200, $st);
                foreach ($j['data'] as $r) { assertEqual('u99', $r['owner'], 'only their own portals'); }
                [$st2, , $j2] = reqJson('POST', "{$B}/api/fm/intake/revoke", ['json' => ['disk' => 'local', 'jti' => $p['jti']], 'headers' => $h]);
                assertEqual(403, $st2);
                assertEqual('perm_denied', $j2['error_code'] ?? null);
                [$st3] = req('GET', "{$B}/api/fm/intake/info?token=" . rawurlencode($p['token']));
                assertEqual(200, $st3, 'not revoked by the stranger');
            });

            test('a token without allow_intake cannot mint one (3rd gate layer)', function () use ($B, $PREFIX) {
                $plain = fluxfiles_token(['user' => 'op42', 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => $PREFIX, 'ttl' => 600]);
                [$st, , $j] = reqJson('POST', "{$B}/api/fm/intake", [
                    'json' => ['disk' => 'local', 'path' => 'drop'],
                    'headers' => ["Authorization: Bearer {$plain}"],
                ]);
                assertEqual(403, $st);
                assertEqual('allow_intake_forbidden', $j['error_code'] ?? null);
            });

            test('analytics: intake_analytics=true records a received + a rejected event over real HTTP; off by default', function () use ($B, $PREFIX, $opHdr, $upload) {
                $opAnalytics = fluxfiles_token(['user' => 'analyticsop', 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => $PREFIX,
                    'ttl' => 600, 'claims' => ['allow_intake' => true, 'intake_analytics' => true]]);
                [$st, , $j] = reqJson('POST', "{$B}/api/fm/intake", [
                    'json' => ['disk' => 'local', 'path' => 'drop', 'password' => 's3cret'],
                    'headers' => ["Authorization: Bearer {$opAnalytics}"],
                ]);
                assertEqual(200, $st);
                $p = $j['data'];

                // One successful upload (right password) + one failing one (wrong
                // password), driven over real HTTP.
                [$stOk, , $jOk] = $upload($p['token'], 'report.csv', 'a,b,c', 's3cret');
                assertEqual(200, $stOk);
                assertEqual(true, $jOk['data']['received']);
                [$stBad, , $jBad] = $upload($p['token'], 'nope.csv', 'x', 'wrong');
                assertEqual(401, $stBad);
                assertEqual('intake_password_wrong', $jBad['error_code'] ?? null);

                [$stA, , $jA] = reqJson('GET', "{$B}/api/fm/intake/analytics?disk=local&jti=" . rawurlencode($p['jti']),
                    ['headers' => ["Authorization: Bearer {$opAnalytics}"]]);
                assertEqual(200, $stA);
                assertEqual($p['jti'], $jA['data']['jti']);
                assertEqual(true, $jA['data']['analytics_enabled']);
                assertEqual(2, $jA['data']['total']);
                $byType = [];
                foreach ($jA['data']['events'] as $ev) { $byType[$ev['type']] = $ev; }
                assertTrue(isset($byType['received'], $byType['rejected']), 'both event types present: ' . json_encode($jA['data']['events']));
                assertEqual(null, $byType['received']['reason'], 'received events carry no reason');
                assertEqual('report.csv', $byType['received']['name'], 'stored filename recorded');
                assertEqual('intake_password_wrong', $byType['rejected']['reason']);
                assertEqual('nope.csv', $byType['rejected']['name'], 'submitted filename recorded even on rejection');
                foreach ($jA['data']['events'] as $ev) {
                    assertTrue(is_string($ev['ip']), 'ip is a string (possibly empty over loopback)');
                    assertTrue($ev['ts'] > 0, 'a unix timestamp');
                }

                // event= filter narrows it.
                [, , $jRej] = reqJson('GET', "{$B}/api/fm/intake/analytics?disk=local&jti=" . rawurlencode($p['jti']) . '&event=rejected',
                    ['headers' => ["Authorization: Bearer {$opAnalytics}"]]);
                assertEqual(1, $jRej['data']['total']);
                assertEqual('rejected', $jRej['data']['events'][0]['type']);

                // A portal minted WITHOUT intake_analytics: off by default, no events,
                // but still a clean 200 (never a bug-looking response) — and the
                // unconditional `rejected` counter still bumps regardless.
                [$st2, , $j2] = reqJson('POST', "{$B}/api/fm/intake", [
                    'json' => ['disk' => 'local', 'path' => 'drop', 'max_files' => 1],
                    'headers' => $opHdr,
                ]);
                assertEqual(200, $st2);
                $p2 = $j2['data'];
                [$stFirst] = $upload($p2['token'], 'one.txt', 'x');
                assertEqual(200, $stFirst);
                [$stSecond, , $jSecond] = $upload($p2['token'], 'two.txt', 'x');
                assertEqual(410, $stSecond);
                assertEqual('intake_full', $jSecond['error_code'] ?? null);

                [$stOff, , $jOff] = reqJson('GET', "{$B}/api/fm/intake/analytics?disk=local&jti=" . rawurlencode($p2['jti']), ['headers' => $opHdr]);
                assertEqual(200, $stOff);
                assertEqual(false, $jOff['data']['analytics_enabled']);
                assertEqual([], $jOff['data']['events']);

                [, , $listAfter] = reqJson('GET', "{$B}/api/fm/intake/list?disk=local", ['headers' => $opHdr]);
                $recAfter = null;
                foreach ($listAfter['data'] as $r) { if ($r['jti'] === $p2['jti']) { $recAfter = $r; } }
                assertEqual(1, $recAfter['rejected'] ?? -1, 'the aggregate counter is unconditional even with analytics off');
            });

            stop($srv3);
        }
    }
} finally {
    if ($envBackup === null) { @unlink($envFile); } else { file_put_contents($envFile, $envBackup); }
    foreach ($procs as $p) { if (is_resource($p)) { @proc_terminate($p); } }
    @unlink($modRouter);
    $dir = "{$uploadRoot}/{$PREFIX}";
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($dir);
    }
}

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
