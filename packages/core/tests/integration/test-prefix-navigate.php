<?php

/**
 * Regression: navigating into a subfolder when the token has a path `prefix`.
 *
 * list() returns FULL prefixed keys (e.g. "uploads/user_1/posts") and the UI
 * navigates with those keys. scopedPath() used to prefix again
 * ("uploads/user_1/uploads/user_1/posts") so every subfolder came back empty —
 * only the root showed. This locks in the idempotent fix AND its security
 * boundary (one prefix can't reach another's files).
 *
 * Usage:
 *   php tests/integration/test-prefix-navigate.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

foreach ([__DIR__ . '/../..', __DIR__ . '/../../../..'] as $envDir) {
    if (is_file($envDir . '/.env')) {
        Dotenv\Dotenv::createImmutable($envDir)->safeLoad();
        break;
    }
}

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'Expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertContains(string $needle, string $hay): void { if (strpos($hay, $needle) === false) throw new \RuntimeException("'$hay' does not contain '$needle'"); }
function placeImage(string $root, string $rel): void
{
    $full = $root . '/' . $rel;
    @mkdir(dirname($full), 0777, true);
    $im = imagecreatetruecolor(8, 8);
    imagepng($im, $full);
    imagedestroy($im);
}
function files(array $list): array { return array_values(array_filter($list, fn($e) => ($e['type'] ?? '') === 'file')); }

$root = sys_get_temp_dir() . '/fluxfiles-prefix-' . uniqid();
@mkdir($root, 0777, true);
placeImage($root, 'uploads/user_1/posts/a.png');
placeImage($root, 'uploads/user_1/posts/b.png');
placeImage($root, 'uploads/user_2/secret.png');

$dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
$meta = new StorageMetadataHandler($dm);
// Token scoped to user_1.
$claims = new Claims('user_1', ['read', 'write', 'delete'], ['local'], 'uploads/user_1', 50, null, 0, false);
$fm = new FileManager($dm, $claims, $meta);

echo "{$yellow}► prefix navigation{$reset}\n";

test('root lists the posts folder', function () use ($fm) {
    $dirs = array_filter($fm->list('local', ''), fn($e) => ($e['type'] ?? '') === 'dir');
    $names = array_map(fn($e) => $e['name'], $dirs);
    assertEqual(true, in_array('posts', $names, true), 'root should show posts/ — got ' . json_encode($names));
});

test('navigate with FULL prefixed key returns the files (the bug: was 0)', function () use ($fm) {
    $f = files($fm->list('local', 'uploads/user_1/posts'));
    assertEqual(2, count($f), 'expected 2 files in posts/, got ' . count($f));
});

test('navigate with a relative key also works (idempotent both ways)', function () use ($fm) {
    $f = files($fm->list('local', 'posts'));
    assertEqual(2, count($f), 'expected 2 files, got ' . count($f));
});

test('file URL resolves under the user prefix', function () use ($fm) {
    $f = files($fm->list('local', 'uploads/user_1/posts'));
    assertContains('uploads/user_1/posts/', (string) ($f[0]['url'] ?? ''));
});

echo "{$yellow}► prefix isolation (security){$reset}\n";

test("user_1 cannot list user_2's folder (sandboxed → empty)", function () use ($fm) {
    // "uploads/user_2" is prefixed into "uploads/user_1/uploads/user_2" → does not exist.
    $f = files($fm->list('local', 'uploads/user_2'));
    assertEqual(0, count($f), 'user_1 must not see user_2 files, got ' . count($f));
});

test('.. inside a prefixed path cannot escape to user_2', function () use ($fm) {
    // ".." stripped → "uploads/user_1/uploads/user_2" (a non-existent subfolder of user_1)
    $f = files($fm->list('local', 'uploads/user_1/../uploads/user_2'));
    assertEqual(0, count($f), 'traversal must not reach user_2, got ' . count($f));
});

// cleanup
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($root);

echo "\n" . ($failed === 0 ? "{$green}All {$passed} tests passed!{$reset}" : "{$red}{$failed} of " . ($passed + $failed) . " failed{$reset}") . "\n";
exit($failed > 0 ? 1 : 0);
