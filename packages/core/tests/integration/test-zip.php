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
use FluxFiles\QuotaManager;
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

echo "\n{$cyan}══ Extract — FileManager::extractZip ══{$reset}\n\n";

/** @param array $opts allowExtract zipMaxMb zipMaxFiles maxStorageMb quota(bool) allowedExt user */
function makeExtractFM(array $opts = []): array {
    $root = sys_get_temp_dir() . '/ff-ext-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $claims = new Claims($opts['user'] ?? 'u', ['read', 'write'], ['local'], '', 50, $opts['allowedExt'] ?? null, 0);
    $claims->allowExtract = $opts['allowExtract'] ?? true;
    $claims->zipMaxMb = $opts['zipMaxMb'] ?? 0;
    $claims->zipMaxFiles = $opts['zipMaxFiles'] ?? 0;
    $claims->maxStorageMb = $opts['maxStorageMb'] ?? 0;
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    if ($opts['quota'] ?? false) { $fm->setQuotaManager(new QuotaManager($dm)); }
    return [$fm, $root];
}
/** Write a fixture zip at $root/$rel with the given name=>content entries. */
function putZip(string $root, string $rel, array $entries): void {
    $abs = $root . '/' . $rel;
    @mkdir(dirname($abs), 0777, true);
    $za = new \ZipArchive();
    $za->open($abs, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) { $za->addFromString($name, $content); }
    $za->close();
}

test('extract → files written to the default dest (archive name), tree preserved', function () {
    [$fm, $root] = makeExtractFM();
    putZip($root, 'archive.zip', ['a.txt' => 'AA', 'sub/b.txt' => 'BB']);
    $r = $fm->extractZip('local', 'archive.zip');
    assertEqual('archive', $r['dest'], 'default dest = archive name');
    assertEqual(2, $r['extracted'], 'two files');
    assertEqual('AA', file_get_contents($root . '/archive/a.txt'));
    assertEqual('BB', file_get_contents($root . '/archive/sub/b.txt'));
});

test('custom dest folder', function () {
    [$fm, $root] = makeExtractFM();
    putZip($root, 'a.zip', ['x.txt' => 'X']);
    $r = $fm->extractZip('local', 'a.zip', 'unpacked/here');
    assertEqual('unpacked/here', $r['dest']);
    assertEqual('X', file_get_contents($root . '/unpacked/here/x.txt'));
});

test('zip-slip entry (../) → 403 zip_slip, nothing written (atomic pass-1 validation)', function () {
    [$fm, $root] = makeExtractFM();
    putZip($root, 'evil.zip', ['../escape.txt' => 'bad', 'ok.txt' => 'good']);
    expectApi(fn () => $fm->extractZip('local', 'evil.zip'), 'zip_slip');
    assertTrue(!file_exists($root . '/escape.txt'), 'no escaped file');
    assertTrue(!file_exists($root . '/evil/ok.txt'), 'no partial extraction');
});

test('dangerous extension entry (.php) → 403 ext_dangerous, nothing written', function () {
    [$fm, $root] = makeExtractFM();
    putZip($root, 'm.zip', ['ok.txt' => 'x', 'shell.php' => '<?php']);
    expectApi(fn () => $fm->extractZip('local', 'm.zip'), 'ext_dangerous');
    assertTrue(!file_exists($root . '/m/ok.txt'), 'no partial extraction before the bad entry');
});

test('entry targeting a system path (_fluxfiles/) → 403 system_path', function () {
    [$fm, $root] = makeExtractFM();
    putZip($root, 's.zip', ['_fluxfiles/hack.json' => '{}']);
    expectApi(fn () => $fm->extractZip('local', 's.zip'), 'system_path');
});

test('bomb: too many files → 413 zip_too_many', function () {
    [$fm, $root] = makeExtractFM(['zipMaxFiles' => 1]);
    putZip($root, 'many.zip', ['a.txt' => '1', 'b.txt' => '2']);
    expectApi(fn () => $fm->extractZip('local', 'many.zip'), 'zip_too_many');
});

test('bomb: total uncompressed too large → 413 zip_too_large', function () {
    [$fm, $root] = makeExtractFM(['zipMaxMb' => 1]);
    putZip($root, 'big.zip', ['big.bin' => str_repeat('x', 2 * 1024 * 1024)]);
    expectApi(fn () => $fm->extractZip('local', 'big.zip'), 'zip_too_large');
});

test('quota exceeded on total uncompressed → 413 quota_exceeded', function () {
    [$fm, $root] = makeExtractFM(['quota' => true, 'maxStorageMb' => 1]);
    putZip($root, 'q.zip', ['big.bin' => str_repeat('y', 2 * 1024 * 1024)]); // < bomb cap, > 1MB quota
    expectApi(fn () => $fm->extractZip('local', 'q.zip'), 'quota_exceeded');
});

