<?php

/**
 * Zip download (Phase 1 #5, M1). zipManifest() is the testable security/expansion
 * core (guards + folder recursion + pre-flight caps); streamZip() is exercised by
 * capturing the output and re-opening it with ZipArchive.
 *
 * Usage: php tests/integration/test-zip.php
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
function expectApi(callable $f, string $code): void {
    try { $f(); throw new \RuntimeException("expected {$code}, no throw"); }
    catch (ApiException $e) { assertEqual($code, $e->getErrorCode(), 'error code'); }
}
/** Sorted list of archive entry names from a manifest. */
function names(array $manifest): array { $n = array_map(fn ($e) => $e['name'], $manifest['entries']); sort($n); return $n; }

/**
 * @param array $opts allowZip(bool) allowDownload(bool) ownerOnly(bool)
 *                    zipMaxMb(int) zipMaxFiles(int) user(string)
 */
function makeZipFM(array $opts = []): array {
    $root = sys_get_temp_dir() . '/ff-zip-' . uniqid();
    @mkdir($root . '/docs/sub', 0777, true);
    file_put_contents($root . '/docs/a.txt', 'aaaa');
    file_put_contents($root . '/docs/sub/b.txt', 'bbbbbb');
    file_put_contents($root . '/photo.jpg', str_repeat('x', 10));

    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $claims = new Claims($opts['user'] ?? 'u1', ['read', 'write'], ['local'], '', 50, null, 0);
    $claims->allowZip = $opts['allowZip'] ?? true;
    $claims->allowDownload = $opts['allowDownload'] ?? true;
    $claims->ownerOnly = $opts['ownerOnly'] ?? false;
    $claims->zipMaxMb = $opts['zipMaxMb'] ?? 0;
    $claims->zipMaxFiles = $opts['zipMaxFiles'] ?? 0;
    $meta = new StorageMetadataHandler($dm);
    $fm = new FileManager($dm, $claims, $meta);
    return [$fm, $root, $meta];
}

echo "\n{$cyan}══ Zip download — Claims ══{$reset}\n\n";

test('Claims defaults + parsing', function () {
    $def = Claims::fromJwtPayload((object) []);
    assertEqual(true, $def->allowZip, 'allow_zip default true');
    assertEqual(true, $def->allowExtract, 'allow_extract default true');
    assertEqual(0, $def->zipMaxMb, 'zip_max_mb default 0 (inherit)');
    assertEqual(0, $def->zipMaxFiles, 'zip_max_files default 0 (inherit)');
    $c = Claims::fromJwtPayload((object) ['allow_zip' => false, 'zip_max_mb' => 10, 'zip_max_files' => 3]);
    assertEqual(false, $c->allowZip);
    assertEqual(10, $c->zipMaxMb);
    assertEqual(3, $c->zipMaxFiles);
});

echo "\n{$cyan}══ Zip download — zipManifest ══{$reset}\n\n";

test('single file → one entry named by basename', function () {
    [$fm] = makeZipFM();
    $m = $fm->zipManifest('local', ['photo.jpg']);
    assertEqual(['photo.jpg'], names($m));
    assertEqual(10, $m['total'], 'total bytes');
});

test('folder → recursive entries, folder name preserved', function () {
    [$fm] = makeZipFM();
    $m = $fm->zipManifest('local', ['docs']);
    assertEqual(['docs/a.txt', 'docs/sub/b.txt'], names($m));
    assertEqual(10, $m['total'], '4 + 6 bytes');
});

test('subfolder → entries relative to the selected folder', function () {
    [$fm] = makeZipFM();
    assertEqual(['sub/b.txt'], names($fm->zipManifest('local', ['docs/sub'])));
});

test('mixed selection (file + folder)', function () {
    [$fm] = makeZipFM();
    $m = $fm->zipManifest('local', ['photo.jpg', 'docs']);
    assertEqual(['docs/a.txt', 'docs/sub/b.txt', 'photo.jpg'], names($m));
    assertEqual(3, $m['count']);
});

