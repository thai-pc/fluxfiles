<?php

/**
 * DLP / PII scan wiring (paid DLP module, docs/DLP-PII-REDACTION-DESIGN.md). Mirrors
 * tests/integration/test-virus-scan.php's shape exactly — the seam is
 * FileManager::setDlpScanner()/assertNoPii(). index.php sets it whenever the
 * `allow_dlp_scan` claim is on, and resolves the module gate INSIDE the callback so a
 * tenant who asked for scanning but has no working module fails the write instead of
 * storing unscanned files.
 *
 * The real module is a proprietary package (packages/dlp/, present in this checkout
 * but its class isn't autoloaded into packages/core/vendor), so we inject FAKE
 * scanners here to drive every branch: clean, PII-detected, and a scanner that throws
 * (no engine / expired licence / engine down). What the assertions pin down is the
 * invariant the feature exists for: **no unscanned or PII-bearing byte is ever written
 * to storage**, AND (the property unique to DLP vs. Virus) **no raw matched text ever
 * reaches the response** — only deduped entity-TYPE names do.
 *
 * Also covers the one structural addition beyond a straight copy of Virus: the
 * extension/size eligibility pre-filter (§2.1/§9.3) that must skip ineligible files
 * WITHOUT ever invoking the (possibly-down) scanner.
 *
 * Usage: php tests/integration/test-dlp.php
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

/** A FileManager over a fresh temp disk; $scanner is the DLP hook (null = unwired). */
function makeFM(?callable $scanner, bool $codeEdit = false, array $claimOverrides = []): array {
    $root = sys_get_temp_dir() . '/ff-dlp-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $claims = Claims::fromJwtPayload((object) array_merge([
        'sub' => 'u', 'perms' => ['read', 'write'], 'disks' => ['local'],
        'allow_code_edit' => $codeEdit, 'allow_zip' => true,
    ], $claimOverrides));
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    if ($scanner !== null) { $fm->setDlpScanner($scanner); }
    return [$fm, $dm, $root, $claims];
}
function uploadFile(string $name, string $bytes): array {
    $tmp = tempnam(sys_get_temp_dir(), 'ffup');
    file_put_contents($tmp, $bytes);
    return ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($bytes), 'type' => 'text/plain'];
}

// The needle a fake fixture "contains PII" — never allowed to reach any response.
const FAKE_SSN = '123-45-6789';

/** Marks any file whose bytes contain FAKE_SSN as PII-bearing — the shape the real
 *  module returns per §6.2: 'entities' carries ONLY deduped type names, never text. */
function fakeScanner(array &$seen = null): callable {
    return function (string $path, string $name) use (&$seen): array {
        if ($seen !== null) { $seen[] = basename($path); }
        $bytes = (string) @file_get_contents($path);
        return str_contains($bytes, FAKE_SSN)
            ? ['clean' => false, 'entities' => ['US_SSN']]
            : ['clean' => true, 'entities' => []];
    };
}

echo "\n{$cyan}══ DLP / PII scan wiring (paid module seam) ══{$reset}\n\n";

