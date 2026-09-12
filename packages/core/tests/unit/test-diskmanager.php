<?php

/**
 * Test script for DiskManager class.
 *
 * Usage:
 *   php tests/test-diskmanager.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

foreach ([__DIR__ . "/../..", __DIR__ . "/../../../.."] as $envDir) {
    if (is_file($envDir . "/.env")) {
        Dotenv\Dotenv::createImmutable($envDir)->safeLoad();
        break;
    }
}

require_once __DIR__ . '/../../embed.php';

$green  = "\033[32m";
$red    = "\033[31m";
$yellow = "\033[33m";
$cyan   = "\033[36m";
$reset  = "\033[0m";

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try {
        $fn();
        echo "  {$green}PASS{$reset} {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

function assertEqual($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            $msg ?: "Expected " . json_encode($expected) . " but got " . json_encode($actual)
        );
    }
}

// Track temp directories for cleanup
$tempDirs = [];

echo "\n{$cyan}╔══════════════════════════════════════════════════╗{$reset}\n";
echo "{$cyan}║      FluxFiles DiskManager Test Suite            ║{$reset}\n";
echo "{$cyan}╚══════════════════════════════════════════════════╝{$reset}\n\n";

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► Build local disk{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('Build local disk - creates Filesystem instance', function () use (&$tempDirs) {
    $root = '/tmp/ff_test_dm_' . uniqid();
    $tempDirs[] = $root;
    mkdir($root, 0755, true);

    $dm = new FluxFiles\DiskManager([
        'local' => ['driver' => 'local', 'root' => $root],
    ]);
    $fs = $dm->disk('local');
    assertEqual(true, $fs instanceof League\Flysystem\Filesystem);
});

test('Build local disk - creates directory if not exists', function () use (&$tempDirs) {
    $root = '/tmp/ff_test_dm_' . uniqid() . '/nested/dir';
    $tempDirs[] = '/tmp/ff_test_dm_' . basename(dirname(dirname($root)));

    // The parent should not exist yet
    assertEqual(false, is_dir($root));

    $dm = new FluxFiles\DiskManager([
        'local' => ['driver' => 'local', 'root' => $root],
    ]);
    $dm->disk('local');

    assertEqual(true, is_dir($root));
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► Disk caching{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('Disk caching - same instance returned on second call', function () use (&$tempDirs) {
    $root = '/tmp/ff_test_dm_' . uniqid();
    $tempDirs[] = $root;
    mkdir($root, 0755, true);

    $dm = new FluxFiles\DiskManager([
        'local' => ['driver' => 'local', 'root' => $root],
    ]);
    $fs1 = $dm->disk('local');
    $fs2 = $dm->disk('local');
    assertEqual(true, $fs1 === $fs2, 'Expected the same Filesystem instance on second call');
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► Error handling{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('Unknown disk name throws ApiException 400', function () {
    $dm = new FluxFiles\DiskManager([]);
    try {
        $dm->disk('nonexistent');
        throw new \RuntimeException('Should have thrown');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(400, $e->getCode());
    }
});

test('Unknown driver throws ApiException 400', function () use (&$tempDirs) {
    $dm = new FluxFiles\DiskManager([
        'bad' => ['driver' => 'ftp', 'root' => '/tmp'],
    ]);
    try {
        $dm->disk('bad');
        throw new \RuntimeException('Should have thrown');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(400, $e->getCode());
    }
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► config() method{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('config() returns config array for known disk', function () {
    $cfg = ['driver' => 'local', 'root' => '/tmp/whatever'];
    $dm = new FluxFiles\DiskManager(['mydisk' => $cfg]);
    $result = $dm->config('mydisk');
    assertEqual($cfg, $result);
});

test('config() returns empty array for unknown disk', function () {
    $dm = new FluxFiles\DiskManager([]);
    $result = $dm->config('nope');
    assertEqual([], $result);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► s3Client() method{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('s3Client() throws ApiException 400 for local disk', function () use (&$tempDirs) {
    $root = '/tmp/ff_test_dm_' . uniqid();
    $tempDirs[] = $root;
    mkdir($root, 0755, true);

    $dm = new FluxFiles\DiskManager([
        'local' => ['driver' => 'local', 'root' => $root],
    ]);
    try {
        $dm->s3Client('local');
        throw new \RuntimeException('Should have thrown');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(400, $e->getCode());
    }
});

test('presignGetUrl() returns null for a local (non-S3) disk', function () use (&$tempDirs) {
    $root = '/tmp/ff_test_dm_' . uniqid();
    $tempDirs[] = $root;
    mkdir($root, 0755, true);
    $dm = new FluxFiles\DiskManager(['local' => ['driver' => 'local', 'root' => $root]]);
    assertEqual(null, $dm->presignGetUrl('local', '_variants/x.webp', 3600));
    assertEqual(null, $dm->presignGetUrl('missing', 'x.webp', 3600)); // unknown disk → null, no throw
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► registerByobDisk(){$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('registerByobDisk() adds new disk config', function () {
    $dm = new FluxFiles\DiskManager([]);
    $dm->registerByobDisk('my-s3', [
        'driver' => 's3', 'bucket' => 'b', 'key' => 'k', 'secret' => 's', 'region' => 'us-east-1',
    ]);
    assertEqual('s3', $dm->config('my-s3')['driver']);
    assertEqual('b', $dm->config('my-s3')['bucket']);
});

test('registerByobDisk() rejects local driver with 403', function () {
    $dm = new FluxFiles\DiskManager([]);
    try {
        $dm->registerByobDisk('evil', ['driver' => 'local', 'root' => '/etc']);
        throw new \RuntimeException('Should have thrown');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(403, $e->getCode());
    }
});

test('registerByobDisk() cannot shadow a statically configured disk name', function () {
    $dm = new FluxFiles\DiskManager([
        's3' => ['driver' => 's3', 'bucket' => 'operator-bucket', 'key' => 'op-key', 'secret' => 'op-secret', 'region' => 'us-east-1'],
    ]);
    $before = $dm->config('s3');

    try {
        $dm->registerByobDisk('s3', [
            'driver' => 's3', 'bucket' => 'attacker-bucket', 'key' => 'evil-key', 'secret' => 'evil-secret', 'region' => 'us-east-1',
        ]);
        throw new \RuntimeException('Should have thrown');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(403, $e->getCode());
        assertEqual('byob_disk_collision', $e->getErrorCode());
    }

    // The static config must be provably untouched even on the throw path.
    assertEqual($before, $dm->config('s3'), 'Static disk config must not mutate when the collision is rejected');
});

test('registerByobDisk() still allows a brand-new BYOB name alongside static disks', function () {
    $dm = new FluxFiles\DiskManager([
        's3' => ['driver' => 's3', 'bucket' => 'operator-bucket', 'key' => 'op-key', 'secret' => 'op-secret', 'region' => 'us-east-1'],
    ]);

    $dm->registerByobDisk('tenant-42', [
        'driver' => 's3', 'bucket' => 'tenant-bucket', 'key' => 'k', 'secret' => 's', 'region' => 'us-east-1',
    ]);

    assertEqual('tenant-bucket', $dm->config('tenant-42')['bucket']);
    assertEqual('operator-bucket', $dm->config('s3')['bucket'], 'Registering an unrelated BYOB name must not touch the static disk');
});

test('registerByobDisk() clears cached instances', function () {
    // 'swap' is not part of the static config set, so re-registering it under a
    // fresh BYOB config on a later request is the allowed case (only a name from
    // the ORIGINAL static config is protected — see the collision test above).
    $dm = new FluxFiles\DiskManager([]);
    $dm->registerByobDisk('swap', [
        'driver' => 's3', 'bucket' => 'b1', 'key' => 'k', 'secret' => 's', 'region' => 'us-east-1',
    ]);
    $fs1 = $dm->disk('swap');

    // Now register a different S3 config under the same BYOB name — this should
    // clear the cached instance.
    $dm->registerByobDisk('swap', [
        'driver' => 's3', 'bucket' => 'b2', 'key' => 'k', 'secret' => 's', 'region' => 'us-east-1',
    ]);

    // Config should reflect the new S3 config
    assertEqual('b2', $dm->config('swap')['bucket']);

    // Fetching the disk again should build a new instance (not return the old one)
    $fs2 = $dm->disk('swap');
    assertEqual(false, $fs1 === $fs2, 'Expected a different instance after registerByobDisk');
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► S3 disk with endpoint{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('S3 disk with endpoint creates Filesystem without error (retain_visibility=false)', function () {
    $dm = new FluxFiles\DiskManager([
        'r2' => [
            'driver'   => 's3',
            'endpoint' => 'https://abc123.r2.cloudflarestorage.com',
            'bucket'   => 'my-bucket',
            'key'      => 'test-key',
            'secret'   => 'test-secret',
            'region'   => 'auto',
        ],
    ]);

    // Should create the Filesystem without throwing
    $fs = $dm->disk('r2');
    assertEqual(true, $fs instanceof League\Flysystem\Filesystem);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► SFTP driver{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('SFTP disk builds a Filesystem to a public host (lazy connection)', function () {
    // github.com resolves to a public IP; the adapter is built without connecting.
    $dm = new FluxFiles\DiskManager([
        'sftp' => ['driver' => 'sftp', 'host' => 'github.com', 'username' => 'u', 'password' => 'p', 'root' => '/'],
    ]);
    $fs = $dm->disk('sftp');
    assertEqual(true, $fs instanceof League\Flysystem\Filesystem, 'sftp → Filesystem');
});

test('SFTP disk to a private/metadata host is rejected by the SSRF guard', function () {
    foreach (['169.254.169.254', '127.0.0.1', '10.0.0.5', 'localhost'] as $bad) {
        $dm = new FluxFiles\DiskManager([
            'sftp' => ['driver' => 'sftp', 'host' => $bad, 'username' => 'u', 'password' => 'p'],
        ]);
        try {
            $dm->disk('sftp');
            throw new \RuntimeException("Should have blocked $bad");
        } catch (FluxFiles\ApiException $e) {
            assertEqual('ssrf_blocked', $e->getErrorCode(), "blocked $bad");
        }
    }
});

test('SFTP disk without a host → clean config error', function () {
    $dm = new FluxFiles\DiskManager(['sftp' => ['driver' => 'sftp', 'username' => 'u']]);
    try {
        $dm->disk('sftp');
        throw new \RuntimeException('Should have thrown');
    } catch (FluxFiles\ApiException $e) {
        assertEqual('sftp_config', $e->getErrorCode());
    }
});

// ═══════════════════════════════════════════════════════════════
// Cleanup
// ═══════════════════════════════════════════════════════════════

foreach ($tempDirs as $dir) {
    if (is_dir($dir)) {
        // Remove directory recursively
        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir);
    }
}

// ═══════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "{$cyan}  Results: {$green}{$passed} passed{$reset}";
if ($failed > 0) {
    echo ", {$red}{$failed} failed{$reset}";
}
echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
