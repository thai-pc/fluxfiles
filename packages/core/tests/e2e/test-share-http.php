<?php

/**
 * End-to-end HTTP test for the public Share landing routes. Boots the real router
 * (own `php -S`, backs up/restores packages/core/.env, needs curl) and drives
 * /api/fm/share/{info,unlock,file} over the wire.
 *
 * It runs in three phases, each its own server, because the gate has three layers:
 *
 *   1. FREE CORE (always runs, incl. CI) — `packages/share` is not composer-installed,
 *      so the module class is absent → every public route must 501 in the standard
 *      envelope, with NO Authorization header anywhere (the whole point of a pre-auth
 *      route), and /public/share.html must be served.
 *   2. MODULE INSTALLED, UNLICENSED — the server boots through a wrapper router that
 *      requires the module src (it isn't a composer dep in dev — same trick the
 *      module's own test uses) but gets no license → 402. Proves the gate ORDER:
 *      installed → licensed. Skipped when packages/share isn't checked out.
 *   3. LICENSED — a real key minted with scripts/license-gen.php + the local signing
 *      key. LicenseManager is untouched (its public-key override is constructor-only,
 *      by design); we simply hand the server a genuine license. Skipped when the
 *      signing key isn't on this machine, which is the normal case in CI.
 *
 * Usage: php tests/e2e/test-share-http.php   (requires the curl extension)
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

$SECRET = str_repeat('h', 40);
$_ENV['FLUXFILES_SECRET'] = $SECRET;
$coreDir = (string) realpath(__DIR__ . '/../..');
$repoRoot = (string) realpath(__DIR__ . '/../../../..');
$uploadRoot = $coreDir . '/storage/uploads';
$PREFIX = 'share_e2e';

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
@mkdir("{$uploadRoot}/{$PREFIX}/reports", 0777, true);
file_put_contents("{$uploadRoot}/{$PREFIX}/reports/q3.pdf", "%PDF-1.4 quarterly numbers\n");
file_put_contents("{$uploadRoot}/{$PREFIX}/notes.txt", 'plain text');
$im = imagecreatetruecolor(400, 300);
imagefilledrectangle($im, 0, 0, 399, 299, imagecolorallocate($im, 30, 120, 200));
imagepng($im, "{$uploadRoot}/{$PREFIX}/photo.png");
imagedestroy($im);

$envFile = $coreDir . '/.env';
$envBackup = is_file($envFile) ? file_get_contents($envFile) : null;
$baseEnv = "FLUXFILES_SECRET={$SECRET}\nFLUXFILES_RATE_LIMIT_READ=100000\nFLUXFILES_RATE_LIMIT_WRITE=100000\n";
file_put_contents($envFile, $baseEnv);

// The module src isn't a composer dep in dev, so phases 2+3 boot through a wrapper
// router that requires it before delegating to the real router.php — the same
// "require the module src directly" trick the module's own test uses. (The built-in
// server ignores auto_prepend_file for its router script, hence the wrapper.)
$moduleSrc = "{$repoRoot}/packages/share/src/ShareModule.php";
$modRouter = sys_get_temp_dir() . '/ff-share-router-' . getmypid() . '.php';
file_put_contents($modRouter, "<?php\nrequire_once '{$coreDir}/vendor/autoload.php';\nrequire_once '{$moduleSrc}';\nreturn require '{$coreDir}/router.php';\n");

/** A real license for `share`, or '' when the offline signing key isn't on this box. */
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
    $p = proc_open(['php', $gen, '--edition=pro', '--modules=share', '--enforcement=perpetual', '--expires=+30d', '--kid=k1'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($p)) { return ''; }
    $tok = trim((string) stream_get_contents($pipes[1])); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return (new \FluxFiles\LicenseManager($tok))->licensed('share') ? $tok : '';
}

echo "\n{$cyan}══ Share public landing over HTTP (e2e) ══{$reset}\n\n";