// ── Claim ────────────────────────────────────────────────────────────────────
test('allow_dlp_scan claim: default off, parsed when set', function () {
    assertEqual(false, Claims::fromJwtPayload((object) [])->allowDlpScan);
    assertEqual(true, Claims::fromJwtPayload((object) ['allow_dlp_scan' => true])->allowDlpScan);
    assertEqual(true, Claims::fromJwtPayload((object) ['allow_dlp_scan' => true])->isAllowed('allow_dlp_scan'));
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

test('PII-bearing upload → 422 pii_detected, entities carried, NOTHING is written', function () {
    [$fm, $dm] = makeFM(fakeScanner());
    $e = expectApi(fn () => $fm->upload('local', '', uploadFile('bad.txt', 'ssn: ' . FAKE_SSN)), 422, 'pii_detected');
    assertEqual('bad.txt', $e->getErrorParams()['name'] ?? null, 'name in params');
    assertEqual(['US_SSN'], $e->getErrorParams()['entities'] ?? null, 'entities in params');
    assertTrue(!$dm->disk('local')->fileExists('bad.txt'), 'PII-bearing file must NOT be stored');
});

// The property unique to DLP vs. Virus (§6.2): the raw matched text/fixture value
// must NEVER surface anywhere in the thrown exception — not the message, not any
// error param, at any nesting depth.
test('no raw PII text ever reaches the exception (message or params) — only entity TYPE names', function () {
    [$fm, $dm] = makeFM(fakeScanner());
    try {
        $fm->upload('local', '', uploadFile('leaky.txt', 'customer ssn is ' . FAKE_SSN . ' end'));
        throw new \RuntimeException('expected pii_detected, no throw');
    } catch (ApiException $e) {
        assertEqual('pii_detected', $e->getErrorCode());
        assertTrue(!str_contains($e->getMessage(), FAKE_SSN), 'raw SSN must not be in the exception message');
        $paramsJson = json_encode($e->getErrorParams());
        assertTrue(!str_contains((string) $paramsJson, FAKE_SSN), 'raw SSN must not be in error_params');
        assertTrue(!str_contains((string) $paramsJson, '123-45'), 'no partial fragment of the matched text either');
    }
});

test('scanner throwing (no engine / expired licence / engine down) FAILS CLOSED — no file stored', function () {
    $thrower = function (string $p, string $n): array {
        throw new ApiException('DLP engine request failed', 502, 'dlp_engine_unavailable');
    };
    [$fm, $dm] = makeFM($thrower);
    expectApi(fn () => $fm->upload('local', '', uploadFile('ok.txt', 'harmless')), 502, 'dlp_engine_unavailable');
    assertTrue(!$dm->disk('local')->fileExists('ok.txt'), 'unscanned file must NOT be stored');
});

test('a malformed verdict is treated as NOT clean (never fail open)', function () {
    [$fm, $dm] = makeFM(fn (string $p, string $n): array => ['entities' => []]); // no 'clean' key
    $e = expectApi(fn () => $fm->upload('local', '', uploadFile('ok.txt', 'harmless')), 422, 'pii_detected');
    assertEqual([], $e->getErrorParams()['entities'] ?? null, 'no entities to report from a malformed verdict');
    assertTrue(!$dm->disk('local')->fileExists('ok.txt'), 'file must NOT be stored');
});

test('a "clean" value that is truthy-but-not-boolean-true is treated as NOT clean', function () {
    [$fm, $dm] = makeFM(fn (string $p, string $n): array => ['clean' => 1, 'entities' => []]); // "1" !== true
    expectApi(fn () => $fm->upload('local', '', uploadFile('ok.txt', 'harmless')), 422, 'pii_detected');
    assertTrue(!$dm->disk('local')->fileExists('ok.txt'));
});

// ── Eligibility pre-filter (§2.1/§9.3) — the one structural addition vs. Virus ──
test('extension NOT in dlp_scan_extensions is SKIPPED — scanner never called, upload succeeds', function () {
    $seen = [];
    [$fm, $dm] = makeFM(fakeScanner($seen), false, ['dlp_scan_extensions' => ['txt']]);
    // Even though this .jpg contains the "PII" needle, it's an ineligible extension —
    // must be skipped, not blocked, and the (possibly-down) engine must never be hit.
    $fm->upload('local', '', uploadFile('photo.jpg', 'binary garbage ' . FAKE_SSN));
    assertTrue($dm->disk('local')->fileExists('photo.jpg'), 'ineligible extension must still be stored');
    assertEqual(0, count($seen), 'scanner must NEVER be invoked for an ineligible extension');
});

test('an engine that would 502 never gets a chance to fail an ineligible upload', function () {
    $thrower = function (string $p, string $n): array { throw new ApiException('down', 502, 'dlp_engine_unavailable'); };
    [$fm, $dm] = makeFM($thrower, false, ['dlp_scan_extensions' => ['txt']]);
    // .jpg is not on the eligibility list — the pre-filter must short-circuit BEFORE
    // ever calling the (down) scanner, so the write must succeed regardless.
    $fm->upload('local', '', uploadFile('photo.jpg', 'binary garbage'));
    assertTrue($dm->disk('local')->fileExists('photo.jpg'), 'engine-down must not block an ineligible file');
});

test('a file over dlp_max_scan_kb is SKIPPED — scanner never called, upload succeeds', function () {
    $seen = [];
    [$fm, $dm] = makeFM(fakeScanner($seen), false, ['dlp_scan_extensions' => ['txt'], 'dlp_max_scan_kb' => 16]);
    $big = str_repeat('x', 20 * 1024); // 20KB > 16KB cap
    $fm->upload('local', '', uploadFile('big.txt', $big));
    assertTrue($dm->disk('local')->fileExists('big.txt'), 'oversized file must still be stored (skip, not block)');
    assertEqual(0, count($seen), 'scanner must NEVER be invoked for an oversized file');
});

test('a file within dlp_max_scan_kb IS scanned', function () {
    $seen = [];
    [$fm, $dm] = makeFM(fakeScanner($seen), false, ['dlp_scan_extensions' => ['txt'], 'dlp_max_scan_kb' => 16]);
    $fm->upload('local', '', uploadFile('small.txt', str_repeat('x', 1024))); // 1KB < 16KB cap
    assertEqual(1, count($seen), 'in-cap file must be scanned');
});

test('scan runs BEFORE the metadata/search index is touched', function () {
    [$fm, $dm] = makeFM(fakeScanner());
    $meta = new StorageMetadataHandler($dm);
    expectApi(fn () => $fm->upload('local', '', uploadFile('bad.txt', FAKE_SSN)), 422, 'pii_detected');
    assertEqual(0, count($meta->search('local', 'bad')), 'rejected upload must not be indexed');
});

// ── putContent() (code editor) ────────────────────────────────────────────────
test('code-editor save is scanned; PII-bearing content is refused and the file is unchanged', function () {
    [$fm, $dm] = makeFM(fakeScanner(), true, ['dlp_scan_extensions' => ['txt']]);
    $fm->upload('local', '', uploadFile('app.txt', 'original'));
    expectApi(fn () => $fm->putContent('local', 'app.txt', 'leaked ' . FAKE_SSN), 422, 'pii_detected');
    assertEqual('original', (string) $dm->disk('local')->read('app.txt'), 'content must be untouched');
});

test('code-editor save of clean content still works', function () {
    [$fm, $dm] = makeFM(fakeScanner(), true, ['dlp_scan_extensions' => ['txt']]);
    $fm->upload('local', '', uploadFile('app.txt', 'original'));
    $fm->putContent('local', 'app.txt', 'updated');
    assertEqual('updated', (string) $dm->disk('local')->read('app.txt'));
});

// ── extractZip() ───────────────────────────────────────────────────────────────
test('zip extract scans every entry; a PII-bearing entry aborts and is never written', function () {
    if (!class_exists('ZipArchive')) { return; } // ext-zip absent → nothing to assert
    [$fm, $dm, $root] = makeFM(null, false, ['dlp_scan_extensions' => ['txt']]);

    $zipPath = sys_get_temp_dir() . '/ffz-' . uniqid() . '.zip';
    $za = new \ZipArchive();
    $za->open($zipPath, \ZipArchive::CREATE);
    $za->addFromString('a-clean.txt', 'harmless');
    $za->addFromString('b-bad.txt', FAKE_SSN);
    $za->close();
    $fm->upload('local', '', ['name' => 'payload.zip', 'tmp_name' => $zipPath,
        'size' => filesize($zipPath), 'type' => 'application/zip']);
    $fm->setDlpScanner(fakeScanner());

    $e = expectApi(fn () => $fm->extractZip('local', 'payload.zip', 'out'), 422, 'pii_detected');
    assertEqual('b-bad.txt', $e->getErrorParams()['entry'] ?? null, 'the offending entry is named');
    assertEqual(['US_SSN'], $e->getErrorParams()['entities'] ?? null, 'entities carried through the entry wrapper too');
    $fs = $dm->disk('local');
    assertTrue(!$fs->fileExists('out/b-bad.txt'), 'PII-bearing entry must NOT be written');
    assertTrue($fs->fileExists('out/a-clean.txt'), 'clean entries extracted before the abort remain');
});

test('zip extract with no PII writes every entry', function () {
    if (!class_exists('ZipArchive')) { return; }
    [$fm, $dm, $root] = makeFM(fakeScanner(), false, ['dlp_scan_extensions' => ['txt']]);
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

// ── the scanner must not consume the caller's file ────────────────────────────
test('scanning does not delete or truncate the upload temp', function () {
    [$fm, $dm] = makeFM(function (string $p, string $n): array {
        assertTrue(is_file($p), 'scanner receives a readable path');
        return ['clean' => true, 'entities' => []];
    });
    $fm->upload('local', '', uploadFile('keep.txt', 'payload-bytes'));
    assertEqual('payload-bytes', (string) $dm->disk('local')->read('keep.txt'), 'bytes survive the scan');
});

// ── writeScopedFile()/writeScopedStream() (the module write seam) ────────────
test('writeScopedFile: clean content passes the scanner and is stored', function () {
    [$fm, $dm] = makeFM(fakeScanner(), false, ['dlp_scan_extensions' => ['txt']]);
    $scoped = $fm->validateUserPath('out.txt');
    $fm->writeScopedFile('local', $scoped, 'clean-bytes');
    assertEqual('clean-bytes', (string) $dm->disk('local')->read('out.txt'));
});

test('writeScopedFile: PII-bearing content is refused and nothing is written', function () {
    [$fm, $dm] = makeFM(fakeScanner(), false, ['dlp_scan_extensions' => ['txt']]);
    $scoped = $fm->validateUserPath('out.txt');
    $e = expectApi(fn () => $fm->writeScopedFile('local', $scoped, 'payload ' . FAKE_SSN), 422, 'pii_detected');
    assertEqual(['US_SSN'], $e->getErrorParams()['entities'] ?? null);
    assertTrue(!$dm->disk('local')->fileExists('out.txt'), 'PII-bearing content must NOT be stored');
});

test('writeScopedStream: PII-bearing content is refused and nothing is written', function () {
    [$fm, $dm] = makeFM(fakeScanner(), false, ['dlp_scan_extensions' => ['txt']]);
    $scoped = $fm->validateUserPath('stream-out.txt');
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, 'leak ' . FAKE_SSN);
    rewind($stream);
    expectApi(fn () => $fm->writeScopedStream('local', $scoped, $stream), 422, 'pii_detected');
    assertTrue(!$dm->disk('local')->fileExists('stream-out.txt'), 'PII-bearing stream must NOT be written');
});

test('writeScopedStream: clean content passes the scanner and is stored', function () {
    [$fm, $dm] = makeFM(fakeScanner(), false, ['dlp_scan_extensions' => ['txt']]);
    $scoped = $fm->validateUserPath('stream-ok.txt');
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, 'clean bytes');
    rewind($stream);
    $fm->writeScopedStream('local', $scoped, $stream);
    assertEqual('clean bytes', (string) $dm->disk('local')->read('stream-ok.txt'));
});

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
