<?php

/**
 * Virus scan wiring (paid Virus module). The seam is FileManager::setVirusScanner —
 * index.php sets it whenever the `allow_virus_scan` claim is on, and resolves the
 * module gate INSIDE the callback so a tenant who asked for scanning but has no
 * working module fails the write instead of storing unscanned files.
 *
 * The real module is a proprietary package absent from this MIT checkout, so we inject
 * FAKE scanners to drive every branch: clean, infected, and a scanner that throws
 * (no engine / expired licence / engine down). What the assertions actually pin down
 * is the invariant the feature exists for: **no unscanned or infected byte is ever
 * written to storage**.
 *
 * Usage: php tests/integration/test-virus-scan.php
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
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
/** @return ApiException */
function expectApi(callable $f, int $code, string $errCode) {
    try { $f(); throw new \RuntimeException("expected {$code}/{$errCode}, no throw"); }
    catch (ApiException $e) {
        assertEqual($code, $e->getHttpCode(), 'http code');
        assertEqual($errCode, $e->getErrorCode(), 'error code');
        return $e;
    }
}

/** A FileManager over a fresh temp disk; $scanner is the virus hook (null = unwired). */
function makeFM(?callable $scanner, bool $codeEdit = false): array {
    $root = sys_get_temp_dir() . '/ff-virus-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $claims = new Claims('u', ['read', 'write'], ['local'], '', 50, null, 0);
    $claims->allowCodeEdit = $codeEdit;
    $claims->allowZip = true;
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    if ($scanner !== null) { $fm->setVirusScanner($scanner); }
    return [$fm, $dm, $root];
}
function uploadFile(string $name, string $bytes): array {
    $tmp = tempnam(sys_get_temp_dir(), 'ffup');
    file_put_contents($tmp, $bytes);
    return ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($bytes), 'type' => 'text/plain'];
}
/** Marks any file whose bytes contain NEEDLE as infected — the shape the module returns. */
const NEEDLE = 'X5O!P%@AP-FAKE-TEST-SIGNATURE';
function fakeScanner(array &$seen = null): callable {
    return function (string $path) use (&$seen): array {
        if ($seen !== null) { $seen[] = basename($path); }
        $bytes = (string) @file_get_contents($path);
        return str_contains($bytes, NEEDLE)
            ? ['clean' => false, 'threat' => 'Test.Fake.Signature']
            : ['clean' => true, 'threat' => null];
    };
}

echo "\n{$cyan}══ Virus scan wiring (paid module seam) ══{$reset}\n\n";

// ── Claim ────────────────────────────────────────────────────────────────────
test('allow_virus_scan claim: default off, parsed when set', function () {
    assertEqual(false, Claims::fromJwtPayload((object) [])->allowVirusScan);
    assertEqual(true, Claims::fromJwtPayload((object) ['allow_virus_scan' => true])->allowVirusScan);
    // isAllowed() drives ModuleRegistry's 403 <claim>_forbidden path.
    assertEqual(true, Claims::fromJwtPayload((object) ['allow_virus_scan' => true])->isAllowed('allow_virus_scan'));
});

// ── upload() ─────────────────────────────────────────────────────────────────
test('no scanner wired → upload untouched (free core pays nothing)', function () {
    [$fm, $dm] = makeFM(null);
    $r = $fm->upload('local', '', uploadFile('ok.txt', 'hello'));
    assertEqual('ok.txt', $r['name']);
    assertTrue($dm->disk('local')->fileExists('ok.txt'), 'file should be stored');
});

test('clean upload passes the scanner and is stored', function () {
    $seen = [];
    [$fm, $dm] = makeFM(fakeScanner($seen));
    $fm->upload('local', '', uploadFile('ok.txt', 'hello'));
    assertTrue($dm->disk('local')->fileExists('ok.txt'), 'file should be stored');
    assertEqual(1, count($seen), 'scanner should be called exactly once');
});

test('infected upload → 422 virus_detected, and NOTHING is written', function () {
    [$fm, $dm] = makeFM(fakeScanner());
    $e = expectApi(fn () => $fm->upload('local', '', uploadFile('bad.txt', 'a' . NEEDLE . 'b')), 422, 'virus_detected');
    assertEqual('bad.txt', $e->getErrorParams()['name'] ?? null, 'name in params');
    assertEqual('Test.Fake.Signature', $e->getErrorParams()['threat'] ?? null, 'threat in params');
    assertTrue(!$dm->disk('local')->fileExists('bad.txt'), 'infected file must NOT be stored');
});

