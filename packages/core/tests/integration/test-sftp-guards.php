<?php

/**
 * SFTP disk guards (M3): operations that only make sense on S3 must reject an
 * SFTP disk cleanly (no connection is even attempted — they throw on the driver
 * check first), so no live server is needed.
 *
 * Usage: php tests/integration/test-sftp-guards.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\DiskManager;
use FluxFiles\ChunkUploader;
use FluxFiles\Claims;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\ApiException;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }

/** A FileManager + ChunkUploader over a (public-host, never-connected) SFTP disk. */
function sftpFM(): array
{
    $dm = new DiskManager(['sftp' => ['driver' => 'sftp', 'host' => 'github.com', 'username' => 'u', 'password' => 'p', 'root' => '/']]);
    $claims = new Claims('u', ['read', 'write', 'delete'], ['sftp'], '', 50, null, 0);
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    return [$fm, new ChunkUploader($dm)];
}

echo "\n{$cyan}══ SFTP disk guards (M3) ══{$reset}\n\n";

test('chunk multipart upload is rejected for an SFTP disk', function () {
    [, $cu] = sftpFM();
    try { $cu->initiate('sftp', 'big.bin'); throw new \RuntimeException('should reject'); }
    catch (ApiException $e) { assertEqual(400, $e->getCode(), 'chunk init → 400 (S3 only)'); }
});

test('GET presign is rejected for an SFTP disk (no presigned URLs)', function () {
    [$fm] = sftpFM();
    try { $fm->presign('sftp', 'a.bin', 'GET', 3600); throw new \RuntimeException('should reject'); }
    catch (ApiException $e) { assertEqual(400, $e->getCode(), 'presign GET → 400'); }
});

test('PUT presign is rejected for an SFTP disk', function () {
    [$fm] = sftpFM();
    try { $fm->presign('sftp', 'a.bin', 'PUT', 3600, 1000); throw new \RuntimeException('should reject'); }
    catch (ApiException $e) { assertEqual(400, $e->getCode(), 'presign PUT → 400'); }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
