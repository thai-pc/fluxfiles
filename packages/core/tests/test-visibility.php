<?php

/**
 * Test script for S3/R2 object visibility → URL generation.
 *
 * Verifies FileManager::fileUrl():
 *   - private disks (default) return a presigned GET URL (works on private buckets)
 *   - public disks return a direct URL (bucket/endpoint URL, or public_url when set)
 *   - local disks always return the configured base URL
 *
 * Usage:
 *   php tests/test-visibility.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

foreach ([__DIR__ . "/..", __DIR__ . "/../../.."] as $envDir) {
    if (is_file($envDir . "/.env")) {
        Dotenv\Dotenv::createImmutable($envDir)->safeLoad();
        break;
    }
}

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\MetadataRepositoryInterface;

$green = "\033[32m";
$red   = "\033[31m";
$cyan  = "\033[36m";
$reset = "\033[0m";

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

function assertContains(string $needle, string $haystack, string $msg = ''): void
{
    if (strpos($haystack, $needle) === false) {
        throw new \RuntimeException($msg ?: "Expected to find '{$needle}' in '{$haystack}'");
    }
}

function assertNotContains(string $needle, string $haystack, string $msg = ''): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new \RuntimeException($msg ?: "Did not expect '{$needle}' in '{$haystack}'");
    }
}

/** Minimal no-op metadata repo (URL generation does not touch metadata). */
class NullMetaRepo implements MetadataRepositoryInterface
{
    public function get(string $disk, string $key): ?array { return null; }
    public function save(string $disk, string $key, array $data): void {}
    public function delete(string $disk, string $key): void {}
    public function deleteChildren(string $disk, string $prefix): int { return 0; }
    public function renameChildren(string $disk, string $oldPrefix, string $newPrefix): int { return 0; }
    public function getBulk(string $disk, array $keys): array { return []; }
    public function search(string $disk, string $query, int $limit = 50, string $pathPrefix = ''): array { return []; }
    public function saveHash(string $disk, string $key, string $hash): void {}
    public function findByHash(string $disk, string $hash, string $pathPrefix = '', ?string $ownerUserId = null): ?array { return null; }
    public function syncToS3Tags(string $disk, string $key, array $data, DiskManager $diskManager): void {}
    public function countChildren(string $disk, string $prefix): int { return 0; }
}

/** Build a FileManager over the given disk configs. */
function makeFM(array $diskConfigs): FileManager
{
    $dm = new DiskManager($diskConfigs);
    $claims = new Claims('u1', ['read', 'write'], array_keys($diskConfigs), '', 10, null, 0, false);
    return new FileManager($dm, $claims, new NullMetaRepo());
}

/** Call the private fileUrl() via reflection. */
function fileUrl(FileManager $fm, string $disk, string $path): string
{
    $ref = new ReflectionMethod($fm, 'fileUrl');
    $ref->setAccessible(true);
    return (string) $ref->invoke($fm, $disk, $path);
}

$DUMMY = [
    'key'    => 'AKIAIOSFODNN7EXAMPLE',
    'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
];

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "  FluxFiles Visibility / URL Test Suite\n";
echo "{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

echo "► Local disk\n";
test('local disk returns configured base URL', function () {
    $fm = makeFM(['local' => ['driver' => 'local', 'root' => sys_get_temp_dir() . '/fx-' . uniqid(), 'url' => '/storage/uploads']]);
    assertContains('/storage/uploads/photos/a.jpg', fileUrl($fm, 'local', 'photos/a.jpg'));
});

echo "\n► AWS S3 (no endpoint)\n";
test('s3 private → presigned GET URL (has signature)', function () use ($DUMMY) {
    $fm = makeFM(['s3' => ['driver' => 's3', 'region' => 'us-east-1', 'bucket' => 'my-bucket', 'visibility' => 'private'] + $DUMMY]);
    $url = fileUrl($fm, 's3', 'photos/a.jpg');
    assertContains('X-Amz-Signature', $url, 'private S3 URL must be presigned');
    assertContains('my-bucket', $url);
});

test('s3 default (no visibility key) → presigned (private is the default)', function () use ($DUMMY) {
    $fm = makeFM(['s3' => ['driver' => 's3', 'region' => 'us-east-1', 'bucket' => 'my-bucket'] + $DUMMY]);
    assertContains('X-Amz-Signature', fileUrl($fm, 's3', 'photos/a.jpg'));
});

test('s3 public → direct bucket URL (no signature)', function () use ($DUMMY) {
    $fm = makeFM(['s3' => ['driver' => 's3', 'region' => 'eu-west-1', 'bucket' => 'my-bucket', 'visibility' => 'public'] + $DUMMY]);
    $url = fileUrl($fm, 's3', 'photos/a.jpg');
    assertNotContains('X-Amz-Signature', $url);
    assertContains('https://my-bucket.s3.eu-west-1.amazonaws.com/photos/a.jpg', $url);
});

test('s3 public + public_url → CDN base, no signature', function () use ($DUMMY) {
    $fm = makeFM(['s3' => ['driver' => 's3', 'region' => 'us-east-1', 'bucket' => 'my-bucket', 'visibility' => 'public', 'public_url' => 'https://cdn.example.com'] + $DUMMY]);
    $url = fileUrl($fm, 's3', 'photos/a.jpg');
    assertNotContains('X-Amz-Signature', $url);
    assertContains('https://cdn.example.com/photos/a.jpg', $url);
});

echo "\n► Cloudflare R2 (endpoint, path-style)\n";
test('r2 private → presigned GET URL', function () use ($DUMMY) {
    $fm = makeFM(['r2' => ['driver' => 's3', 'endpoint' => 'https://acc.r2.cloudflarestorage.com', 'region' => 'auto', 'bucket' => 'r2-bucket', 'visibility' => 'private'] + $DUMMY]);
    $url = fileUrl($fm, 'r2', 'photos/a.jpg');
    assertContains('X-Amz-Signature', $url, 'private R2 URL must be presigned');
});

test('r2 public + public_url → custom domain, no signature', function () use ($DUMMY) {
    $fm = makeFM(['r2' => ['driver' => 's3', 'endpoint' => 'https://acc.r2.cloudflarestorage.com', 'region' => 'auto', 'bucket' => 'r2-bucket', 'visibility' => 'public', 'public_url' => 'https://media.example.com'] + $DUMMY]);
    $url = fileUrl($fm, 'r2', 'photos/a.jpg');
    assertNotContains('X-Amz-Signature', $url);
    assertContains('https://media.example.com/photos/a.jpg', $url);
});

test('r2 public WITHOUT public_url → falls back to endpoint/bucket URL', function () use ($DUMMY) {
    $fm = makeFM(['r2' => ['driver' => 's3', 'endpoint' => 'https://acc.r2.cloudflarestorage.com', 'region' => 'auto', 'bucket' => 'r2-bucket', 'visibility' => 'public'] + $DUMMY]);
    $url = fileUrl($fm, 'r2', 'photos/a.jpg');
    assertNotContains('X-Amz-Signature', $url);
    assertContains('https://acc.r2.cloudflarestorage.com/r2-bucket/photos/a.jpg', $url);
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