test('scanner throwing (no engine / expired licence) FAILS CLOSED — no file stored', function () {
    $thrower = function (string $p): array {
        throw new ApiException('No virus scanner configured', 501, 'virus_unavailable');
    };
    [$fm, $dm] = makeFM($thrower);
    expectApi(fn () => $fm->upload('local', '', uploadFile('ok.txt', 'harmless')), 501, 'virus_unavailable');
    assertTrue(!$dm->disk('local')->fileExists('ok.txt'), 'unscanned file must NOT be stored');
});

test('a malformed verdict is treated as NOT clean (never fail open)', function () {
    [$fm, $dm] = makeFM(fn (string $p): array => ['threat' => null]); // no 'clean' key
    expectApi(fn () => $fm->upload('local', '', uploadFile('ok.txt', 'harmless')), 422, 'virus_detected');
    assertTrue(!$dm->disk('local')->fileExists('ok.txt'), 'file must NOT be stored');
});

test('scan runs BEFORE the metadata/search index is touched', function () {
    [$fm, $dm] = makeFM(fakeScanner());
    $meta = new StorageMetadataHandler($dm);
    expectApi(fn () => $fm->upload('local', '', uploadFile('bad.txt', NEEDLE)), 422, 'virus_detected');
    // A file that was never written must leave no search-index entry behind.
    assertEqual(0, count($meta->search('local', 'bad')), 'rejected upload must not be indexed');
});

// ── putContent() (code editor) ───────────────────────────────────────────────
test('code-editor save is scanned; infected content is refused and the file is unchanged', function () {
    [$fm, $dm] = makeFM(fakeScanner(), true);
    $fm->upload('local', '', uploadFile('app.txt', 'original'));
    expectApi(fn () => $fm->putContent('local', 'app.txt', 'webshell ' . NEEDLE), 422, 'virus_detected');
    assertEqual('original', (string) $dm->disk('local')->read('app.txt'), 'content must be untouched');
});

test('code-editor save of clean content still works', function () {
    [$fm, $dm] = makeFM(fakeScanner(), true);
    $fm->upload('local', '', uploadFile('app.txt', 'original'));
    $fm->putContent('local', 'app.txt', 'updated');
    assertEqual('updated', (string) $dm->disk('local')->read('app.txt'));
});

// ── extractZip() ─────────────────────────────────────────────────────────────
test('zip extract scans every entry; an infected entry aborts and is never written', function () {
    if (!class_exists('ZipArchive')) { return; } // ext-zip absent → nothing to assert
    // Wire the scanner only AFTER the archive is stored: a real scanner reads inside
    // archives and would reject this one at upload (asserted separately below), but
    // the entry-level scan must also hold for archives that predate the module.
    [$fm, $dm, $root] = makeFM(null);

    $zipPath = sys_get_temp_dir() . '/ffz-' . uniqid() . '.zip';
    $za = new \ZipArchive();
    $za->open($zipPath, \ZipArchive::CREATE);
    $za->addFromString('a-clean.txt', 'harmless');
    $za->addFromString('b-bad.txt', NEEDLE);
    $za->close();
    $fm->upload('local', '', ['name' => 'payload.zip', 'tmp_name' => $zipPath,
        'size' => filesize($zipPath), 'type' => 'application/zip']);
    $fm->setVirusScanner(fakeScanner());

    $e = expectApi(fn () => $fm->extractZip('local', 'payload.zip', 'out'), 422, 'virus_detected');
    assertEqual('b-bad.txt', $e->getErrorParams()['entry'] ?? null, 'the offending entry is named');
    $fs = $dm->disk('local');
    assertTrue(!$fs->fileExists('out/b-bad.txt'), 'infected entry must NOT be written');
    // Entries written before the threat were each scanned clean — that is the guarantee.
    assertTrue($fs->fileExists('out/a-clean.txt'), 'clean entries extracted before the abort remain');
});

