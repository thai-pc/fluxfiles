<?php

/**
 * list() sorts and filters the WHOLE folder before cutting pages: `sort`
 * (name|date|size|type), `order` (asc|desc), `type` (image|video|…). Pages
 * concatenate to exactly the unpaged order; folders always come first; the
 * default ('' sort) keeps the original key order; and the extension buckets
 * stay identical to the UI's fileIcon().
 *
 * Usage:
 *   php tests/integration/test-list-sort-filter.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function assertSame($e, $a, string $m): void
{
    if ($e !== $a) throw new \RuntimeException($m . "\n    expected: " . json_encode($e) . "\n    actual:   " . json_encode($a));
}

/** name => [bytes, mtime] */
const FILES = [
    'img2.jpg'    => [300, 1700000500],
    'img10.jpg'   => [100, 1700000900],   // newest
    'Img1.png'    => [200, 1700000100],
    'clip.mp4'    => [900, 1700000300],
    'song.mp3'    => [50,  1700000300],   // same mtime as clip.mp4 → tie on date
    'notes.txt'   => [300, 1700000200],   // same size as img2.jpg → tie on size
    'report.pdf'  => [700, 1700000050],   // oldest
];

function env(): FileManager
{
    $root = sys_get_temp_dir() . '/ff-sort-' . bin2hex(random_bytes(4));
    mkdir("{$root}/box/zeta", 0777, true);
    mkdir("{$root}/box/Alpha", 0777, true);
    foreach (FILES as $name => [$bytes, $mtime]) {
        file_put_contents("{$root}/box/{$name}", str_repeat('x', $bytes));
        touch("{$root}/box/{$name}", $mtime);
    }
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $c = new Claims('u', ['read'], ['local'], '', 50, null, 0, false);
    return new FileManager($dm, $c, new StorageMetadataHandler($dm));
}

function names(array $items): array
{
    return array_map(fn ($i) => ($i['type'] === 'dir' ? 'D:' : '') . $i['name'], $items);
}

/** All pages at $limit, concatenated. */
function paged(FileManager $fm, int $limit, string $sort, string $order, string $type = 'all'): array
{
    $out = [];
    $cursor = '';
    $guard = 0;
    do {
        $r = $fm->list('local', 'box', $limit, $cursor, $sort, $order, $type);
        $out = array_merge($out, names($r['items']));
        $cursor = (string) ($r['next_cursor'] ?? '');
    } while ($cursor !== '' && ++$guard < 50);
    return $out;
}

echo "\n{$cyan}══ list(): server-side sort + type filter ══{$reset}\n\n";

test('default (no sort) is unchanged: folders then files, by key (byte order)', function () {
    $r = env()->list('local', 'box', 100);
    assertSame(['D:Alpha', 'D:zeta', 'Img1.png', 'clip.mp4', 'img10.jpg', 'img2.jpg', 'notes.txt', 'report.pdf', 'song.mp3'],
        names($r['items']), 'key order');
});

test('name: natural + case-insensitive, folders first; desc reverses both groups', function () {
    $fm = env();
    assertSame(['D:Alpha', 'D:zeta', 'clip.mp4', 'Img1.png', 'img2.jpg', 'img10.jpg', 'notes.txt', 'report.pdf', 'song.mp3'],
        names($fm->list('local', 'box', 100, '', 'name', 'asc')['items']), 'name asc');
    assertSame(['D:zeta', 'D:Alpha', 'song.mp3', 'report.pdf', 'notes.txt', 'img10.jpg', 'img2.jpg', 'Img1.png', 'clip.mp4'],
        names($fm->list('local', 'box', 100, '', 'name', 'desc')['items']), 'name desc');
});

test('date desc: newest in the FOLDER first; a tie breaks by name ascending', function () {
    $r = env()->list('local', 'box', 100, '', 'date', 'desc');
    $files = array_values(array_filter(names($r['items']), fn ($n) => strpos($n, 'D:') !== 0));
    assertSame(['img10.jpg', 'img2.jpg', 'clip.mp4', 'song.mp3', 'notes.txt', 'Img1.png', 'report.pdf'], $files, 'date desc');
});

test('size asc / type asc, with name-ascending tie-breaks', function () {
    $fm = env();
    $files = fn (array $items) => array_values(array_filter(names($items), fn ($n) => strpos($n, 'D:') !== 0));
    assertSame(['song.mp3', 'img10.jpg', 'Img1.png', 'img2.jpg', 'notes.txt', 'report.pdf', 'clip.mp4'],
        $files($fm->list('local', 'box', 100, '', 'size', 'asc')['items']), 'size asc (img2/notes tie by name)');
    // UI kind names: audio < document < file (other) < image < video
    assertSame(['song.mp3', 'report.pdf', 'notes.txt', 'Img1.png', 'img2.jpg', 'img10.jpg', 'clip.mp4'],
        $files($fm->list('local', 'box', 100, '', 'type', 'asc')['items']), 'type asc');
});

