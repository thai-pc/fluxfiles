<?php

/**
 * Live SFTP round-trip test — env-gated, skips cleanly when no server is given.
 * Proves the SFTP disk driver against a real server (write → list → read →
 * delete) through Flysystem, exactly as upload/download/list would use it.
 *
 * Required env (skips if FXTEST_SFTP_HOST is empty):
 *   FXTEST_SFTP_HOST        sftp host
 *   FXTEST_SFTP_PORT        port (default 22)
 *   FXTEST_SFTP_USERNAME    username
 *   FXTEST_SFTP_PASSWORD    password (or use FXTEST_SFTP_PRIVATE_KEY)
 *   FXTEST_SFTP_PRIVATE_KEY path to a private key file (alternative auth)
 *   FXTEST_SFTP_ROOT        remote root dir (default '.')
 *   FXTEST_SFTP_ALLOW_HOST  set to bypass the SSRF guard for a 127.0.0.1 test box
 *
 * Example (a local docker container `atmoz/sftp` on 127.0.0.1:2222):
 *   FXTEST_SFTP_HOST=127.0.0.1 FXTEST_SFTP_PORT=2222 FXTEST_SFTP_USERNAME=ff \
 *   FXTEST_SFTP_PASSWORD=ffpass FXTEST_SFTP_ROOT=upload FXTEST_SFTP_ALLOW_HOST=1 \
 *   php tests/e2e/test-sftp-live.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\DiskManager;
use FluxFiles\SsrfGuard;

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $cyan = "\033[36m"; $reset = "\033[0m";

$host = getenv('FXTEST_SFTP_HOST') ?: '';
if ($host === '') {
    echo "{$yellow}SKIP{$reset} test-sftp-live.php — set FXTEST_SFTP_HOST to run the live SFTP round-trip.\n";
    exit(0);
}

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void {
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

// Local test boxes (127.0.0.1) are blocked by the SSRF guard; opt in explicitly.
if (getenv('FXTEST_SFTP_ALLOW_HOST')) {
    SsrfGuard::$allowTestHosts[] = strtolower($host);
}

$keyPath = getenv('FXTEST_SFTP_PRIVATE_KEY') ?: '';
$cfg = [
    'driver'      => 'sftp',
    'host'        => $host,
    'port'        => (int) (getenv('FXTEST_SFTP_PORT') ?: 22),
    'username'    => getenv('FXTEST_SFTP_USERNAME') ?: '',
    'password'    => getenv('FXTEST_SFTP_PASSWORD') ?: '',
    'private_key' => $keyPath !== '' && is_file($keyPath) ? (string) file_get_contents($keyPath) : '',
    'root'        => getenv('FXTEST_SFTP_ROOT') ?: '.',
];

echo "\n{$cyan}══ Live SFTP round-trip ({$host}) ══{$reset}\n\n";

$dm = new DiskManager(['sftp' => $cfg]);
$fs = $dm->disk('sftp');
$key = 'fluxfiles-sftp-test-' . bin2hex(random_bytes(4)) . '.txt';
$body = 'hello from FluxFiles SFTP ' . time();

test('writeStream uploads a file', function () use ($fs, $key, $body) {
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $body); rewind($stream);
    $fs->writeStream($key, $stream);
    if (is_resource($stream)) { fclose($stream); }
    assertTrue($fs->fileExists($key), 'file exists after write');
});

test('listContents shows the uploaded file with its size', function () use ($fs, $key, $body) {
    $found = null;
    foreach ($fs->listContents('', false) as $item) {
        if ($item->isFile() && basename($item->path()) === $key) { $found = $item; }
    }
    assertTrue($found !== null, 'file listed');
    assertEqual(strlen($body), $found->fileSize(), 'size matches');
});

test('readStream returns the exact bytes', function () use ($fs, $key, $body) {
    $got = stream_get_contents($fs->readStream($key));
    assertEqual($body, $got, 'round-trip bytes match');
});

test('chmod: getMode reads, setMode writes a Unix mode (cPanel-style)', function () use ($dm, $cfg, $key) {
    $claims = new \FluxFiles\Claims('u', ['read', 'write'], ['sftp'], '', 50, null, 0);
    $fm = new \FluxFiles\FileManager($dm, $claims, new \FluxFiles\StorageMetadataHandler($dm));

    $start = $fm->getMode('sftp', $key);
    assertTrue(preg_match('/^[0-7]{3}$/', $start['mode']) === 1, 'mode is octal: ' . $start['mode']);

    $set = $fm->setMode('sftp', $key, '700');
    assertEqual('700', $set['mode'], 'setMode returns the new mode');
    assertEqual('700', $fm->getMode('sftp', $key)['mode'], 'getMode reflects the change');

    $fm->setMode('sftp', $key, '0644');     // leading-zero form accepted
    assertEqual('644', $fm->getMode('sftp', $key)['mode'], 'back to 644');
});

test('chmod: invalid mode → 422', function () use ($dm, $key) {
    $claims = new \FluxFiles\Claims('u', ['read', 'write'], ['sftp'], '', 50, null, 0);
    $fm = new \FluxFiles\FileManager($dm, $claims, new \FluxFiles\StorageMetadataHandler($dm));
    foreach (['999', 'abc', '', '7777', '64'] as $bad) {
        try { $fm->setMode('sftp', $key, $bad); throw new \RuntimeException("should reject $bad"); }
        catch (\FluxFiles\ApiException $e) { assertEqual('invalid_mode', $e->getErrorCode(), "rejected $bad"); }
    }
});

test('delete removes the file', function () use ($fs, $key) {
    $fs->delete($key);
    assertTrue(!$fs->fileExists($key), 'gone after delete');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
