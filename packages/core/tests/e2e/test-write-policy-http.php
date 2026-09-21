<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\JwtCompat;
use FluxFiles\Db\{Connection, MigrationRunner};

if (!extension_loaded('pdo_sqlite') || !extension_loaded('curl')) {
    echo "SKIP: pdo_sqlite and curl required\n"; exit(0);
}
$core = realpath(__DIR__ . '/../..');
$prefix = 'policy-http-' . bin2hex(random_bytes(8));
$root = $core . '/storage/uploads/' . $prefix;
mkdir($root, 0777, true);
file_put_contents($root . '/alice.txt', 'original');
file_put_contents($root . '/bob.txt', 'bob original');
$dbFile = tempnam(sys_get_temp_dir(), 'ff-policy-db');
$db = new Connection('sqlite:' . $dbFile);
(new MigrationRunner($db))->migrate($core . '/db/migrations');
$secret = str_repeat('policy-http-secret-', 3);
$probe = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($probe, false); fclose($probe);
$base = 'http://' . $address;
$env = array_merge(getenv(), [
    'FLUXFILES_SECRET' => $secret,
    'FLUXFILES_STORAGE_BACKEND' => 'db',
    'FLUXFILES_DB_DSN' => 'sqlite:' . $dbFile,
    'FLUXFILES_RATE_LIMIT_READ' => '100000',
    'FLUXFILES_RATE_LIMIT_WRITE' => '100000',
]);
$proc = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', $address, 'router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $core, $env);
if (!is_resource($proc)) throw new RuntimeException('Could not start test server');
function token(string $user, array $extra = []): string {
    global $prefix, $secret;
    return JwtCompat::encode(array_merge([
        'sub' => $user, 'iat' => time(), 'exp' => time() + 300,
        'perms' => ['read', 'write', 'delete'], 'disks' => ['local'], 'prefix' => $prefix,
        'owner_only' => true, 'allow_code_edit' => true, 'max_storage' => 1,
    ], $extra), $secret);
}
function request(string $method, string $path, string $token, ?array $body = null): array {
    global $base;
    $ch = curl_init($base . '/api/fm/' . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json']]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$status, json_decode((string) $raw, true) ?? ['raw' => $raw]];
}
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$passed = 0; $failed = 0;
function test(string $name, callable $fn): void {
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
    test('HTTP original content respects preview-only', function () {
        [$status, $json] = request('GET', 'content?disk=local&path=alice.txt', token('alice', ['allow_download' => false]));
        check($status === 403 && $json['error_code'] === 'download_forbidden', 'preview-only content was not refused');
    });
    test('HTTP editor cannot exceed storage quota', function () use ($root) {
        [$status, $json] = request('PUT', 'content', token('alice'), ['disk' => 'local', 'path' => 'alice.txt', 'content' => str_repeat('x', 2 * 1048576)]);
        check($status === 413 && $json['error_code'] === 'quota_exceeded', 'quota was not enforced');
        check(file_get_contents($root . '/alice.txt') === 'original', 'failed edit changed bytes');
    });
    test('HTTP import cannot forge, clear or steal ownership even without a title', function () use ($prefix, $root) {
        [$status, $json] = request('POST', 'metadata/import', token('bob'), ['disk' => 'local', 'entries' => [['path' => $prefix . '/bob.txt', 'owner' => null]]]);
        check($status === 200 && ($json['data']['imported'] ?? null) === 1, 'owner import setup failed: ' . json_encode($json));
        [$status, $json] = request('GET', 'metadata/export?disk=local', token('bob'));
        check($status === 200 && ($json['path'] ?? null) === $prefix . '/bob.txt' && ($json['owner'] ?? null) === 'bob', 'DB export did not preserve scoped ownership');
        [$status, $json] = request('POST', 'metadata/import', token('alice'), ['disk' => 'local', 'entries' => [
            ['path' => $prefix . '/new.txt', 'title' => 'must not be written'],
            ['path' => $prefix . '/bob.txt', 'owner' => 'alice'],
        ]]);
        check($status === 422 && $json['error_code'] === 'metadata_import_rejected', 'ownership import bypass: ' . $status . ' ' . json_encode($json));
        [$status] = request('PUT', 'content', token('alice'), ['disk' => 'local', 'path' => 'bob.txt', 'content' => 'stolen']);
        check($status === 403 && file_get_contents($root . '/bob.txt') === 'bob original', 'owner-only lost after import');
        [$status, $json] = request('GET', 'metadata?disk=local&key=' . $prefix . '/new.txt', token('alice'));
        check($status === 200 && $json['data'] === null, 'rejected batch wrote an earlier row');
    });
    test('HTTP import rejects internal and foreign-tenant paths', function () use ($prefix) {
        foreach ([$prefix . '/_fluxfiles/index.json', 'other-tenant/file.txt'] as $path) {
            [$status, $json] = request('POST', 'metadata/import', token('alice'), ['disk' => 'local', 'entries' => [['path' => $path]]]);
            check($status === 422, 'unsafe import path accepted: ' . $status . ' ' . json_encode($json));
        }
    });
} finally {
    proc_terminate($proc); proc_close($proc);
    $db = null;
    foreach ([$dbFile, $dbFile . '-wal', $dbFile . '-shm'] as $path) if (is_file($path)) unlink($path);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($root);
}
echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