test('gates: allow_extract / not-a-zip / missing / allowed_ext', function () {
    [$off, $r1] = makeExtractFM(['allowExtract' => false]);
    putZip($r1, 'a.zip', ['x.txt' => 'x']);
    expectApi(fn () => $off->extractZip('local', 'a.zip'), 'extract_forbidden');

    [$fm, $r2] = makeExtractFM();
    file_put_contents($r2 . '/notes.txt', 'x');
    expectApi(fn () => $fm->extractZip('local', 'notes.txt'), 'not_a_zip');
    expectApi(fn () => $fm->extractZip('local', 'nope.zip'), 'not_found');

    [$ext, $r3] = makeExtractFM(['allowedExt' => ['txt']]);
    putZip($r3, 'mixed.zip', ['ok.txt' => 'x', 'pic.jpg' => 'y']);
    expectApi(fn () => $ext->extractZip('local', 'mixed.zip'), 'ext_not_allowed');
});

// ── F3: extracted files join the upload pipeline (metadata + index + variants) ──
test('extract registers files: metadata + search index + image variants', function () {
    $root = sys_get_temp_dir() . '/ff-ext-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $meta = new StorageMetadataHandler($dm);
    $claims = new Claims('alice', ['read', 'write'], ['local'], '', 50, null, 0);
    $fm = new FileManager($dm, $claims, $meta);

    // a real PNG (big enough to generate variants) + a text file
    $im = imagecreatetruecolor(400, 300); imagefilledrectangle($im, 0, 0, 399, 299, imagecolorallocate($im, 10, 120, 200));
    ob_start(); imagepng($im); $png = ob_get_clean(); imagedestroy($im);
    putZip($root, 'media.zip', ['photo.png' => $png, 'readme.txt' => 'hi']);

    $r = $fm->extractZip('local', 'media.zip');
    assertEqual(2, $r['extracted'], 'two extracted');

    // metadata recorded (owner + dims) → also means it's in the search index
    $m = $meta->get('local', 'media/photo.png');
    assertTrue(is_array($m), 'metadata sidecar saved for extracted image');
    assertEqual('alice', $m['uploaded_by'] ?? null, 'owner tagged');
    // image variants (thumbnails) generated on disk
    assertTrue(is_dir($root . '/media/_variants'), '_variants dir created');
    assertTrue(count(glob($root . '/media/_variants/photo*') ?: []) > 0, 'variant files generated');
    // indexed for search, with dimensions (the index carries width/size, not the sidecar)
    $hits = $meta->search('local', 'photo', 50);
    assertTrue(!empty($hits), 'extracted file appears in search');
    assertEqual(400, $hits[0]['width'] ?? null, 'image width indexed');
});

// ── F2: extract honours owner_only + the collision policy on existing files ──
test('extract over another user\'s file with owner_only → 403, original untouched', function () {
    $root = sys_get_temp_dir() . '/ff-ext-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $meta = new StorageMetadataHandler($dm);
    @mkdir($root . '/out', 0777, true);
    file_put_contents($root . '/out/keep.txt', 'BOB-OWNED');
    $meta->save('local', 'out/keep.txt', ['uploaded_by' => 'bob']);

    $alice = new Claims('alice', ['read', 'write'], ['local'], '', 50, null, 0);
    $alice->ownerOnly = true;
    $fm = new FileManager($dm, $alice, $meta);
    putZip($root, 'a.zip', ['keep.txt' => 'ALICE-NEW']);
    expectApi(fn () => $fm->extractZip('local', 'a.zip', 'out'), 'owner_only');
    assertEqual('BOB-OWNED', file_get_contents($root . '/out/keep.txt'), 'original not clobbered');
});

test('extract collision: default rename keeps both; overwrite replaces', function () {
    // rename (default) — extracting "keep.txt" beside an existing one keeps both
    [$fm, $root] = makeExtractFM();
    @mkdir($root . '/out', 0777, true);
    file_put_contents($root . '/out/keep.txt', 'OLD');
    putZip($root, 'a.zip', ['keep.txt' => 'NEW']);
    $fm->extractZip('local', 'a.zip', 'out');
    assertEqual('OLD', file_get_contents($root . '/out/keep.txt'), 'original kept');
    assertEqual('NEW', file_get_contents($root . '/out/keep (1).txt'), 'renamed copy written');

    // overwrite — replaces in place
    $root2 = sys_get_temp_dir() . '/ff-ext-' . uniqid();
    @mkdir($root2 . '/out', 0777, true);
    $dm2 = new DiskManager(['local' => ['driver' => 'local', 'root' => $root2, 'url' => '/s']]);
    $c2 = new Claims('u', ['read', 'write'], ['local'], '', 50, null, 0);
    $c2->allowExtract = true; $c2->uploadCollision = 'overwrite';
    $fm2 = new FileManager($dm2, $c2, new StorageMetadataHandler($dm2));
    file_put_contents($root2 . '/out/keep.txt', 'OLD');
    putZip($root2, 'b.zip', ['keep.txt' => 'NEW']);
    $fm2->extractZip('local', 'b.zip', 'out');
    assertEqual('NEW', file_get_contents($root2 . '/out/keep.txt'), 'overwritten in place');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
