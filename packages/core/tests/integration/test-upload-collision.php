<?php

/**
 * Upload name-collision policy (upload_collision claim): rename (default) / reject /
 * overwrite. Content dedup is by hash and tested elsewhere — here every upload is a
 * DIFFERENT file that happens to share a name.
 *
 * Usage: php packages/core/tests/integration/test-upload-collision.php  (from repo root)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
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
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

/** A FileManager with the given collision policy over a fresh temp local disk. */
function setup(string $policy): array {
    $root = sys_get_temp_dir() . '/ff-coll-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $claims = new Claims('u', ['read', 'write', 'delete'], ['local'], '', 50, null, 0);
    $claims->uploadCollision = $policy;
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    return [$fm, $dm, $root, $claims];
}

/** Simulate a multipart upload of $content as $name (unique bytes → never a hash-dup). */
function upload(FileManager $fm, string $name, string $content): array {
    $tmp = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($tmp, $content);
    try {
        return $fm->upload('local', '', [
            'name' => $name,
            'tmp_name' => $tmp,
            'size' => strlen($content),
            'error' => 0,
            'type' => 'text/plain',
        ]);
    } finally { @unlink($tmp); }
}

echo "\n{$cyan}══ Upload name-collision policy ══{$reset}\n\n";

test('claim parse: default rename; invalid → rename; valid kept', function () {
    assertEqual('rename', (Claims::fromJwtPayload((object) []))->uploadCollision, 'default');
    assertEqual('rename', (Claims::fromJwtPayload((object) ['upload_collision' => 'bogus']))->uploadCollision, 'invalid→rename');
    assertEqual('overwrite', (Claims::fromJwtPayload((object) ['upload_collision' => 'overwrite']))->uploadCollision);
    assertEqual('reject', (Claims::fromJwtPayload((object) ['upload_collision' => 'reject']))->uploadCollision);
});

test('rename (default): keeps both, appends (1)/(2), extension preserved', function () {
    [$fm, $dm, $root] = setup('rename');
    $r1 = upload($fm, 'doc.txt', 'first');
    assertEqual('doc.txt', $r1['name'], 'first keeps name');
    $r2 = upload($fm, 'doc.txt', 'second-different');
    assertEqual('doc (1).txt', $r2['name'], 'second → doc (1).txt');
    $r3 = upload($fm, 'doc.txt', 'third-different');
    assertEqual('doc (2).txt', $r3['name'], 'third → doc (2).txt');
    // All three survive with their own contents.
    assertEqual('first', file_get_contents("$root/doc.txt"));
    assertEqual('second-different', file_get_contents("$root/doc (1).txt"));
    assertEqual('third-different', file_get_contents("$root/doc (2).txt"));
});

test('rename keeps both even for IDENTICAL content (dedup off by default)', function () {
    [$fm, $dm, $root] = setup('rename');
    upload($fm, 'same.txt', 'identical-bytes');
    $r = upload($fm, 'same.txt', 'identical-bytes'); // same content, dedup OFF → copy
    assertEqual('same (1).txt', $r['name'], 'identical re-upload → (1), like the OS');
    assertTrue(is_file("$root/same.txt") && is_file("$root/same (1).txt"), 'both kept');
});

test('reject: 409 name_conflict when the name is taken', function () {
    [$fm] = setup('reject');
    upload($fm, 'a.txt', 'one');
    try { upload($fm, 'a.txt', 'two-different'); throw new \RuntimeException('expected 409'); }
    catch (ApiException $e) { assertEqual('name_conflict', $e->getErrorCode()); assertEqual(409, $e->getHttpCode()); }
});

test('overwrite: replaces in place, same name', function () {
    [$fm, $dm, $root] = setup('overwrite');
    upload($fm, 'b.txt', 'old');
    $r = upload($fm, 'b.txt', 'new-different');
    assertEqual('b.txt', $r['name'], 'same name');
    assertEqual('new-different', file_get_contents("$root/b.txt"), 'overwritten');
    assertTrue(!is_file("$root/b (1).txt"), 'no rename copy');
});

test('dedupe_uploads claim ON → identical content is refused (storage saving)', function () {
    [$fm, $dm, $root, $claims] = setup('rename');
    $claims->dedupeUploads = true;
    upload($fm, 'c.txt', 'identical-bytes');
    $r = upload($fm, 'c.txt', 'identical-bytes'); // same content + dedup ON → duplicate
    assertTrue(!empty($r['duplicate']), 'hash dedup reports duplicate when claim on');
    assertTrue(!is_file("$root/c (1).txt"), 'no copy when dedup is enabled');
});