test('every sort pages to exactly the unpaged order (cursor resumes in place)', function () {
    $fm = env();
    foreach (['', 'name', 'date', 'size', 'type'] as $sort) {
        foreach (['asc', 'desc'] as $order) {
            $all = names($fm->list('local', 'box', 100, '', $sort, $order)['items']);
            foreach ([1, 2, 3, 4] as $limit) {
                assertSame($all, paged($fm, $limit, $sort, $order), "sort={$sort} order={$order} limit={$limit}");
            }
        }
    }
});

test('type filter: matching files plus every folder; total counts what matched', function () {
    $fm = env();
    $r = $fm->list('local', 'box', 2, '', 'name', 'asc', 'image');
    assertSame(3 + 2, $r['total'], 'total = 3 images + 2 folders');
    assertSame(['D:Alpha', 'D:zeta', 'Img1.png', 'img2.jpg', 'img10.jpg'], paged($fm, 2, 'name', 'asc', 'image'), 'images, paged');
    assertSame(['D:Alpha', 'D:zeta', 'notes.txt'], paged($fm, 2, 'name', 'asc', 'other'), 'other = no known kind');
    assertSame(['D:Alpha', 'D:zeta', 'clip.mp4'], paged($fm, 10, '', 'asc', 'video'), 'filter works in key order too');
});

test('unknown sort / order / type → 400, never silently ignored', function () {
    $fm = env();
    foreach ([['bogus', 'asc', 'all', 'invalid_sort'], ['name', 'up', 'all', 'invalid_order'], ['name', 'asc', 'exe', 'invalid_type']] as [$s, $o, $t, $code]) {
        $got = null;
        try { $fm->list('local', 'box', 10, '', $s, $o, $t); } catch (ApiException $e) { $got = [$e->getHttpCode(), $e->getErrorCode()]; }
        assertSame([400, $code], $got, "{$s}/{$o}/{$t}");
    }
});

test('the extension buckets are the UI\'s fileIcon() lists, exactly', function () {
    $js = (string) file_get_contents(__DIR__ . '/../../assets/fm.js');
    $start = strpos($js, 'fileIcon(file) {');
    assertTrue($start !== false, 'fileIcon() found in fm.js');
    $body = substr($js, $start, 900);
    $ref = new \ReflectionClassConstant(FileManager::class, 'LIST_KIND_EXT');
    foreach (['image' => 'imageExts', 'video' => 'videoExts', 'audio' => 'audioExts', 'document' => 'docExts'] as $kind => $var) {
        assertTrue((bool) preg_match('/const ' . $var . ' = \[([^\]]*)\]/', $body, $m), "{$var} in fm.js");
        preg_match_all("/'([a-z0-9]+)'/", $m[1], $mm);
        assertSame($mm[1], $ref->getValue()[$kind], "{$kind} extensions match fm.js");
    }
});

test('names collate like the browser: accents in place, numbers numeric (ext-intl)', function () {
    if (!class_exists(\Collator::class)) {
        echo "    (skipped: no ext-intl — strnatcasecmp fallback)\n";
        return;
    }
    $root = sys_get_temp_dir() . '/ff-sort-intl-' . bin2hex(random_bytes(4));
    mkdir("{$root}/v", 0777, true);
    foreach (['Zebra.txt', 'Ảnh 1.txt', 'éclair.txt', 'Bảng.txt', 'ab.txt', 'a b.txt', 'IMG_2.txt', 'img10.txt'] as $n) {
        file_put_contents("{$root}/v/{$n}", 'x');
    }
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $fm = new FileManager($dm, new Claims('u', ['read'], ['local'], '', 50, null, 0, false), new StorageMetadataHandler($dm));
    // What Chrome's localeCompare(…, {numeric: true}) gives for the same names.
    assertSame(['a b.txt', 'ab.txt', 'Ảnh 1.txt', 'Bảng.txt', 'éclair.txt', 'IMG_2.txt', 'img10.txt', 'Zebra.txt'],
        names($fm->list('local', 'v', 100, '', 'name', 'asc')['items']), 'ICU root, numeric');
});

test('a cursor whose row is gone: 409 cursor_stale in a sorted listing; key order restarts as before', function () {
    $fm = env();
    $first = $fm->list('local', 'box', 3, '', 'name', 'asc');
    $got = null;
    try { $fm->list('local', 'box', 3, 'box/deleted-meanwhile.jpg', 'name', 'asc'); } catch (ApiException $e) { $got = [$e->getHttpCode(), $e->getErrorCode()]; }
    assertSame([409, 'cursor_stale'], $got, 'sorted');
    $legacy = $fm->list('local', 'box', 3, 'box/deleted-meanwhile.jpg');
    assertSame(names($fm->list('local', 'box', 3)['items']), names($legacy['items']), 'key order: unchanged behaviour');
    assertTrue(count($first['items']) === 3, 'sanity');
});

test('empty order / type mean the defaults', function () {
    $fm = env();
    assertSame(names($fm->list('local', 'box', 100, '', 'name', 'asc', 'all')['items']),
        names($fm->list('local', 'box', 100, '', 'name', '', '')['items']), 'defaults');
});

echo "\n  Total: " . ($passed + $failed) . "  Passed: {$passed}  Failed: {$failed}\n\n";
exit($failed > 0 ? 1 : 0);