test('an archive carrying a threat is refused at UPLOAD (scanners read inside archives)', function () {
    if (!class_exists('ZipArchive')) { return; }
    [$fm, $dm, $root] = makeFM(fakeScanner());
    $zipPath = sys_get_temp_dir() . '/ffz-' . uniqid() . '.zip';
    $za = new \ZipArchive();
    $za->open($zipPath, \ZipArchive::CREATE);
    $za->addFromString('bad.txt', NEEDLE);
    $za->close();
    expectApi(fn () => $fm->upload('local', '', ['name' => 'payload2.zip', 'tmp_name' => $zipPath,
        'size' => filesize($zipPath), 'type' => 'application/zip']), 422, 'virus_detected');
    assertTrue(!$dm->disk('local')->fileExists('payload2.zip'), 'the archive must NOT be stored');
});

test('zip extract with no threats writes every entry', function () {
    if (!class_exists('ZipArchive')) { return; }
    [$fm, $dm, $root] = makeFM(fakeScanner());
    $zipPath = sys_get_temp_dir() . '/ffz-' . uniqid() . '.zip';
    $za = new \ZipArchive();
    $za->open($zipPath, \ZipArchive::CREATE);
    $za->addFromString('one.txt', 'a');
    $za->addFromString('two.txt', 'b');
    $za->close();
    $fm->upload('local', '', ['name' => 'clean.zip', 'tmp_name' => $zipPath,
        'size' => filesize($zipPath), 'type' => 'application/zip']);
    $r = $fm->extractZip('local', 'clean.zip', 'out');
    assertEqual(2, $r['extracted']);
    assertTrue($dm->disk('local')->fileExists('out/one.txt') && $dm->disk('local')->fileExists('out/two.txt'));
});

// ── the scanner must not consume the caller's file ───────────────────────────
test('scanning does not delete or truncate the upload temp', function () {
    $paths = [];
    [$fm, $dm] = makeFM(function (string $p) use (&$paths): array {
        $paths[] = $p;
        assertTrue(is_file($p), 'scanner receives a readable path');
        return ['clean' => true, 'threat' => null];
    });
    $fm->upload('local', '', uploadFile('keep.txt', 'payload-bytes'));
    assertEqual('payload-bytes', (string) $dm->disk('local')->read('keep.txt'), 'bytes survive the scan');
});

// ── writeScopedFile() (the module write seam: AI Vision, C2PA) ───────────────
// These modules validate the path with validateUserPath()/assertCanModifyScopedPath()
// but must route the actual write through writeScopedFile() to inherit allowed_ext,
// the dangerous-extension check, and virus scanning — the same guarantees every
// other write path already has. A module reaching for $fs->write() directly would
// silently skip all three.
test('writeScopedFile: clean content passes the scanner and is stored', function () {
    [$fm, $dm] = makeFM(fakeScanner());
    $scoped = $fm->validateUserPath('out.png');
    $fm->writeScopedFile('local', $scoped, 'clean-bytes');
    assertEqual('clean-bytes', (string) $dm->disk('local')->read('out.png'));
});

test('writeScopedFile: infected content is refused and nothing is written', function () {
    [$fm, $dm] = makeFM(fakeScanner());
    $scoped = $fm->validateUserPath('out.png');
    expectApi(fn () => $fm->writeScopedFile('local', $scoped, 'payload ' . NEEDLE), 422, 'virus_detected');
    assertTrue(!$dm->disk('local')->fileExists('out.png'), 'infected content must NOT be stored');
});

test('writeScopedFile: a dangerous double extension is rejected', function () {
    [$fm, $dm] = makeFM(fakeScanner());
    $scoped = $fm->validateUserPath('shell.php.jpg');
    expectApi(fn () => $fm->writeScopedFile('local', $scoped, 'x'), 403, 'ext_dangerous');
    assertTrue(!$dm->disk('local')->fileExists('shell.php.jpg'));
});

test('writeScopedFile: honours the allowed_ext claim', function () {
    $root = sys_get_temp_dir() . '/ff-virus-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $claims = new Claims('u', ['read', 'write'], ['local'], '', 50, ['png'], 0);
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    $fm->setVirusScanner(fakeScanner());
    $scoped = $fm->validateUserPath('out.exe');
    expectApi(fn () => $fm->writeScopedFile('local', $scoped, 'x'), 403, 'ext_not_allowed');
    assertTrue(!$dm->disk('local')->fileExists('out.exe'));
});

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
