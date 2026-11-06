<?php

/**
 * A broken FLUXFILES_DISKS_CONFIG over HTTP: anonymous /img and /stream and an
 * authenticated /list must all answer a generic 500 that leaks neither the
 * operator's path nor any content of their file (a parse error quotes a token
 * from it), and must never fall back to the default `local` disk.
 *
 * Self-booting (ephemeral port).
 *
 * Usage:
 *   php tests/e2e/test-disks-config-http.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\JwtCompat;

if (!extension_loaded('curl')) {
    echo "SKIP: curl required\n"; exit(0);
}
$core = realpath(__DIR__ . '/../..');
$secret = str_repeat('disks-config-http-', 3);
$tmp = sys_get_temp_dir() . '/fx-dcfg-http-' . bin2hex(random_bytes(4));
mkdir($tmp);

$missing = $tmp . '/does-not-exist-PATHMARKER.php';
$syntax = $tmp . '/syntax-PATHMARKER.php';
file_put_contents($syntax, "<?php\nreturn ['local' => ['driver' => 'local', 'root' => '/tmp'] FAKESECRETFRAGMENT];\n");
$noisy = $tmp . '/noisy-PATHMARKER.php';
file_put_contents($noisy, "<?php echo 'NOISYCONTENT'; return ['local' => ['driver' => 'local', 'root' => '/tmp']];\n");

$probe = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($probe, false); fclose($probe);
$base = 'http://' . $address;

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$passed = 0; $failed = 0;
function test(string $name, callable $fn): void {
    global $passed, $failed;
    try { $fn(); $passed++; echo "PASS $name\n"; }
    catch (Throwable $e) { $failed++; echo "FAIL $name: {$e->getMessage()}\n"; }
}
function get(string $url, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $headers]);
    $raw = (string) curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$status, $raw];
}

function boot(string $core, string $address, string $secret, string $configPath)
{
    $env = array_merge(getenv(), [
        'FLUXFILES_SECRET' => $secret,
        'FLUXFILES_DISKS_CONFIG' => $configPath,
        'FLUXFILES_RATE_LIMIT_READ' => '100000',
    ]);
    $proc = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', $address, 'router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $core, $env);
    if (!is_resource($proc)) throw new RuntimeException('Could not start test server');
    for ($i = 0; $i < 50; $i++) {
        $s = @fsockopen('127.0.0.1', (int) substr($address, strrpos($address, ':') + 1));
        if ($s) { fclose($s); break; }
        usleep(100000);
    }
    return $proc;
}

$cases = ['missing file' => $missing, 'syntax error file' => $syntax, 'file that prints output' => $noisy];
$jwt = JwtCompat::encode([
    'sub' => 'u1', 'iat' => time(), 'exp' => time() + 300,
    'perms' => ['read'], 'disks' => ['local'], 'prefix' => '',
], $secret);

foreach ($cases as $label => $path) {
    $proc = boot($core, $address, $secret, $path);
    try {
        foreach ([
            'anonymous /img' => ['/api/fm/img', []],
            'anonymous /stream' => ['/api/fm/stream', []],
            'authenticated /list' => ['/api/fm/list?disk=local&path=', ['Authorization: Bearer ' . $jwt]],
        ] as $name => [$route, $headers]) {
            test("$label: $name is a generic 500", function () use ($base, $route, $headers, $tmp) {
                [$status, $body] = get($base . $route, $headers);
                check($status === 500, "expected 500, got $status: $body");
                $j = json_decode($body, true);
                check(is_array($j) && ($j['error'] ?? null) === 'Server disk configuration is invalid', 'generic envelope expected: ' . $body);
                check(array_key_exists('data', $j) && $j['data'] === null, 'no data (no fallback to local): ' . $body);
                foreach (['PATHMARKER', $tmp, 'FAKESECRETFRAGMENT', 'NOISYCONTENT', 'disks.php', 'syntax error'] as $leak) {
                    check(!str_contains($body, $leak), "body leaks '$leak': $body");
                }
            });
        }
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
}

array_map('unlink', glob($tmp . '/*.php'));
rmdir($tmp);
echo "\nTotal: " . ($passed + $failed) . "  Passed: $passed  Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
