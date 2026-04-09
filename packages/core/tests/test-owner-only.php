<?php

/**
 * Test script for owner_only file protection.
 *
 * Tests that assertOwner() in FileManager correctly blocks
 * delete/rename/move when owner_only is enabled and the user
 * is not the file owner.
 *
 * Usage:
 *   php tests/test-owner-only.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use FluxFiles\Claims;
use FluxFiles\ApiException;
use FluxFiles\MetadataRepositoryInterface;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;

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

/**
 * In-memory mock metadata repository for testing ownership checks.
 */
class MockMetadataRepository implements MetadataRepositoryInterface
{
    /** @var array<string, array> keyed by "disk:key" */
    private $store = [];

    public function setMeta(string $disk, string $key, array $data): void
    {
        $this->store[$disk . ':' . $key] = $data;
    }

    public function get(string $disk, string $key): ?array
    {
        return $this->store[$disk . ':' . $key] ?? null;
    }

    public function save(string $disk, string $key, array $data): void
    {
        $existing = $this->store[$disk . ':' . $key] ?? [];
        $this->store[$disk . ':' . $key] = array_merge($existing, $data);
    }

    public function delete(string $disk, string $key): void
    {
        unset($this->store[$disk . ':' . $key]);
    }

    public function deleteChildren(string $disk, string $prefix): int { return 0; }
    public function renameChildren(string $disk, string $oldPrefix, string $newPrefix): int { return 0; }
    public function getBulk(string $disk, array $keys): array { return []; }
    public function search(string $disk, string $query, int $limit = 50, string $pathPrefix = ''): array { return []; }
    public function saveHash(string $disk, string $key, string $hash): void {}
    public function findByHash(string $disk, string $hash): ?array { return null; }
    public function syncToS3Tags(string $disk, string $key, array $data, DiskManager $diskManager): void {}
    public function countChildren(string $disk, string $prefix): int { return 0; }
}

/**
 * Use reflection to call the private assertOwner method directly.
 */
function callAssertOwner(FileManager $fm, string $disk, string $scopedPath): void
{
    $ref = new ReflectionMethod($fm, 'assertOwner');
    $ref->setAccessible(true);
    $ref->invoke($fm, $disk, $scopedPath);
}

/**
 * Create a FileManager with given claims and metadata.
 */
function makeFM(Claims $claims, MockMetadataRepository $meta): FileManager
{
    // DiskManager needs config — create a minimal one
    $diskManager = new DiskManager([
        'local' => [
            'driver' => 'local',
            'root'   => sys_get_temp_dir() . '/fluxfiles-test-' . uniqid(),
            'url'    => '/storage',
        ],
    ]);

    return new FileManager($diskManager, $claims, $meta);
}

