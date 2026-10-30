<?php

/**
 * Defense in depth for issue #176: on LOCAL disks, every `_fluxfiles/` dir core
 * writes into (disk root and per tenant prefix) gets a deny-all .htaccess and an
 * empty index.html, so a disk root inside the web root doesn't serve the audit
 * log / index / trash as static files. Never overwrites an operator's file;
 * never written for non-local disks; invisible to trash list / duplicates.
 *
 * Usage:
 *   php tests/integration/test-fluxfiles-dir-guard.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\FluxDirGuard;
use FluxFiles\GuardedLocalAdapter;
use FluxFiles\StorageMetadataHandler;

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

function makeFM(string $prefix = ''): array
{
    FluxDirGuard::reset();
    $root = sys_get_temp_dir() . '/fluxfiles-guard-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $meta = new StorageMetadataHandler($dm);
    $claims = new Claims('tester', ['read', 'write', 'delete'], ['local'], $prefix, 50, null, 0, false);
    return [new FileManager($dm, $claims, $meta), $dm->disk('local'), $root, $dm];
}

function upload(FileManager $fm, string $name, string $content): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'fxg');
    file_put_contents($tmp, $content);
    return $fm->upload('local', '', ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'type' => 'text/plain', 'error' => 0]);
}

echo "\n{$cyan}══ FluxFiles _fluxfiles/ guard files ══{$reset}\n\n";

test('first upload creates _fluxfiles/.htaccess (deny all) and empty index.html', function () {
    [$fm, , $root] = makeFM();
    upload($fm, 'a.txt', 'hello');
    assertTrue(is_file("$root/_fluxfiles/.htaccess"), '.htaccess missing');
    $h = (string) file_get_contents("$root/_fluxfiles/.htaccess");
    assertTrue(str_contains($h, 'Require all denied'), 'Apache 2.4 rule');
    assertTrue(str_contains($h, 'Deny from all'), 'Apache 2.2 rule');
    assertTrue(is_file("$root/_fluxfiles/index.html"), 'index.html missing');
    assertEqual('', (string) file_get_contents("$root/_fluxfiles/index.html"), 'index.html must be empty');
});

test('prefixed tenant gets guard files in <prefix>/_fluxfiles/', function () {
    [$fm, $fs, $root] = makeFM('tenant1');
    upload($fm, 'a.txt', 'hello');
    // Per-tenant bookkeeping (usage.json / fixity.json / optimize.json) lives under the prefix.
    \FluxFiles\OptimizeStats::record($fs, 'tenant1', 100, 1);
    assertTrue(is_dir("$root/tenant1/_fluxfiles"), 'tenant bookkeeping dir expected');
    assertTrue(is_file("$root/tenant1/_fluxfiles/.htaccess"), 'tenant .htaccess');
    assertTrue(is_file("$root/tenant1/_fluxfiles/index.html"), 'tenant index.html');
    // The disk-root audit/index dir is guarded too.
    assertTrue(is_file("$root/_fluxfiles/.htaccess"), 'root .htaccess');
});

test('an existing customised .htaccess / index.html is never overwritten', function () {
    FluxDirGuard::reset();
    $root = sys_get_temp_dir() . '/fluxfiles-guard-' . uniqid();
    mkdir("$root/_fluxfiles", 0777, true);
    file_put_contents("$root/_fluxfiles/.htaccess", "# mine\n");
    file_put_contents("$root/_fluxfiles/index.html", 'custom');
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $fm = new FileManager($dm, new Claims('t', ['read', 'write'], ['local'], '', 50, null, 0, false), new StorageMetadataHandler($dm));
    upload($fm, 'a.txt', 'x');
    assertEqual("# mine\n", (string) file_get_contents("$root/_fluxfiles/.htaccess"), 'htaccess preserved');
    assertEqual('custom', (string) file_get_contents("$root/_fluxfiles/index.html"), 'index preserved');
});

test('trash → listTrash and duplicates ignore the guard files', function () {
    [$fm, $fs, $root] = makeFM();
    upload($fm, 'a.txt', 'same-bytes');
    upload($fm, 'b.txt', 'same-bytes');
    upload($fm, 'c.txt', 'other');
    $r = $fm->trash('local', 'c.txt');
    assertTrue(is_file("$root/_fluxfiles/trash/{$r['trash_id']}/c.txt"), 'payload in trash');
    $list = $fm->listTrash('local');
    assertEqual(1, count($list), 'one trash entry');
    assertEqual('c.txt', $list[0]['name'], 'trash entry name');
    $dups = $fm->findDuplicates('local');
    $names = json_encode($dups);
    assertTrue(!str_contains($names, 'htaccess') && !str_contains($names, 'index.html'), 'guard files leaked into duplicates');
    assertEqual(1, count($dups['groups'] ?? $dups), 'exactly one duplicate group (a,b)');
    $res = $fm->list('local', '');
    assertTrue(!str_contains(json_encode($res), '_fluxfiles'), '_fluxfiles hidden from list');
});

test('local disks are wrapped, non-local filesystems are never written to', function () {
    [, , , $dm] = makeFM();
    $ad = new ReflectionProperty(\League\Flysystem\Filesystem::class, 'adapter');
    assertTrue($ad->getValue($dm->disk('local')) instanceof GuardedLocalAdapter, 'local disk guarded');
    // Plain (non-guarded) filesystem standing in for an object store: no guard files.
    $root = sys_get_temp_dir() . '/fluxfiles-guard-obj-' . uniqid();
    mkdir($root, 0777, true);
    $fs = new \League\Flysystem\Filesystem(new \League\Flysystem\Local\LocalFilesystemAdapter($root));
    $fs->write('_fluxfiles/index.json', '{}');
    assertTrue(!file_exists("$root/_fluxfiles/.htaccess"), 'no .htaccess without the local guard');
    // The s3/sftp builders never wrap: DiskManager::build only wraps in the 'local' branch.
    $src = (string) file_get_contents(__DIR__ . '/../../api/DiskManager.php');
    assertEqual(1, substr_count($src, 'new GuardedLocalAdapter('), 'only the local branch wraps');
});

test('on a public disk, upload folders are 0755 but every _fluxfiles/ dir is 0700', function () {
    // Public-disk folders are 0755 so a web server running as another user can
    // serve uploads; _fluxfiles/ (audit log, index, trash bytes) must stay closed.
    $old = umask(022);
    try {
        foreach (['', 'tenant/'] as $prefix) {
            [$fm, , $root] = makeFM($prefix);
            $tmp = tempnam(sys_get_temp_dir(), 'fxg');
            file_put_contents($tmp, 'x');
            $fm->upload('local', 'sub', ['name' => 'a.txt', 'tmp_name' => $tmp, 'size' => 1, 'type' => 'text/plain', 'error' => 0]);
            $fm->trash('local', 'sub/a.txt');
            $base = $root . '/' . rtrim($prefix, '/');
            assertEqual(0755, fileperms(rtrim($base, '/') . '/sub') & 0777, "{$prefix}sub");
            foreach (array_filter([$root . '/_fluxfiles', $prefix !== '' ? $base . '/_fluxfiles' : null]) as $d) {
                if (is_dir($d)) {
                    assertEqual(0700, fileperms($d) & 0777, $d);
                }
            }
        }
    } finally {
        umask($old);
    }
});

echo "\n  {$passed} passed, {$failed} failed\n\n";
exit($failed > 0 ? 1 : 0);