try {
    // ════════════════════════════════════════════════════════════════════════
    // Phase 1 — FREE CORE: the module isn't installed.
    // ════════════════════════════════════════════════════════════════════════
    echo "{$cyan}── phase 1: free core (module absent) ──{$reset}\n\n";
    [$srv1, $B1] = boot(8110);

    // A syntactically real share token, so nothing can pass merely for being junk.
    $fakeShare = \FluxFiles\JwtCompat::encode([
        'sub' => 'share:u1', 'iat' => time(), 'exp' => time() + 600, 'jti' => bin2hex(random_bytes(12)),
        'perms' => ['read'], 'disks' => ['local'], 'prefix' => "{$PREFIX}/reports/q3.pdf",
        'share' => true, 'store' => "{$PREFIX}/",
    ], $SECRET);

    test('GET /share/info → 501 module_not_installed, standard envelope, NO auth header sent', function () use ($B1, $fakeShare) {
        [$st, $h, $j] = reqJson('GET', "{$B1}/api/fm/share/info?token=" . rawurlencode($fakeShare));
        assertEqual(501, $st);
        assertEnvelope($j, 'module_not_installed');
        assertEqual('share', $j['error_params']['module'] ?? null, 'names the module');
        assertTrue(stripos($h['content-type'] ?? '', 'application/json') === 0, 'json content-type');
    });

    test('POST /share/unlock → 501 (same envelope)', function () use ($B1, $fakeShare) {
        [$st, , $j] = reqJson('POST', "{$B1}/api/fm/share/unlock", ['json' => ['token' => $fakeShare, 'password' => 'x']]);
        assertEqual(501, $st);
        assertEnvelope($j, 'module_not_installed');
    });

    test('GET /share/file → 501 JSON, never bytes', function () use ($B1, $fakeShare) {
        [$st, $h, $body] = req('GET', "{$B1}/api/fm/share/file?token=" . rawurlencode($fakeShare));
        assertEqual(501, $st);
        assertEqual('module_not_installed', json_decode($body, true)['error_code'] ?? null);
        assertTrue(($h['content-disposition'] ?? '') === '', 'no download offered');
    });

    test('the public routes are truly pre-auth: a garbage Bearer changes nothing', function () use ($B1, $fakeShare) {
        // If these routes were behind JwtMiddleware, a bad token would 401 first.
        foreach (['Authorization: Bearer not-a-jwt', 'Authorization: Bearer ' . str_repeat('a.', 3)] as $auth) {
            [$st, , $j] = reqJson('GET', "{$B1}/api/fm/share/info?token=" . rawurlencode($fakeShare), ['headers' => [$auth]]);
            assertEqual(501, $st, 'gate, not auth');
            assertEnvelope($j, 'module_not_installed');
        }
    });

    test('REPLAY: a share-shaped token is refused on the MAIN API (403 token_not_access)', function () use ($B1, $fakeShare) {
        // The whole point of the landing is that this token reaches an untrusted
        // recipient. It is signed with FLUXFILES_SECRET and carries perms/disks/prefix,
        // so without the guard it is a working access JWT: /content would return the
        // bytes, /zip would zip them and /presign would hand out a 24h URL — past the
        // password, past max_downloads, and past revoke (which only deletes the
        // storage record while the JWT lives to `exp`). Runs in free core: this is a
        // core auth property, not a module one.
        $h = ['headers' => ["Authorization: Bearer {$fakeShare}"]];
        foreach ([
            ['GET', '/api/fm/content?disk=local&path=share_e2e/reports/q3.pdf'],
            ['GET', '/api/fm/list?disk=local'],
            ['POST', '/api/fm/zip'],
            ['POST', '/api/fm/presign'],
        ] as [$m, $u]) {
            [$st, , $j] = reqJson($m, "{$B1}{$u}", $h);
            assertEqual(403, $st, "{$m} {$u}");
            assertEqual('token_not_access', $j['error_code'] ?? null, "{$m} {$u}");
            assertTrue(strpos(json_encode($j), 'quarterly') === false, 'no bytes leaked');
        }
    });

    test('REPLAY: an intake portal token is refused on the main API the same way', function () use ($B1, $SECRET, $PREFIX) {
        $portal = \FluxFiles\JwtCompat::encode([
            't' => 'intake', 'sub' => 'intake:u1', 'iat' => time(), 'exp' => time() + 600,
            'jti' => bin2hex(random_bytes(12)), 'perms' => ['write'], 'disks' => ['local'],
            'prefix' => "{$PREFIX}/drop", 'intake' => true, 'store' => "{$PREFIX}/",
        ], $SECRET);
        [$st, , $j] = reqJson('GET', "{$B1}/api/fm/list?disk=local", ['headers' => ["Authorization: Bearer {$portal}"]]);
        assertEqual(403, $st);
        assertEqual('token_not_access', $j['error_code'] ?? null);
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

    test('a missing/garbage token still yields the envelope, never a 500 or a PHP notice', function () use ($B1) {
        foreach (['', 'nope', 'a.b.c', str_repeat('x', 4000)] as $t) {
            [$st, , $body] = req('GET', "{$B1}/api/fm/share/file?token=" . rawurlencode($t));
            assertEqual(501, $st, "token=" . substr($t, 0, 12));
            $j = json_decode($body, true);
            assertTrue(is_array($j), 'valid JSON, not warning-prefixed: ' . substr($body, 0, 120));
            assertTrue(stripos($body, 'warning') === false && stripos($body, 'deprecated') === false, 'no PHP diagnostics in the body');
        }
    });

    test('an array-shaped token param does not corrupt the response', function () use ($B1) {
        [$st, , $body] = req('GET', "{$B1}/api/fm/share/file?token[]=a&token[]=b");
        assertEqual(501, $st);
        assertTrue(is_array(json_decode($body, true)), 'still JSON: ' . substr($body, 0, 120));
    });

    test('an unsupported method on a public route stays inside the gate (no route leak)', function () use ($B1, $fakeShare) {
        [$st, , $j] = reqJson('POST', "{$B1}/api/fm/share/info", ['json' => ['token' => $fakeShare]]);
        assertEqual(501, $st);
        assertEnvelope($j, 'module_not_installed');
    });

    test('CSRF still applies to the unlock POST (foreign Origin → origin_denied)', function () use ($B1, $fakeShare) {
        [$st, , $j] = reqJson('POST', "{$B1}/api/fm/share/unlock", [
            'json' => ['token' => $fakeShare, 'password' => 'x'],
            'headers' => ['Origin: https://evil.example'],
        ]);
        assertEqual(403, $st);
        assertEqual('origin_denied', $j['error_code'] ?? null);
    });

    test('same-origin unlock passes CSRF and reaches the gate', function () use ($B1, $fakeShare) {
        [$st, , $j] = reqJson('POST', "{$B1}/api/fm/share/unlock", [
            'json' => ['token' => $fakeShare, 'password' => 'x'],
            'headers' => ['Origin: ' . $B1],
        ]);
        assertEqual(501, $st);
        assertEnvelope($j, 'module_not_installed');
    });

    test('GET /public/share.html is served: static, no-referrer, talks to the 3 public routes', function () use ($B1) {
        [$st, $h, $body] = req('GET', "{$B1}/public/share.html");
        assertEqual(200, $st);
        assertTrue(stripos($h['content-type'] ?? '', 'text/html') === 0, 'html: ' . ($h['content-type'] ?? ''));
        assertTrue(strpos($body, '<meta name="referrer" content="no-referrer">') !== false, 'referrer meta');
        // Asserted on the route NAMES, not on a full literal path: the page resolves
        // its API base at runtime (window.__FM_API_BASE__) so a host that serves it from
        // elsewhere — the WordPress plugin serves it out of wp-content, with the API
        // under /wp-json/ — can point it at their own namespace. The default base is
        // checked separately below, which is what keeps standalone honest.
        foreach (['/share/info', '/share/unlock', '/share/file'] as $route) {
            assertTrue(strpos($body, $route) !== false, "wired to {$route}");
        }
        assertTrue(strpos($body, "'/api/fm'") !== false, 'defaults to the standalone API base');
        assertTrue(strpos($body, '<?php') === false, 'no PHP leaked into the landing');
        // Free shell: nothing branded of its own — brand comes from the paid payload.
        assertTrue(strpos($body, 'FluxFiles') === false, 'brand-neutral shell');
    });

    stop($srv1);

    // ════════════════════════════════════════════════════════════════════════
    // Phase 2 — module installed, NOT licensed.
    // ════════════════════════════════════════════════════════════════════════
    if (!is_file($moduleSrc)) {
        echo "\n  {$yellow}skip{$reset} phases 2+3 (packages/share not checked out)\n";
    } else {
        echo "\n{$cyan}── phase 2: module installed, unlicensed ──{$reset}\n\n";
        [$srv2, $B2] = boot(8111, $modRouter);

        test('all three public routes → 402 license_required (gate order: installed → licensed)', function () use ($B2, $fakeShare) {
            [$st1, , $j1] = reqJson('GET', "{$B2}/api/fm/share/info?token=" . rawurlencode($fakeShare));
            assertEqual(402, $st1);
            assertEnvelope($j1, 'license_required');
            assertEqual('share', $j1['error_params']['module'] ?? null);
            [$st2, , $j2] = reqJson('POST', "{$B2}/api/fm/share/unlock", ['json' => ['token' => $fakeShare, 'password' => 'x']]);
            assertEqual(402, $st2);
            assertEnvelope($j2, 'license_required');
            [$st3, , $j3] = reqJson('GET', "{$B2}/api/fm/share/file?token=" . rawurlencode($fakeShare));
            assertEqual(402, $st3);
            assertEnvelope($j3, 'license_required');
        });

        test('the operator create route is gated the same way', function () use ($B2, $SECRET, $PREFIX) {
            $op = fluxfiles_token(['user' => 'op', 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => $PREFIX,
                'ttl' => 600, 'claims' => ['allow_share' => true]]);
            [$st, , $j] = reqJson('POST', "{$B2}/api/fm/share", [
                'json' => ['disk' => 'local', 'path' => 'reports/q3.pdf'],
                'headers' => ["Authorization: Bearer {$op}"],
            ]);
            assertEqual(402, $st);
            assertEqual('license_required', $j['error_code'] ?? null);
        });

        stop($srv2);

        // ════════════════════════════════════════════════════════════════════
        // Phase 3 — licensed: the real recipient journey.
        // ════════════════════════════════════════════════════════════════════
        $license = mintLicense($repoRoot);
        if ($license === '') {
            echo "\n  {$yellow}skip{$reset} phase 3 (no offline signing key on this machine — expected in CI)\n";
        } else {
            echo "\n{$cyan}── phase 3: licensed (real share journey) ──{$reset}\n\n";
            // 3 unlock attempts/min per share+IP and 6 per share overall, so both
            // brute-force limits are testable (the second one is the ceiling that no
            // amount of IP rotation can raise).
            file_put_contents($envFile, $baseEnv . "FLUXFILES_LICENSE_KEY={$license}\nFLUXFILES_SHARE_UNLOCK_LIMIT=3\nFLUXFILES_SHARE_UNLOCK_TOTAL=6\n");
            [$srv3, $B] = boot(8112, $modRouter);

            $op = fluxfiles_token(['user' => 'op42', 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => $PREFIX,
                'ttl' => 600, 'claims' => ['allow_share' => true]]);
            $opHdr = ["Authorization: Bearer {$op}"];
            /** Mint a share through the real operator route. @return array<string,mixed> */
            $mint = static function (array $body) use ($B, $opHdr): array {
                [$st, , $j] = reqJson('POST', "{$B}/api/fm/share", ['json' => array_merge(['disk' => 'local'], $body), 'headers' => $opHdr]);
                if ($st !== 200) { throw new \RuntimeException('mint failed: ' . $st . ' ' . json_encode($j)); }
                return $j['data'];
            };

            test('operator: POST /api/fm/share → token + a recipient URL on this origin', function () use ($mint, $B, $uploadRoot, $PREFIX) {
                $s = $mint(['path' => 'reports/q3.pdf', 'label' => 'Q3 report', 'ttl' => 3600]);
                assertTrue(!empty($s['token']) && !empty($s['jti']), 'token + jti');
                assertEqual(false, $s['has_password']);
                assertEqual('Q3 report', $s['label']);
                assertEqual("{$B}/public/share.html?token=" . rawurlencode((string) $s['token']), $s['url'], 'landing URL');
                assertTrue(is_file("{$uploadRoot}/{$PREFIX}/_fluxfiles/shares.json"), 'record is storage-resident under the prefix');
            });

            test('recipient: GET /share/info → the card, with no storage path or disk name', function () use ($mint, $B, $PREFIX) {
                $s = $mint(['path' => 'reports/q3.pdf', 'label' => 'Q3 report']);
                [$st, , $j] = reqJson('GET', "{$B}/api/fm/share/info?token=" . rawurlencode($s['token']));
                assertEqual(200, $st);
                $d = $j['data'];
                assertEqual('q3.pdf', $d['file']['name']);
                assertEqual('application/pdf', $d['file']['mime']);
                assertEqual('pdf', $d['file']['kind']);
                assertEqual('Q3 report', $d['label']);
                assertEqual(null, $d['remaining'], 'uncapped');
                assertEqual(null, $d['brand'], 'free core renders no branding');
                assertEqual($d['file'], $d['files'][0], 'forward-compatible files[]');
                $raw = json_encode($d);
                assertTrue(strpos($raw, $PREFIX) === false, "storage prefix leaked: {$raw}");
                assertTrue(strpos($raw, '"disk"') === false, "disk name leaked: {$raw}");
            });

            test('operator: share_brand_* claims are baked into the record and returned to the recipient', function () use ($B, $PREFIX) {
                $opBrand = fluxfiles_token(['user' => 'brandop', 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => $PREFIX,
                    'ttl' => 600, 'claims' => [
                        'allow_share' => true,
                        'share_brand_name' => 'Acme Corp',
                        'share_brand_logo_url' => 'https://acme.example/logo.png',
                        'share_brand_color' => '#7c3aed',
                        'share_brand_link_url' => 'https://acme.example',
                    ]]);
                [$st, , $j] = reqJson('POST', "{$B}/api/fm/share", [
                    'json' => ['disk' => 'local', 'path' => 'reports/q3.pdf'],
                    'headers' => ["Authorization: Bearer {$opBrand}"],
                ]);
                assertEqual(200, $st);
                $s = $j['data'];

                [$st2, , $j2] = reqJson('GET', "{$B}/api/fm/share/info?token=" . rawurlencode($s['token']));
                assertEqual(200, $st2);
                assertEqual([
                    'name' => 'Acme Corp',
                    'logo_url' => 'https://acme.example/logo.png',
                    'color' => '#7c3aed',
                    'link_url' => 'https://acme.example',
                ], $j2['data']['brand'], 'brand baked in at create time');
            });

            test('operator: a token with no share_brand_* claims still mints brand=null', function () use ($mint, $B) {
                $s = $mint(['path' => 'reports/q3.pdf']);
                [$st, , $j] = reqJson('GET', "{$B}/api/fm/share/info?token=" . rawurlencode($s['token']));
                assertEqual(200, $st);
                assertEqual(null, $j['data']['brand'], 'no brand claims => null, unaffected');
            });

            test('password: brand is visible on the pre-unlock card too', function () use ($B, $PREFIX) {
                $opBrand = fluxfiles_token(['user' => 'brandop2', 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => $PREFIX,
                    'ttl' => 600, 'claims' => ['allow_share' => true, 'share_brand_name' => 'Acme Corp']]);
                [$st, , $j] = reqJson('POST', "{$B}/api/fm/share", [
                    'json' => ['disk' => 'local', 'path' => 'reports/q3.pdf', 'password' => 's3cret'],
                    'headers' => ["Authorization: Bearer {$opBrand}"],
                ]);
                assertEqual(200, $st);
                $s = $j['data'];
                [$st2, , $j2] = reqJson('GET', "{$B}/api/fm/share/info?token=" . rawurlencode($s['token']));
                assertEqual(200, $st2);
                assertEqual(['has_password', 'expires', 'brand'], array_keys($j2['data']));
                assertEqual('Acme Corp', $j2['data']['brand']['name'] ?? null, 'branding shown even before unlock');
            });

            test('recipient: GET /share/file → the bytes, inline, with the hardening headers', function () use ($mint, $B) {
                $s = $mint(['path' => 'reports/q3.pdf']);
                [$st, $h, $body] = req('GET', "{$B}/api/fm/share/file?token=" . rawurlencode($s['token']));
                assertEqual(200, $st);
                assertEqual("%PDF-1.4 quarterly numbers\n", $body, 'real bytes, streamed by the app');
                assertEqual('inline; filename="q3.pdf"; filename*=UTF-8\'\'q3.pdf', $h['content-disposition'] ?? '');
                assertEqual('nosniff', $h['x-content-type-options'] ?? '');
                assertEqual('sandbox', $h['content-security-policy'] ?? '');
                assertEqual('private, no-store', $h['cache-control'] ?? '');
                assertEqual('no-referrer', $h['referrer-policy'] ?? '');
                assertEqual('application/pdf', $h['content-type'] ?? '');
            });

            test('recipient: ?dl=1 forces attachment; a .txt is attachment either way', function () use ($mint, $B) {
                $pdf = $mint(['path' => 'reports/q3.pdf']);
                [, $h] = req('GET', "{$B}/api/fm/share/file?token=" . rawurlencode($pdf['token']) . '&dl=1');
                assertEqual(0, strpos((string) ($h['content-disposition'] ?? ''), 'attachment;'));
                $txt = $mint(['path' => 'notes.txt']);
                [$st, $h2, $body] = req('GET', "{$B}/api/fm/share/file?token=" . rawurlencode($txt['token']));
                assertEqual(200, $st);
                assertEqual('plain text', $body);
                assertEqual(0, strpos((string) ($h2['content-disposition'] ?? ''), 'attachment;'), 'never inline for text');
            });

            test('a PUBLIC local disk never 302s to the static file URL (defect 2, over the wire)', function () use ($mint, $B) {
                $s = $mint(['path' => 'reports/q3.pdf']);
                [$st, $h] = req('GET', "{$B}/api/fm/share/file?token=" . rawurlencode($s['token']));
                assertEqual(200, $st, 'streamed, not redirected');
                assertTrue(!isset($h['location']), 'no Location header');
            });

            test('password: the card reveals NOTHING before unlock', function () use ($mint, $B) {
                $s = $mint(['path' => 'reports/q3.pdf', 'password' => 's3cret', 'label' => 'secret name']);
                [$st, , $j] = reqJson('GET', "{$B}/api/fm/share/info?token=" . rawurlencode($s['token']));
                assertEqual(200, $st);
                assertEqual(['has_password', 'expires', 'brand'], array_keys($j['data']));
                assertTrue(strpos(json_encode($j), 'q3.pdf') === false, 'filename is the secret');
            });

            test('password: the bytes GET refuses without a grant — and NEVER accepts the password itself', function () use ($mint, $B) {
                $s = $mint(['path' => 'reports/q3.pdf', 'password' => 's3cret']);
                $u = "{$B}/api/fm/share/file?token=" . rawurlencode($s['token']);
                [$st, , $j] = reqJson('GET', $u);
                assertEqual(401, $st);
                assertEqual('share_password', $j['error_code'] ?? null);
                // The password must never ride a query string (token + password in one
                // access-log line defeats the password) — the grant is the only key.
                foreach (['password', 'pw', 'p'] as $param) {
                    [$st2, , $body] = req('GET', "{$u}&{$param}=s3cret");
                    assertEqual(401, $st2, "query param {$param} must not unlock");
                    assertEqual('share_password', json_decode($body, true)['error_code'] ?? null);
                }
            });

            test('unlock: wrong password → 401 and no card; right password → card + grant', function () use ($mint, $B) {
                $s = $mint(['path' => 'reports/q3.pdf', 'password' => 's3cret', 'label' => 'Q3']);
                [$st, , $j] = reqJson('POST', "{$B}/api/fm/share/unlock", ['json' => ['token' => $s['token'], 'password' => 'wrong']]);
                assertEqual(401, $st);
                assertEqual('share_password_wrong', $j['error_code'] ?? null);
                assertTrue(strpos(json_encode($j), 'q3.pdf') === false, 'a failed attempt reveals nothing');

                [$st2, , $j2] = reqJson('POST', "{$B}/api/fm/share/unlock", ['json' => ['token' => $s['token'], 'password' => 's3cret']]);
                assertEqual(200, $st2);
                assertTrue(!empty($j2['data']['grant']), 'grant issued');
                assertTrue($j2['data']['grant_expires'] > time(), 'grant expiry');
                assertEqual('q3.pdf', $j2['data']['file']['name']);
            });

            test('grant: unlocks the bytes for ITS share only, and an expired one is refused', function () use ($mint, $B, $SECRET) {
                $a = $mint(['path' => 'reports/q3.pdf', 'password' => 'pw']);
                $b = $mint(['path' => 'notes.txt', 'password' => 'pw']);
                [, , $ja] = reqJson('POST', "{$B}/api/fm/share/unlock", ['json' => ['token' => $a['token'], 'password' => 'pw']]);
                $grant = $ja['data']['grant'];

                [$st, , $body] = req('GET', "{$B}/api/fm/share/file?token=" . rawurlencode($a['token']) . '&g=' . rawurlencode($grant));
                assertEqual(200, $st);
                assertEqual("%PDF-1.4 quarterly numbers\n", $body);

                [$st2, , $j2] = reqJson('GET', "{$B}/api/fm/share/file?token=" . rawurlencode($b['token']) . '&g=' . rawurlencode($grant));
                assertEqual(403, $st2, 'a grant for share A must not open share B');
                assertEqual('share_grant_invalid', $j2['error_code'] ?? null);

                $stale = \FluxFiles\JwtCompat::encode(['t' => 'share_grant', 'jti' => $a['jti'], 'iat' => time() - 100, 'exp' => time() - 10], $SECRET);
                [$st3, , $j3] = reqJson('GET', "{$B}/api/fm/share/file?token=" . rawurlencode($a['token']) . '&g=' . rawurlencode($stale));
                assertEqual(403, $st3);
                assertEqual('share_grant_invalid', $j3['error_code'] ?? null, 'the landing re-prompts');
            });

            test('a stream/img token is not a grant', function () use ($mint, $B, $SECRET) {
                $s = $mint(['path' => 'reports/q3.pdf', 'password' => 'pw']);
                $img = \FluxFiles\ImageToken::mint('local', 'share_e2e/photo.png', 'share:' . $s['jti'], 600, $SECRET, 1600);
                [$st, , $j] = reqJson('GET', "{$B}/api/fm/share/file?token=" . rawurlencode($s['token']) . '&g=' . rawurlencode($img));
                assertEqual(403, $st);
                assertEqual('share_grant_invalid', $j['error_code'] ?? null);
            });

            test('cap: the second download → 410 share_exhausted (distinct from expiry/revocation)', function () use ($mint, $B) {
                $s = $mint(['path' => 'reports/q3.pdf', 'max_downloads' => 1]);
                [$st1] = req('GET', "{$B}/api/fm/share/file?token=" . rawurlencode($s['token']));
                assertEqual(200, $st1);
                [$st2, , $j2] = reqJson('GET', "{$B}/api/fm/share/file?token=" . rawurlencode($s['token']));
                assertEqual(410, $st2);
                assertEqual('share_exhausted', $j2['error_code'] ?? null);
                [, , $info] = reqJson('GET', "{$B}/api/fm/share/info?token=" . rawurlencode($s['token']));
                assertEqual(0, $info['data']['remaining'], 'card shows none left');
            });

            test('expiry → 410 share_expired; a non-share JWT → 403 share_invalid', function () use ($mint, $B, $SECRET, $PREFIX) {
                $s = $mint(['path' => 'reports/q3.pdf']);
                $expired = \FluxFiles\JwtCompat::encode([
                    'sub' => 'share:op42', 'iat' => time() - 100, 'exp' => time() - 10, 'jti' => $s['jti'],
                    'perms' => ['read'], 'disks' => ['local'], 'prefix' => "{$PREFIX}/reports/q3.pdf",
                    'share' => true, 'store' => "{$PREFIX}/",
                ], $SECRET);
                [$st, , $j] = reqJson('GET', "{$B}/api/fm/share/info?token=" . rawurlencode($expired));
                assertEqual(410, $st);
                assertEqual('share_expired', $j['error_code'] ?? null, 'told to ask for a new link, not "broken"');

                $access = \FluxFiles\JwtCompat::encode(['sub' => 'op42', 'perms' => ['read'], 'disks' => ['local'], 'exp' => time() + 600], $SECRET);
                [$st2, , $j2] = reqJson('GET', "{$B}/api/fm/share/info?token=" . rawurlencode($access));
                assertEqual(403, $st2);
                assertEqual('share_invalid', $j2['error_code'] ?? null, 'a main JWT is not a share link');
            });

            test('garbage / missing tokens → clean envelopes, no 500, no PHP diagnostics', function () use ($B) {
                foreach (['', 'nope', 'a.b.c', str_repeat('x', 4000)] as $t) {
                    [$st, , $body] = req('GET', "{$B}/api/fm/share/info?token=" . rawurlencode($t));
                    assertEqual(403, $st, "token='" . substr($t, 0, 12) . "' → " . substr($body, 0, 120));
                    $j = json_decode($body, true);
                    assertTrue(is_array($j), 'valid JSON: ' . substr($body, 0, 160));
                    assertEqual('share_invalid', $j['error_code'] ?? null);
                    assertTrue(stripos($body, 'warning') === false && stripos($body, 'notice') === false, 'no PHP diagnostics: ' . substr($body, 0, 160));
                }
            });

            test('an array-shaped token is refused (4xx), serves no bytes and keeps the envelope intact', function () use ($B) {
                // `?token[]=a` makes $_GET['token'] an ARRAY. Casting that to string used
                // to emit "Array to string conversion" ahead of the JSON — corrupting the
                // envelope and, with display_errors on, disclosing the absolute server
                // path. ff_str_param() collapses any non-scalar to '' instead.
                [$st, $h, $body] = req('GET', "{$B}/api/fm/share/file?token[]=a&token[]=b");
                assertTrue($st >= 400 && $st < 500, "refused: {$st}");
                assertTrue(!isset($h['content-disposition']), 'no file offered');
                assertTrue(strpos($body, '%PDF') === false, 'no bytes leaked');
                assertTrue(is_array(json_decode($body, true)), 'clean JSON envelope: ' . substr($body, 0, 160));
                assertTrue(stripos($body, 'warning') === false && stripos($body, '.php') === false,
                    'no PHP diagnostic / server path: ' . substr($body, 0, 160));
                // Same for the other two share routes and the `g` param.
                foreach (['info?token[]=a', 'file?token=x&g[]=y'] as $q) {
                    [$s2, , $b2] = req('GET', "{$B}/api/fm/share/{$q}");
                    assertTrue($s2 >= 400 && $s2 < 500, "refused: {$s2}");
                    assertTrue(is_array(json_decode($b2, true)), 'clean JSON: ' . substr($b2, 0, 160));
                }
                [$s3, , $b3] = req('POST', "{$B}/api/fm/share/unlock", ['json' => ['token' => ['a'], 'password' => ['b']]]);
                assertTrue($s3 >= 400 && $s3 < 500, "refused: {$s3}");
                assertTrue(is_array(json_decode($b3, true)), 'clean JSON: ' . substr($b3, 0, 160));
            });

            test('a token for another store is not resolvable (defect 1, over the wire)', function () use ($mint, $B, $SECRET, $PREFIX) {
                $s = $mint(['path' => 'reports/q3.pdf']);
                // A WELL-FORMED share token (type marker and all) whose `store` points at
                // a different tenant prefix → no record there. The marker matters: without
                // it the request would be refused as malformed (403) and this case would
                // stop testing the store mismatch it exists for.
                $moved = \FluxFiles\JwtCompat::encode([
                    't' => 'share', 'sub' => 'share:op42', 'iat' => time(), 'exp' => time() + 600, 'jti' => $s['jti'],
                    'perms' => ['read'], 'disks' => ['local'], 'prefix' => "{$PREFIX}/reports/q3.pdf",
                    'share' => true, 'store' => 'someone_else/',
                ], $SECRET);
                [$st, , $j] = reqJson('GET', "{$B}/api/fm/share/info?token=" . rawurlencode($moved));
                assertEqual(404, $st);
                assertEqual('share_revoked', $j['error_code'] ?? null);
            });

            test('a share token from before the `t` marker is refused as malformed', function () use ($mint, $B, $SECRET, $PREFIX) {
                // Load-bearing behaviour, so it gets its own case: the marker is what
                // keeps the token off the main API (JwtMiddleware refuses every typed
                // token), and the module refuses anything without it as `share_invalid`
                // rather than silently accepting a shape that predates the guard.
                $s = $mint(['path' => 'reports/q3.pdf']);
                $legacy = \FluxFiles\JwtCompat::encode([
                    'sub' => 'share:op42', 'iat' => time(), 'exp' => time() + 600, 'jti' => $s['jti'],
                    'perms' => ['read'], 'disks' => ['local'], 'prefix' => "{$PREFIX}/reports/q3.pdf",
                    'share' => true, 'store' => "{$PREFIX}/",
                ], $SECRET);
                [$st, , $j] = reqJson('GET', "{$B}/api/fm/share/info?token=" . rawurlencode($legacy));
                assertEqual(403, $st);
                assertEqual('share_invalid', $j['error_code'] ?? null);
                // …and it is no more usable as an access token than a current one.
                [$st2, , $j2] = reqJson('GET', "{$B}/api/fm/list?disk=local", ['headers' => ["Authorization: Bearer {$legacy}"]]);
                assertEqual(403, $st2);
                assertEqual('token_not_access', $j2['error_code'] ?? null, 'the `share` flag alone is still refused');
            });

            test('image share: the preview is a bounded /img transform that never counts a download', function () use ($mint, $B, $opHdr) {
                $s = $mint(['path' => 'photo.png', 'max_downloads' => 5]);
                [$st, , $j] = reqJson('GET', "{$B}/api/fm/share/info?token=" . rawurlencode($s['token']));
                assertEqual(200, $st);
                $prev = (string) ($j['data']['file']['preview_url'] ?? '');
                assertEqual(0, strpos($prev, '/api/fm/img?token='), "preview url: {$prev}");
                assertTrue(strpos($prev, rawurlencode($s['token'])) === false, 'the share token does not ride the preview URL');

                [$pst, $ph, $pbody] = req('GET', $B . $prev, ['headers' => ['Accept: image/webp,*/*']]);
                assertEqual(200, $pst, 'preview renders');
                assertTrue(strlen($pbody) > 0 && stripos((string) ($ph['content-type'] ?? ''), 'image/') === 0, 'an image came back');

                [, , $list] = reqJson('GET', "{$B}/api/fm/share/list?disk=local", ['headers' => $opHdr]);
                $rec = null;
                foreach ($list['data'] as $r) { if ($r['jti'] === $s['jti']) { $rec = $r; } }
                assertEqual(0, $rec['downloads'] ?? -1, 'a preview is not a download');
                assertEqual(5, $rec['max_downloads'] ?? -1);
            });

            test('operator: GET /share/list returns records without password hashes', function () use ($mint, $B, $opHdr) {
                $s = $mint(['path' => 'reports/q3.pdf', 'password' => 'pw', 'label' => 'listed']);
                [$st, , $j] = reqJson('GET', "{$B}/api/fm/share/list?disk=local", ['headers' => $opHdr]);
                assertEqual(200, $st);
                $rec = null;
                foreach ($j['data'] as $r) { if ($r['jti'] === $s['jti']) { $rec = $r; } }
                assertTrue($rec !== null, 'share listed');
                assertTrue(!array_key_exists('password_hash', $rec), 'hash never leaves the server');
                assertTrue(!array_key_exists('token', $rec), 'the token is returned once, never stored');
                assertEqual('op42', $rec['owner']);
            });

            test('operator: revoke kills the link → 404 share_revoked', function () use ($mint, $B, $opHdr) {
                $s = $mint(['path' => 'reports/q3.pdf']);
                [$st, , $j] = reqJson('POST', "{$B}/api/fm/share/revoke", ['json' => ['disk' => 'local', 'jti' => $s['jti']], 'headers' => $opHdr]);
                assertEqual(200, $st);
                assertEqual(true, $j['data']['revoked']);
                [$st2, , $j2] = reqJson('GET', "{$B}/api/fm/share/info?token=" . rawurlencode($s['token']));
                assertEqual(404, $st2);
                assertEqual('share_revoked', $j2['error_code'] ?? null);
                [$st3, , $j3] = reqJson('GET', "{$B}/api/fm/share/file?token=" . rawurlencode($s['token']));
                assertEqual(404, $st3, 'the bytes are gone too');
                assertEqual('share_revoked', $j3['error_code'] ?? null);
            });

            test('owner_only: another tenant on the same prefix cannot list or revoke my shares', function () use ($mint, $B, $PREFIX) {
                $s = $mint(['path' => 'reports/q3.pdf']);
                $other = fluxfiles_token(['user' => 'u99', 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => $PREFIX,
                    'ttl' => 600, 'ownerOnly' => true, 'claims' => ['allow_share' => true]]);
                $h = ["Authorization: Bearer {$other}"];
                [$st, , $j] = reqJson('GET', "{$B}/api/fm/share/list?disk=local", ['headers' => $h]);
                assertEqual(200, $st);
                foreach ($j['data'] as $r) { assertEqual('u99', $r['owner'], 'only their own shares'); }
                [$st2, , $j2] = reqJson('POST', "{$B}/api/fm/share/revoke", ['json' => ['disk' => 'local', 'jti' => $s['jti']], 'headers' => $h]);
                assertEqual(403, $st2);
                assertEqual('perm_denied', $j2['error_code'] ?? null);
                // …and the link still works for its owner.
                [$st3] = req('GET', "{$B}/api/fm/share/info?token=" . rawurlencode($s['token']));
                assertEqual(200, $st3, 'not revoked by the stranger');
            });

            test('a token without allow_share cannot mint one (3rd gate layer)', function () use ($B, $PREFIX) {
                $plain = fluxfiles_token(['user' => 'op42', 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => $PREFIX, 'ttl' => 600]);
                [$st, , $j] = reqJson('POST', "{$B}/api/fm/share", [
                    'json' => ['disk' => 'local', 'path' => 'reports/q3.pdf'],
                    'headers' => ["Authorization: Bearer {$plain}"],
                ]);
                assertEqual(403, $st);
                assertEqual('allow_share_forbidden', $j['error_code'] ?? null);
            });

            test('brute force: unlock is rate-limited per share (429 rate_limited)', function () use ($mint, $B) {
                $s = $mint(['path' => 'reports/q3.pdf', 'password' => 'right']);
                $codes = [];
                for ($i = 0; $i < 5; $i++) {
                    [$st, , $j] = reqJson('POST', "{$B}/api/fm/share/unlock", ['json' => ['token' => $s['token'], 'password' => 'guess' . $i]]);
                    $codes[] = $st;
                    if ($st === 429) { assertEqual('rate_limited', $j['error_code'] ?? null, 'a translated code, not a bare 429'); }
                }
                assertEqual(401, $codes[0], 'first attempts are just wrong');
                assertTrue(in_array(429, $codes, true), 'the bucket closes: ' . json_encode($codes));
                // info/file use a separate, roomier bucket — a locked-out guesser hasn't
                // taken the link down for everyone.
                [$st] = req('GET', "{$B}/api/fm/share/info?token=" . rawurlencode($s['token']));
                assertEqual(200, $st, 'view bucket is independent');
            });

            test('brute force: rotating the forwarded client IP buys no extra attempts', function () use ($mint, $B) {
                // A proxy-pool attacker varies the client address. The limiter must key
                // on nothing the client can set — and the per-share ceiling
                // (FLUXFILES_SHARE_UNLOCK_TOTAL, 6 in this run) has no IP component at
                // all, so attempts stay bounded even if REMOTE_ADDR itself rotates.
                // (That IP-rotation case can't be produced over loopback; it's covered
                // deterministically in tests/integration/test-share-public.php.)
                $s = $mint(['path' => 'reports/q3.pdf', 'password' => 'right']);
                $codes = [];
                for ($i = 0; $i < 10; $i++) {
                    [$st] = req('POST', "{$B}/api/fm/share/unlock", [
                        'json' => ['token' => $s['token'], 'password' => 'guess' . $i],
                        'headers' => ['X-Forwarded-For: 203.0.113.' . $i, 'Forwarded: for=198.51.100.' . $i],
                    ]);
                    $codes[] = $st;
                }
                assertTrue(in_array(429, $codes, true), 'rotation must not raise the ceiling: ' . json_encode($codes));
                assertEqual(429, $codes[9], 'still closed at the end of the minute');
                assertTrue(count(array_filter($codes, fn ($c) => $c === 401)) <= 6,
                    'no more attempts than the total ceiling allows: ' . json_encode($codes));
            });

            test('analytics: share_analytics=true records a view + download over real HTTP; off by default', function () use ($mint, $B, $PREFIX, $opHdr) {
                $opAnalytics = fluxfiles_token(['user' => 'analyticsop', 'perms' => ['read', 'write'], 'disks' => ['local'], 'prefix' => $PREFIX,
                    'ttl' => 600, 'claims' => ['allow_share' => true, 'share_analytics' => true]]);
                [$st, , $j] = reqJson('POST', "{$B}/api/fm/share", [
                    'json' => ['disk' => 'local', 'path' => 'reports/q3.pdf'],
                    'headers' => ["Authorization: Bearer {$opAnalytics}"],
                ]);
                assertEqual(200, $st);
                $s = $j['data'];

                // Drive one view (the landing) + one download over real HTTP. curl sends
                // no User-Agent by default, so set one explicitly to exercise the `ua`
                // field end to end (PHP's built-in server never sees one otherwise).
                $uaHdr = ['headers' => ['User-Agent: FluxFiles-e2e-test/1.0']];
                [$stInfo] = req('GET', "{$B}/api/fm/share/info?token=" . rawurlencode($s['token']), $uaHdr);
                assertEqual(200, $stInfo);
                [$stFile] = req('GET', "{$B}/api/fm/share/file?token=" . rawurlencode($s['token']), $uaHdr);
                assertEqual(200, $stFile);

                [$stA, , $jA] = reqJson('GET', "{$B}/api/fm/share/analytics?disk=local&jti=" . rawurlencode($s['jti']),
                    ['headers' => ["Authorization: Bearer {$opAnalytics}"]]);
                assertEqual(200, $stA);
                assertEqual($s['jti'], $jA['data']['jti']);
                assertEqual(true, $jA['data']['analytics_enabled']);
                assertEqual(2, $jA['data']['total']);
                $types = array_column($jA['data']['events'], 'type');
                sort($types);
                assertEqual(['download', 'view'], $types);
                foreach ($jA['data']['events'] as $ev) {
                    assertTrue(is_string($ev['ip']), 'ip is a string (possibly empty over loopback)');
                    assertEqual('FluxFiles-e2e-test/1.0', $ev['ua'], 'the ua sent on the request was captured');
                    assertTrue($ev['ts'] > 0, 'a unix timestamp');
                }

                // event= filter narrows it.
                [, , $jDl] = reqJson('GET', "{$B}/api/fm/share/analytics?disk=local&jti=" . rawurlencode($s['jti']) . '&event=download',
                    ['headers' => ["Authorization: Bearer {$opAnalytics}"]]);
                assertEqual(1, $jDl['data']['total']);
                assertEqual('download', $jDl['data']['events'][0]['type']);

                // A share minted WITHOUT share_analytics: off by default, no events, but
                // still a clean 200 (never a bug-looking response).
                $s2 = $mint(['path' => 'reports/q3.pdf']);
                [$stOff, , $jOff] = reqJson('GET', "{$B}/api/fm/share/analytics?disk=local&jti=" . rawurlencode($s2['jti']), ['headers' => $opHdr]);
                assertEqual(200, $stOff);
                assertEqual(false, $jOff['data']['analytics_enabled']);
                assertEqual([], $jOff['data']['events']);
            });

            stop($srv3);
        }
    }
} finally {
    // .env FIRST: it's the developer's file, so it must be put back even if a later
    // cleanup step throws (proc_terminate() on a closed handle is a TypeError, not a
    // warning — @ won't save it).
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