test('system paths (_fluxfiles / _variants / .meta.json) are excluded', function () {
    [$fm, $root] = makeZipFM();
    @mkdir($root . '/docs/_variants', 0777, true);
    file_put_contents($root . '/docs/_variants/a_thumb.webp', 'v');
    @mkdir($root . '/_fluxfiles', 0777, true);
    file_put_contents($root . '/_fluxfiles/index.json', '{}');
    $m = $fm->zipManifest('local', ['docs']);
    assertEqual(['docs/a.txt', 'docs/sub/b.txt'], names($m), 'no _variants in zip');
    // Selecting _fluxfiles directly is blocked.
    expectApi(fn () => $fm->zipManifest('local', ['_fluxfiles']), 'system_path');
});

test('duplicate archive names get de-duped', function () {
    [$fm, $root] = makeZipFM();
    @mkdir($root . '/other', 0777, true);
    file_put_contents($root . '/other/a.txt', 'zzz');
    // docs/a.txt and other/a.txt both basename a.txt only when selected as files:
    $m = $fm->zipManifest('local', ['docs/a.txt', 'other/a.txt']);
    $n = names($m);
    assertEqual(2, count($n), 'two entries');
    assertTrue($n[0] !== $n[1], 'names differ after de-dupe');
});

test('oversize selection → 413 zip_too_large (pre-flight, before streaming)', function () {
    [$fm, $root] = makeZipFM(['zipMaxMb' => 1]);          // 1 MB cap
    file_put_contents($root . '/big.bin', str_repeat('x', 2 * 1024 * 1024)); // 2 MB
    expectApi(fn () => $fm->zipManifest('local', ['big.bin']), 'zip_too_large');
});

test('too many files → 413 zip_too_many', function () {
    [$fm] = makeZipFM(['zipMaxFiles' => 1]);
    expectApi(fn () => $fm->zipManifest('local', ['docs']), 'zip_too_many');
});

test('gates: allow_zip / allow_download / empty / missing', function () {
    expectApi(fn () => makeZipFM(['allowZip' => false])[0]->zipManifest('local', ['docs']), 'zip_forbidden');
    expectApi(fn () => makeZipFM(['allowDownload' => false])[0]->zipManifest('local', ['docs']), 'download_forbidden');
    expectApi(fn () => makeZipFM()[0]->zipManifest('local', []), 'zip_empty');
    expectApi(fn () => makeZipFM()[0]->zipManifest('local', ['nope.txt']), 'not_found');
});

test('owner_only: folder/file owned by another user → 403', function () {
    [$fm, $root, $meta] = makeZipFM(['ownerOnly' => true, 'user' => 'me']);
    $meta->save('local', 'docs/a.txt', ['uploaded_by' => 'someone-else']);
    expectApi(fn () => $fm->zipManifest('local', ['docs']), 'owner_only');     // folder tree
    expectApi(fn () => $fm->zipManifest('local', ['docs/a.txt']), 'owner_only'); // single file
});

echo "\n{$cyan}══ Zip download — streamZip produces a valid archive ══{$reset}\n\n";

test('streamZip output opens as a zip with the right entries + bytes', function () {
    [$fm, $root] = makeZipFM();
    ob_start();
    $fm->streamZip('local', ['docs', 'photo.jpg'], 'bundle', false); // sendHeaders=false
    $bytes = ob_get_clean();
    assertTrue(strlen($bytes) > 0, 'got zip bytes');

    $tmp = $root . '/out.zip';
    file_put_contents($tmp, $bytes);
    $za = new \ZipArchive();
    assertTrue($za->open($tmp) === true, 'opens as zip');
    $found = [];
    for ($i = 0; $i < $za->numFiles; $i++) { $found[] = $za->getNameIndex($i); }
    sort($found);
    assertEqual(['docs/a.txt', 'docs/sub/b.txt', 'photo.jpg'], $found, 'entries');
    assertEqual('aaaa', $za->getFromName('docs/a.txt'), 'content of a.txt');
    assertEqual('bbbbbb', $za->getFromName('docs/sub/b.txt'), 'content of b.txt');
    $za->close();
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
