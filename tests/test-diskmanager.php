<?php

/**
 * Test script for DiskManager class.
 *
 * Usage:
 *   php tests/test-diskmanager.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

require_once __DIR__ . '/../embed.php';

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

test('registerByobDisk() clears cached instances', function () use (&$tempDirs) {
    $root = '/tmp/ff_test_dm_' . uniqid();
    $tempDirs[] = $root;
    mkdir($root, 0755, true);

    // Start with a local disk config, build it to cache
    $dm = new FluxFiles\DiskManager([
        'swap' => ['driver' => 'local', 'root' => $root],
    ]);
    $fs1 = $dm->disk('swap');

    // Now register as S3 — this should clear the cached instance
    $dm->registerByobDisk('swap', [
        'driver' => 's3', 'bucket' => 'b', 'key' => 'k', 'secret' => 's', 'region' => 'us-east-1',
    ]);

    // Config should reflect the new S3 config
    assertEqual('s3', $dm->config('swap')['driver']);

    // Fetching the disk again should build a new instance (not return the old local one)
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