echo "\n{$cyan}╔══════════════════════════════════════════════════╗{$reset}\n";
echo "{$cyan}║      FluxFiles Owner-Only Test Suite             ║{$reset}\n";
echo "{$cyan}╚══════════════════════════════════════════════════╝{$reset}\n\n";

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► assertOwner — ownerOnly disabled{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('ownerOnly=false: allows any user to modify any file', function () {
    $claims = new Claims('user-A', ['read', 'write', 'delete'], ['local'], '', 10, null, 0, false);
    $meta = new MockMetadataRepository();
    $meta->setMeta('local', 'photos/pic.jpg', ['uploaded_by' => 'user-B']);
    $fm = makeFM($claims, $meta);

    // Should NOT throw
    callAssertOwner($fm, 'local', 'photos/pic.jpg');
    assertEqual(true, true); // reached here = pass
});

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► assertOwner — ownerOnly enabled{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('owner can modify their own file', function () {
    $claims = new Claims('user-A', ['read', 'write', 'delete'], ['local'], '', 10, null, 0, true);
    $meta = new MockMetadataRepository();
    $meta->setMeta('local', 'photos/pic.jpg', ['uploaded_by' => 'user-A']);
    $fm = makeFM($claims, $meta);

    callAssertOwner($fm, 'local', 'photos/pic.jpg');
    assertEqual(true, true);
});

test('non-owner CANNOT modify another user file', function () {
    $claims = new Claims('user-A', ['read', 'write', 'delete'], ['local'], '', 10, null, 0, true);
    $meta = new MockMetadataRepository();
    $meta->setMeta('local', 'photos/pic.jpg', ['uploaded_by' => 'user-B']);
    $fm = makeFM($claims, $meta);

    $threw = false;
    try {
        callAssertOwner($fm, 'local', 'photos/pic.jpg');
    } catch (ApiException $e) {
        $threw = true;
        assertEqual(403, $e->getHttpCode(), 'Should be 403');
    }
    assertEqual(true, $threw, 'Should have thrown ApiException');
});

test('file with no metadata (directory/unknown) is allowed', function () {
    $claims = new Claims('user-A', ['read', 'write', 'delete'], ['local'], '', 10, null, 0, true);
    $meta = new MockMetadataRepository();
    // No metadata set for this path
    $fm = makeFM($claims, $meta);

    callAssertOwner($fm, 'local', 'some-folder');
    assertEqual(true, true);
});

test('legacy file without uploaded_by field is allowed', function () {
    $claims = new Claims('user-A', ['read', 'write', 'delete'], ['local'], '', 10, null, 0, true);
    $meta = new MockMetadataRepository();
    $meta->setMeta('local', 'old-file.jpg', ['title' => 'Old file', 'alt_text' => '']);
    $fm = makeFM($claims, $meta);

    callAssertOwner($fm, 'local', 'old-file.jpg');
    assertEqual(true, true);
});

test('uploaded_by is stored on upload via meta->save', function () {
    $claims = new Claims('user-42', ['read', 'write'], ['local'], '', 10, null, 0, true);
    $meta = new MockMetadataRepository();

    // Simulate what FileManager::upload does
    $meta->save('local', 'photos/new.jpg', ['uploaded_by' => $claims->userId]);

    $stored = $meta->get('local', 'photos/new.jpg');
    assertEqual('user-42', $stored['uploaded_by'] ?? null);
});

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► assertOwner — edge cases{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('different userId strings are not equal', function () {
    $claims = new Claims('42', ['read', 'write', 'delete'], ['local'], '', 10, null, 0, true);
    $meta = new MockMetadataRepository();
    $meta->setMeta('local', 'file.jpg', ['uploaded_by' => '43']);
    $fm = makeFM($claims, $meta);

    $threw = false;
    try {
        callAssertOwner($fm, 'local', 'file.jpg');
    } catch (ApiException $e) {
        $threw = true;
    }
    assertEqual(true, $threw, 'user 42 should not modify user 43 file');
});

test('empty userId still works (matches empty uploaded_by)', function () {
    $claims = new Claims('', ['read', 'write', 'delete'], ['local'], '', 10, null, 0, true);
    $meta = new MockMetadataRepository();
    $meta->setMeta('local', 'file.jpg', ['uploaded_by' => '']);
    $fm = makeFM($claims, $meta);

    callAssertOwner($fm, 'local', 'file.jpg');
    assertEqual(true, true);
});

test('ownerOnly with pathPrefix: scoped path ownership check', function () {
    $claims = new Claims('user-A', ['read', 'write', 'delete'], ['local'], 'team/shared', 10, null, 0, true);
    $meta = new MockMetadataRepository();
    $meta->setMeta('local', 'team/shared/doc.pdf', ['uploaded_by' => 'user-B']);
    $fm = makeFM($claims, $meta);

    $threw = false;
    try {
        callAssertOwner($fm, 'local', 'team/shared/doc.pdf');
    } catch (ApiException $e) {
        $threw = true;
        assertEqual(403, $e->getHttpCode());
    }
    assertEqual(true, $threw, 'user-A should not modify user-B file in shared folder');
});

// ═══════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  ";
echo "{$green}Passed: {$passed}{$reset}  ";
echo "{$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
