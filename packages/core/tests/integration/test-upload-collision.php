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
    return [$fm, $dm, $root];
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

test('rename (default): keeps both, appends -1/-2, extension preserved', function () {
    [$fm, $dm, $root] = setup('rename');
    $r1 = upload($fm, 'doc.txt', 'first');
    assertEqual('doc.txt', $r1['name'], 'first keeps name');
    $r2 = upload($fm, 'doc.txt', 'second-different');
    assertEqual('doc-1.txt', $r2['name'], 'second → doc-1.txt');
    $r3 = upload($fm, 'doc.txt', 'third-different');
    assertEqual('doc-2.txt', $r3['name'], 'third → doc-2.txt');
    // All three survive with their own contents.
    assertEqual('first', file_get_contents("$root/doc.txt"));
    assertEqual('second-different', file_get_contents("$root/doc-1.txt"));
    assertEqual('third-different', file_get_contents("$root/doc-2.txt"));
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
    assertTrue(!is_file("$root/b-1.txt"), 'no rename copy');
});

test('rename only triggers on a DIFFERENT file (same bytes → hash dedup, not -1)', function () {
    [$fm, $dm, $root] = setup('rename');
    upload($fm, 'c.txt', 'identical-bytes');
    $r = upload($fm, 'c.txt', 'identical-bytes'); // same content → duplicate path
    assertTrue(!empty($r['duplicate']), 'hash dedup reports duplicate');
    assertTrue(!is_file("$root/c-1.txt"), 'no -1 copy for an identical re-upload');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
