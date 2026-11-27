<?php

/**
 * Malformed input answers 400 with an error_code, never a 500: non-scalar JSON
 * fields, array query parameters, control characters in a name, a missing field.
 * Self-booting (ephemeral port, own tenant prefix on the local disk).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\JwtCompat;

if (!extension_loaded('curl')) {
    echo "SKIP: curl required\n";
    exit(0);
}
$core = realpath(__DIR__ . '/../..');
$prefix = 'badinput-http-' . bin2hex(random_bytes(8));
$root = $core . '/storage/uploads/' . $prefix;
mkdir($root, 0777, true);
$secret = str_repeat('badinput-http-secret-', 3);
$probe = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($probe, false);
fclose($probe);
$base = 'http://' . $address;
$env = array_merge(getenv(), [
    'FLUXFILES_SECRET' => $secret,
    'FLUXFILES_RATE_LIMIT_READ' => '100000',
    'FLUXFILES_RATE_LIMIT_WRITE' => '100000',
]);
$proc = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', $address, 'router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $core, $env);
if (!is_resource($proc)) {
    throw new RuntimeException('Could not start test server');
}
$token = JwtCompat::encode([
    'sub' => 'alice', 'iat' => time(), 'exp' => time() + 300,
    'perms' => ['read', 'write', 'delete'], 'disks' => ['local'], 'prefix' => $prefix,
], $secret);
function request(string $method, string $path, ?string $rawBody = null): array
{
    global $base, $token;
    $ch = curl_init($base . '/api/fm/' . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json']]);
    if ($rawBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
    }
    $raw = curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode((string) $raw, true) ?? ['raw' => $raw]];
}
$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok ? '' : " — $detail") . "\n";
}
try {
    for ($i = 0; $i < 50; $i++) {
        $s = @stream_socket_client('tcp://' . $address, $errno, $errstr, .2);
        if ($s) {
            fclose($s);
            break;
        }
        usleep(100000);
    }
    $cases = [
        'array path in JSON'      => ['POST', 'mkdir', '{"disk":"local","path":["x"]}', 'invalid_param'],
        'object disk in JSON'     => ['POST', 'mkdir', '{"disk":{"a":1},"path":"x"}', 'invalid_param'],
        'missing field'           => ['POST', 'mkdir', '{"disk":"local"}', 'missing_param'],
        'newline in a folder name' => ['POST', 'mkdir', json_encode(['disk' => 'local', 'path' => "a\nb"]), 'name_invalid'],
        'too long folder name'    => ['POST', 'mkdir', json_encode(['disk' => 'local', 'path' => str_repeat('n', 300)]), 'name_too_long'],
        'array disk in the query' => ['GET', 'list?disk[]=x&path=', null, 'invalid_param'],
    ];
    foreach ($cases as $name => [$method, $path, $body, $want]) {
        [$status, $json] = request($method, $path, $body);
        check($name . ' -> 400 ' . $want, $status === 400 && ($json['error_code'] ?? null) === $want, "$status " . json_encode($json));
    }
    [$status, $json] = request('POST', 'mkdir', '{"disk":"local","path":"fine"}');
    check('a valid mkdir still works', $status === 200, "$status " . json_encode($json));
} finally {
    proc_terminate($proc);
    proc_close($proc);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
