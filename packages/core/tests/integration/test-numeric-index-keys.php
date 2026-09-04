<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

// Regression: json_decode(..., true) coerces decimal-integer-looking string
// keys (e.g. "5") into real PHP int array keys. Under strict_types=1, every
// loadIndex() consumer that passes the key into a string-typed parameter
// (isReservedPath/isHiddenPath in search(), strpos() in deleteChildren()/
// renameChildren(), str_starts_with() in findByHash()) would throw a
// TypeError as soon as the index contained one such entry — crashing every
// call that iterates the whole index, not just requests touching that file.

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }

/** @return array{0:FileManager,1:StorageMetadataHandler} */
function makeFM(bool $dedupe = false): array
{
    $root = sys_get_temp_dir() . '/fluxfiles-numkeys-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $meta = new StorageMetadataHandler($dm);
    $claims = new Claims('u', ['read', 'write', 'delete'], ['local'], '', 50, null, 0);
    $claims->dedupeUploads = $dedupe;
    $fm = new FileManager($dm, $claims, $meta);
    return [$fm, $meta];
}

function up(FileManager $fm, string $path, string $name, string $content = 'x'): void
{
    $tmp = tempnam(sys_get_temp_dir(), 'fxn');
    file_put_contents($tmp, $content);
    $fm->upload('local', $path, ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'type' => 'text/plain', 'error' => 0]);
}

echo "\n{$cyan}══ FluxFiles Numeric Index Keys ══{$reset}\n\n";

test('a numeric-named file survives loadIndex() (PHP itself coerces "5" -> int(5) as an array key)', function () {
    [$fm, $meta] = makeFM();
    up($fm, '', '5');
    $index = (new \ReflectionMethod($meta, 'loadIndex'))->invoke($meta, 'local');
    // PHP always normalizes a canonical-decimal string key to int — this is an
    // array-key invariant, not something loadIndex() can override. The entry
    // itself must still be there (not dropped); consumers cast the key with
    // (string) before using it in a string-typed context.
    assertTrue(array_key_exists('5', $index), 'entry for "5" present (accessible via either "5" or 5)');
});

test('search() does not throw and still finds an unrelated file once a numeric key exists', function () {
    [$fm, $meta] = makeFM();
    up($fm, '', '5');
    $fm->mkdir('local', 'other');
    up($fm, 'other', 'unrelated.txt');

    $rows = $meta->search('local', 'unrelated');
    assertEqual(1, count($rows), 'unrelated file still found');
    assertEqual('other/unrelated.txt', $rows[0]['file_key'], 'right file');

    // The numeric-named file itself is discoverable too, not silently dropped.
    $numRows = $meta->search('local', '5');
    $keys = array_column($numRows, 'file_key');
    assertTrue(in_array('5', $keys, true), 'file "5" is found by search, not dropped');
});

test('deleteChildren() (via folder delete) does not throw once a numeric key exists', function () {
    [$fm, $meta] = makeFM();
    up($fm, '', '5');
    $fm->mkdir('local', 'other');
    up($fm, 'other', 'unrelated.txt');

    $fm->delete('local', 'other');

    // The folder's child is gone from the index; "5" at the root is untouched.
    assertEqual(0, count($meta->search('local', 'unrelated')), 'child removed from index');
    $numRows = $meta->search('local', '5');
    assertTrue(count($numRows) === 1, 'unrelated numeric-keyed entry survives the cascade');
});

test('renameChildren() (via folder rename) does not throw once a numeric key exists', function () {
    [$fm, $meta] = makeFM();
    up($fm, '', '5');
    $fm->mkdir('local', 'other');
    up($fm, 'other', 'unrelated.txt');

    $fm->rename('local', 'other', 'renamed');

    $rows = $meta->search('local', 'unrelated');
    assertEqual(1, count($rows), 'child re-found after rename');
    assertEqual('renamed/unrelated.txt', $rows[0]['file_key'], 'child key updated');
});

test('findByHash() (upload dedup) does not throw once a numeric key exists', function () {
    [$fm, $meta] = makeFM(dedupe: true);
    up($fm, '', '5');
    up($fm, '', 'first.txt', 'same-bytes');

    // Re-uploading identical bytes should be recognized as a duplicate, not
    // crash while scanning past the numeric-keyed "5" entry.
    $tmp = tempnam(sys_get_temp_dir(), 'fxn');
    file_put_contents($tmp, 'same-bytes');
    $result = $fm->upload('local', '', ['name' => 'second.txt', 'tmp_name' => $tmp, 'size' => strlen('same-bytes'), 'type' => 'text/plain', 'error' => 0]);
    assertTrue(!empty($result['duplicate'] ?? false), 'duplicate detected instead of crashing');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
