<?php

declare(strict_types=1);

/**
 * router.php is an ALLOWLIST: the built-in server must never fall through to "serve
 * whatever exists under the document root". Pre-launch audit: GET /.env returned the
 * secrets and GET /tests/generate-token.php printed a 24 h full-privilege JWT.
 *
 * Self-booting (own `php -S` on an ephemeral port); needs curl.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\JwtCompat;

if (!extension_loaded('curl')) {
    echo "SKIP: curl required\n"; exit(0);
}
$core = realpath(__DIR__ . '/../..');
$secret = str_repeat('router-allowlist-secret-', 2);
$probe = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($probe, false); fclose($probe);
$base = 'http://' . $address;

// Planted files: prove a script / dotfile under an ALLOWED tree is still refused, and that
// a `.env` exists to be exposed (a checkout without one would pass vacuously).
$planted = [];
$plant = function (string $rel, string $body) use ($core, &$planted): void {
    $path = $core . '/' . $rel;
    if (!file_exists($path)) {
        file_put_contents($path, $body);
        $planted[] = $path;
    }
};
$plant('.env', "FLUXFILES_SECRET=router-allowlist-planted-secret-value\n");
$plant('assets/zz-router-probe.php', "<?php echo 'EXECUTED';\n");
$plant('assets/.zz-router-dot', "dot secret\n");

$env = array_merge(getenv(), [
    'FLUXFILES_SECRET' => $secret,
    'FLUXFILES_RATE_LIMIT_READ' => '100000',
]);
$proc = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', $address, 'router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $core, $env);
if (!is_resource($proc)) { throw new RuntimeException('Could not start test server'); }

function get(string $path, array $headers = []): array
{
    global $base;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $headers]);
    $body = (string) curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$status, $body];
}
function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$passed = 0; $failed = 0;
function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try { $fn(); $passed++; echo "PASS $name\n"; }
    catch (Throwable $e) { $failed++; echo "FAIL $name: {$e->getMessage()}\n"; }
}

try {
    for ($i = 0; $i < 50; $i++) {
        $socket = @stream_socket_client('tcp://' . $address, $errno, $errstr, .2);
        if ($socket) { fclose($socket); break; }
        usleep(100000);
    }

    $denied = [
        '/.env', '/%2eenv', '/./.env', '/composer.json', '/composer.lock',
        '/tests/generate-token.php', '/tests/e2e/test-router-allowlist-http.php',
        '/scripts/import-metadata.php', '/scripts/', '/vendor/autoload.php', '/vendor/composer/installed.json',
        '/embed.php', '/router.php', '/autoload.php', '/bin/fluxfiles', '/config/disks.php',
        '/.env.example', '/lang/en.json', '/db/', '/public/../.env',
        '/assets/zz-router-probe.php', '/assets/.zz-router-dot', '/api.php', '/index.php',
    ];
    foreach ($denied as $path) {
        test("GET $path is refused", function () use ($path) {
            [$status, $body] = get($path);
            check(in_array($status, [400, 403, 404], true), "status $status");
            check(strpos($body, 'EXECUTED') === false, 'a script was executed');
            check(strpos($body, 'FLUXFILES_SECRET') === false && strpos($body, 'eyJ') === false, 'secret material in body');
        });
    }

    test('GET /public/ renders the UI', function () {
        [$status, $body] = get('/public/');
        check($status === 200 && strpos($body, '<html') !== false, "status $status");
    });
    test('GET /public/share.html (static stub) is served', function () {
        [$status] = get('/public/share.html');
        check($status === 200, "status $status");
    });
    test('GET /assets/fm.js is served', function () {
        [$status, $body] = get('/assets/fm.js');
        check($status === 200 && strlen($body) > 1000, "status $status");
    });
    test('GET /fluxfiles.js is served', function () {
        [$status] = get('/fluxfiles.js');
        check($status === 200, "status $status");
    });
    test('GET /favicon.ico is served', function () {
        [$status] = get('/favicon.ico');
        check($status === 200, "status $status");
    });
    test('GET /api/fm/lang still routes to the API', function () {
        [$status, $body] = get('/api/fm/lang');
        check($status === 200 && is_array(json_decode($body, true)), "status $status");
    });
    test('an authenticated /api/fm/list still works', function () use ($secret) {
        $token = JwtCompat::encode(['sub' => 'u1', 'iat' => time(), 'exp' => time() + 300,
            'perms' => ['read'], 'disks' => ['local'], 'prefix' => 'router-allowlist-probe'], $secret);
        [$status] = get('/api/fm/list?disk=local&path=', ['Authorization: Bearer ' . $token]);
        check($status === 200, "status $status");
    });
} finally {
    proc_terminate($proc); proc_close($proc);
    foreach ($planted as $path) { @unlink($path); }
}
echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
