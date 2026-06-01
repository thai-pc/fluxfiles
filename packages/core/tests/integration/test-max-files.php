<?php

/**
 * max_files claim — total file-count limit per prefix (server enforcement).
 *
 * Uploading is one-request-per-file, so the server caps the TOTAL number of
 * user-visible files under the prefix. Internal _fluxfiles/ / _variants/ paths
 * are excluded from the count.
 *
 * Usage:
 *   php tests/integration/test-max-files.php
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
use FluxFiles\ApiException;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\QuotaManager;
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

/** A tiny PNG in a $_FILES-style array, written to a temp file. */
function pngUpload(string $name): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'ff');
    $im = imagecreatetruecolor(4, 4);
    imagepng($im, $tmp);
    imagedestroy($im);
    return ['name' => $name, 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp)];
}

function makeFm(string $root, int $maxFiles): FileManager
{
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $meta = new StorageMetadataHandler($dm);
    // Claims: maxFiles is the 10th positional arg.
    $claims = new Claims('u1', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, false, [], $maxFiles);
    $fm = new FileManager($dm, $claims, $meta);
    $fm->setQuotaManager(new QuotaManager($dm));
    return $fm;
}

$root = sys_get_temp_dir() . '/fluxfiles-maxfiles-' . uniqid();
@mkdir($root, 0777, true);

echo "{$yellow}► max_files enforcement{$reset}\n";

test('upload up to the limit succeeds, the next one is rejected (413 too_many_files)', function () use ($root) {
    $fm = makeFm($root, 2);
    $r1 = $fm->upload('local', '', pngUpload('a.png'), true);
    assertEqual(false, isset($r1['duplicate']) && $r1['duplicate'], 'a.png should upload');
    $fm->upload('local', '', pngUpload('b.png'), true);

    $code = 0; $errCode = '';
    try {
        $fm->upload('local', '', pngUpload('c.png'), true);
    } catch (ApiException $e) {
        $code = $e->getHttpCode();
        $errCode = $e->getErrorCode();
    }
    assertEqual(413, $code, '3rd upload should be 413, got ' . $code);
    assertEqual('too_many_files', $errCode, 'error_code should be too_many_files');
});

test('max_files = 0 means unlimited', function () use ($root) {
    $sub = $root . '/unlimited';
    @mkdir($sub, 0777, true);
    $fm = makeFm($sub, 0);
    for ($i = 0; $i < 5; $i++) {
        $fm->upload('local', '', pngUpload("f{$i}.png"), true);
    }
    // No exception → pass
    assertEqual(true, true);
});

echo "{$yellow}► getFileCount{$reset}\n";

test('getFileCount counts user files but skips _fluxfiles/ and _variants/', function () use ($root) {
    $sub = $root . '/count';
    @mkdir($sub . '/_fluxfiles/meta', 0777, true);
    @mkdir($sub . '/_variants', 0777, true);
    file_put_contents($sub . '/real1.png', 'x');
    file_put_contents($sub . '/real2.png', 'x');
    file_put_contents($sub . '/_fluxfiles/index.json', '{}');
    file_put_contents($sub . '/_fluxfiles/meta/x.json', '{}');
    file_put_contents($sub . '/_variants/thumb.webp', 'x');

    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $sub, 'url' => '/s']]);
    $qm = new QuotaManager($dm);
    assertEqual(2, $qm->getFileCount('local', ''), 'should count only the 2 real files');
});

// cleanup
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($root);

echo "\n" . ($failed === 0 ? "{$green}All {$passed} tests passed!{$reset}" : "{$red}{$failed} of " . ($passed + $failed) . " failed{$reset}") . "\n";
exit($failed > 0 ? 1 : 0);
