<?php

/**
 * Regression tests: torn/corrupt manifests, all-digit folder names, name rules
 * (length / characters / mkdir), zip extract self-collisions, case-only rename,
 * trash purge failures and cross-disk folders.
 *
 * Usage: php tests/unit/test-core-correctness.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\{ApiException, Claims, DiskManager, FileManager, StorageMetadataHandler};

$fail = 0;
function ok(bool $cond, string $msg): void
{
    global $fail;
    echo ($cond ? '  ✓ ' : '  ✗ ') . $msg . "\n";
    if (!$cond) {
        $fail++;
    }
}
function code(callable $fn): ?string
{
    try {
        $fn();
        return null;
    } catch (ApiException $e) {
        return $e->getErrorCode() . ':' . $e->getHttpCode();
    }
}
function rig(array $extra = []): array
{
    $root = sys_get_temp_dir() . '/fxcc-' . bin2hex(random_bytes(4));
    mkdir($root);
    mkdir($root . '-b');
    $dm = new DiskManager([
        'local' => ['driver' => 'local', 'root' => $root, 'url' => '/s'],
        'other' => ['driver' => 'local', 'root' => $root . '-b', 'url' => '/s2'],
    ]);
    $meta = new StorageMetadataHandler($dm);
    $c = new Claims('u', ['read', 'write', 'delete'], ['local', 'other'], '', 50, null, 0);
    $c->allowExtract = true;
    foreach ($extra as $k => $v) {
        $c->$k = $v;
    }
    return [$root, $dm, $meta, new FileManager($dm, $c, $meta)];
}
function up(FileManager $fm, string $name, string $body = 'x'): array
{
    $t = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($t, $body);
    return $fm->upload('local', '', ['name' => $name, 'tmp_name' => $t, 'size' => strlen($body), 'type' => 'text/plain', 'error' => 0]);
}
function rrm(string $d): void
{
    if (!is_dir($d)) {
        return;
    }
    foreach (scandir($d) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        is_dir("$d/$f") ? rrm("$d/$f") : unlink("$d/$f");
    }
    rmdir($d);
}

echo "\n[1] corrupt manifests\n";
[$root, $dm, $meta, $fm] = rig();
up($fm, 'a.txt'); up($fm, 'b.txt'); up($fm, 'c.txt');
$fm->trash('local', 'a.txt');
$fm->trash('local', 'b.txt');
$trashJson = "$root/_fluxfiles/trash.json";
ok(is_file($trashJson . '.bak'), 'a .bak of the last good manifest is kept');
ok(glob("$root/_fluxfiles/*.tmp-*") === [], 'no temp file is left behind by the atomic write');
// torn write: both main and bak
$j = file_get_contents($trashJson);
file_put_contents($trashJson, substr($j, 0, intdiv(strlen($j), 2)));
$list = $fm->listTrash('local');
ok(count($list) === 1, 'a torn trash.json falls back to the .bak copy (' . count($list) . ' entry)');
$fm->trash('local', 'c.txt');
ok(json_decode(file_get_contents($trashJson), true) !== null, 'the next write repairs trash.json');
// corrupt with no usable backup: refuse, do not overwrite
file_put_contents($trashJson, '{"broken":');
@unlink($trashJson . '.bak');
$before = file_get_contents($trashJson);
up($fm, 'd.txt');
ok(code(fn() => $fm->trash('local', 'd.txt')) === 'metadata_corrupt:409', 'trash on a corrupt manifest answers 409 metadata_corrupt');
ok(file_exists("$root/d.txt"), 'the file was not moved');
ok(file_get_contents($trashJson) === $before, 'the corrupt manifest was not overwritten');
// reconcile rebuilds from payloads
$res = $meta->reconcileTrash('local');
ok(count($res['recovered']) === 3, 'reconcile recovers every payload (' . count($res['recovered']) . ')');
ok(count($fm->listTrash('local')) === 3, 'recovered entries are listed');
$fm->trash('local', 'd.txt');
ok(count($fm->listTrash('local')) === 4, 'trash works again after reconcile');
rrm($root); rrm($root . '-b');

echo "\n[2] all-digit folder names\n";
[$root, $dm, $meta, $fm] = rig();
$fm->mkdir('local', '2024');
$fm->mkdir('local', 'photos');
$fm->mkdir('local', 'docs');
$dirs = json_decode(file_get_contents("$root/_fluxfiles/dirs.json"), true);
ok(isset($dirs['2024']) && isset($dirs['photos']) && isset($dirs['docs']), 'dirs.json keeps 2024 across later writes');
$fresh = new StorageMetadataHandler($dm);
ok(count($fresh->searchFolders('local', '2024')) === 1, 'searchFolders finds the all-digit folder');
$fm->rename('local', '2024', '2025');
$dirs = json_decode(file_get_contents("$root/_fluxfiles/dirs.json"), true);
ok(isset($dirs['2025']) && !isset($dirs['2024']), 'renaming an all-digit folder moves its index entry');
rrm($root); rrm($root . '-b');

echo "\n[3] name length\n";
[$root, $dm, $meta, $fm] = rig();
$long = str_repeat('a', 251) . '.txt';
ok(code(fn() => up($fm, $long)) === 'name_too_long:400', '255-byte upload name -> 400 name_too_long');
ok(!file_exists("$root/$long"), 'no orphan file was written');
ok(code(fn() => $fm->mkdir('local', str_repeat('d', 250))) === 'name_too_long:400', 'mkdir with a long name -> 400');
up($fm, 'ok.txt');
ok(code(fn() => $fm->rename('local', 'ok.txt', str_repeat('r', 250) . '.txt')) === 'name_too_long:400', 'rename to a long name -> 400');
$edge = str_repeat('e', 236) . '.txt'; // 240 bytes: the limit
$r = up($fm, $edge);
ok(isset($r['key']) && is_file("$root/_fluxfiles/meta/$edge.json"), 'a name at the limit uploads with its sidecar');
rrm($root); rrm($root . '-b');

echo "\n[4] zip extract collisions\n";
[$root, $dm, $meta, $fm] = rig();
mkdir("$root/arc");
file_put_contents("$root/arc/a.txt", 'EXISTING');
$z = new ZipArchive();
$z->open("$root/arc.zip", ZipArchive::CREATE);
$z->addFromString('a.txt', 'ZIP-A');
$z->addFromString('a (1).txt', 'ZIP-A1');
$z->addFromString('sub/x.txt', 'X');
$z->close();
$r = $fm->extractZip('local', 'arc.zip');
$contents = [];
foreach (glob("$root/arc/*.txt") as $f) {
    $contents[basename($f)] = file_get_contents($f);
}
ok(count($contents) === 3 && in_array('ZIP-A', $contents, true) && in_array('ZIP-A1', $contents, true) && $contents['a.txt'] === 'EXISTING',
    'no entry overwrites another (' . json_encode($contents) . ')');
$z = new ZipArchive();
@unlink("$root/ci.zip");
$z->open("$root/ci.zip", ZipArchive::CREATE);
$z->addFromString('Readme.md', 'upper');
$z->addFromString('README.md', 'lower');
$z->close();
$fm->extractZip('local', 'ci.zip', 'ci');
$n = 0;
$all = '';
foreach (scandir("$root/ci") as $f) {
    if ($f[0] !== '.' && is_file("$root/ci/$f")) {
        $n++;
        $all .= file_get_contents("$root/ci/$f");
    }
}
ok($n === 2 && str_contains($all, 'upper') && str_contains($all, 'lower'), 'case-variant entries both survive');
ok(code(fn() => $fm->extractZip('local', 'arc.zip', 'arc/a.txt')) === 'dest_not_folder:400', 'a file as destination -> 400');
rrm($root); rrm($root . '-b');

echo "\n[5/6] malformed names\n";
[$root, $dm, $meta, $fm] = rig();
foreach (["a\nb", 'a:b', 'a<b', "a\x01b", 'a|b', 'a?b'] as $bad) {
    ok(code(fn() => $fm->mkdir('local', $bad)) === 'name_invalid:400', 'mkdir rejects ' . json_encode($bad));
}
ok(code(fn() => $fm->mkdir('local', 'fine/dir')) === null, 'mkdir accepts a nested legal path');
rrm($root); rrm($root . '-b');

echo "\n[7] case-only rename\n";
[$root, $dm, $meta, $fm] = rig();
up($fm, 'Photo.txt', 'PIC');
$r = $fm->rename('local', 'Photo.txt', 'photo.txt');
ok(($r['name'] ?? '') === 'photo.txt', 'Photo.txt -> photo.txt is allowed');
$names = array_diff(scandir($root), ['.', '..', '_fluxfiles']);
ok(array_values($names) === ['photo.txt'], 'the file is spelled photo.txt on disk (' . implode(',', $names) . ')');
ok(($meta->get('local', 'photo.txt')['uploaded_by'] ?? '') === 'u', 'its owner record followed the rename');
up($fm, 'Other.txt');
up($fm, 'z.txt');
ok(code(fn() => $fm->rename('local', 'z.txt', 'Other.txt')) === 'name_exists:409', 'a real conflict still answers 409');
rrm($root); rrm($root . '-b');

echo "\n[8] purge keeps the entry when the delete fails\n";
[$root, $dm, $meta, $fm] = rig();
up($fm, 'p.txt');
$id = $fm->trash('local', 'p.txt')['trash_id'];
$dir = "$root/_fluxfiles/trash/$id";
chmod($dir, 0500);
$posixRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
if ($posixRoot) {
    echo "  - skipped (running as root: chmod does not block deletes)\n";
} else {
    $c = code(fn() => $fm->purgeTrash('local', $id));
    ok($c === 'purge_failed:500', 'a failed delete -> 500 purge_failed (' . $c . ')');
    ok(count($fm->listTrash('local')) === 1, 'the manifest entry is kept');
    $e = $fm->emptyTrash('local');
    ok(($e['purged'] ?? -1) === 0 && ($e['failed'] ?? 0) === 1, 'emptyTrash reports the failure');
}
chmod($dir, 0700);
ok(($fm->purgeTrash('local', $id)['purged'] ?? false) === true, 'purge succeeds once the delete can');
ok(count($fm->listTrash('local')) === 0, 'and then the entry is gone');
rrm($root); rrm($root . '-b');

echo "\n[9] cross-disk folders\n";
[$root, $dm, $meta, $fm] = rig();
$fm->mkdir('local', 'dir');
ok(code(fn() => $fm->crossCopy('local', 'dir', 'other', 'dir')) === 'cross_disk_dir_unsupported:400', 'cross copy of a folder -> 400');
ok(code(fn() => $fm->crossMove('local', 'dir', 'other', 'dir')) === 'cross_disk_dir_unsupported:400', 'cross move of a folder -> 400');
rrm($root); rrm($root . '-b');

echo "\n" . ($fail === 0 ? "All tests passed!\n" : "{$fail} FAILED\n");
exit($fail === 0 ? 0 : 1);