test('mkdir: fresh folder → created; existing folder → 409 folder_exists', function () {
    [$fm] = setup('rename');
    $r = $fm->mkdir('local', 'docs');
    assertEqual(true, $r['created'] ?? null, 'fresh folder created');
    // Re-creating the same folder must conflict, not silently 200.
    try { $fm->mkdir('local', 'docs'); throw new \RuntimeException('expected 409'); }
    catch (ApiException $e) {
        assertEqual('folder_exists', $e->getErrorCode());
        assertEqual(409, $e->getHttpCode());
        assertEqual('docs', $e->getErrorParams()['name'] ?? null, 'name in params for i18n');
    }
});

test('mkdir: a folder name colliding with an existing FILE → 409', function () {
    [$fm] = setup('rename');
    upload($fm, 'report', 'i am a file'); // extensionless file named "report"
    try { $fm->mkdir('local', 'report'); throw new \RuntimeException('expected 409'); }
    catch (ApiException $e) { assertEqual('folder_exists', $e->getErrorCode()); }
});

// ── B1: owner_only must gate overwrite, not just the other destructive ops ──

test('B1: owner_only + upload_collision=overwrite → a different tenant CANNOT overwrite', function () {
    $root = sys_get_temp_dir() . '/ff-coll-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $meta = new StorageMetadataHandler($dm);

    // user-A uploads the original.
    $claimsA = new Claims('user-A', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, true);
    $claimsA->uploadCollision = 'overwrite';
    $fmA = new FileManager($dm, $claimsA, $meta);
    upload($fmA, 'shared.txt', 'user-A original');

    // user-B tries to overwrite it via upload_collision=overwrite.
    $claimsB = new Claims('user-B', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, true);
    $claimsB->uploadCollision = 'overwrite';
    $fmB = new FileManager($dm, $claimsB, $meta);
    try {
        upload($fmB, 'shared.txt', 'user-B attack');
        throw new \RuntimeException('expected 403 owner_only');
    } catch (ApiException $e) {
        assertEqual('owner_only', $e->getErrorCode());
        assertEqual(403, $e->getHttpCode());
    }
    assertEqual('user-A original', file_get_contents("$root/shared.txt"), 'original bytes untouched');
});

test('B1: owner_only + force_upload=1 → a different tenant CANNOT overwrite', function () {
    $root = sys_get_temp_dir() . '/ff-coll-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $meta = new StorageMetadataHandler($dm);

    $claimsA = new Claims('user-A', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, true);
    $fmA = new FileManager($dm, $claimsA, $meta);
    upload($fmA, 'shared2.txt', 'user-A original');

    $claimsB = new Claims('user-B', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, true);
    $fmB = new FileManager($dm, $claimsB, $meta);
    $tmp = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($tmp, 'user-B attack via force_upload');
    try {
        try {
            $fmB->upload('local', '', [
                'name' => 'shared2.txt',
                'tmp_name' => $tmp,
                'size' => filesize($tmp),
                'error' => 0,
                'type' => 'text/plain',
            ], true); // forceUpload=true, mirrors ?force_upload=1
            throw new \RuntimeException('expected 403 owner_only');
        } catch (ApiException $e) {
            assertEqual('owner_only', $e->getErrorCode());
            assertEqual(403, $e->getHttpCode());
        }
    } finally {
        @unlink($tmp);
    }
    assertEqual('user-A original', file_get_contents("$root/shared2.txt"), 'original bytes untouched');
});

test('B1: owner_only → the OWNER can still overwrite their own file', function () {
    [$fm, $dm, $root, $claims] = setup('overwrite');
    $claims->ownerOnly = true;
    $claims->userId = 'user-A';
    upload($fm, 'mine.txt', 'first');
    $r = upload($fm, 'mine.txt', 'second-different');
    assertEqual('mine.txt', $r['name']);
    assertEqual('second-different', file_get_contents("$root/mine.txt"));
});

test('B1: brand-new upload (no existing file) needs no owner check even with owner_only on', function () {
    $root = sys_get_temp_dir() . '/ff-coll-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $claims = new Claims('user-A', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, true);
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    $r = upload($fm, 'fresh.txt', 'brand new');
    assertEqual('fresh.txt', $r['name']);
});

// ── Filename character blacklist (mirrors rename()'s regex) ──

test('upload rejects filenames with dangerous characters (mirrors rename())', function () {
    [$fm] = setup('rename');
    foreach (['bad<name.txt', 'bad>name.txt', 'bad:name.txt', 'bad"name.txt', 'bad|name.txt', 'bad?name.txt', 'bad*name.txt'] as $name) {
        try {
            upload($fm, $name, 'x');
            throw new \RuntimeException("expected name_invalid for {$name}");
        } catch (ApiException $e) {
            assertEqual('name_invalid', $e->getErrorCode(), "for {$name}");
        }
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
