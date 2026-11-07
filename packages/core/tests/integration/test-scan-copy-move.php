<?php

/**
 * Virus + DLP scanning must also cover bytes that are ALREADY stored when they
 * are copied (same disk) or crossed between disks (copy/move). Before this guard,
 * copy()/crossCopy()/crossMove() never called the scanners, so an unscanned disk
 * could feed a scanned one and a pre-claim EICAR could be duplicated at will.
 *
 * Same-disk move() is deliberately NOT scanned (no new object; pure rename).
 *
 * Usage: php tests/integration/test-scan-copy-move.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed;
    try { $f(); echo "  PASS {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  FAIL {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function expectApi(callable $f, int $code, string $errCode): void {
    try { $f(); } catch (ApiException $e) {
        if ($e->getHttpCode() !== $code || $e->getErrorCode() !== $errCode) {
            throw new \RuntimeException("expected {$code}/{$errCode}, got {$e->getHttpCode()}/{$e->getErrorCode()}");
        }
        return;
    }
    throw new \RuntimeException("expected {$code}/{$errCode}, no throw");
}

const NEEDLE = 'X5O!P%@AP-FAKE-TEST-SIGNATURE';
const SSN = '123-45-6789';

function virusScanner(): callable {
    return fn (string $p): array => str_contains((string) file_get_contents($p), NEEDLE)
        ? ['clean' => false, 'threat' => 'Test.Fake'] : ['clean' => true, 'threat' => null];
}
function dlpScanner(): callable {
    return fn (string $p, string $n): array => str_contains((string) file_get_contents($p), SSN)
        ? ['clean' => false, 'entities' => ['US_SSN']] : ['clean' => true, 'entities' => []];
}

/** Two local disks; the bad files are planted straight on the adapters (no scanner involved). */
function make(bool $virus, bool $dlp, array $claimExtra = []): array {
    $r1 = sys_get_temp_dir() . '/ff-scm-a-' . uniqid();
    $r2 = sys_get_temp_dir() . '/ff-scm-b-' . uniqid();
    @mkdir($r1, 0777, true); @mkdir($r2, 0777, true);
    $dm = new DiskManager([
        'a' => ['driver' => 'local', 'root' => $r1, 'url' => '/a'],
        'b' => ['driver' => 'local', 'root' => $r2, 'url' => '/b'],
    ]);
    $claims = Claims::fromJwtPayload((object) ($claimExtra + [
        'sub' => 'u', 'perms' => ['read', 'write', 'delete'], 'disks' => ['a', 'b'],
    ]));
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    if ($virus) { $fm->setVirusScanner(virusScanner()); }
    if ($dlp) { $fm->setDlpScanner(dlpScanner()); }
    $dm->disk('a')->write('evil.txt', 'x ' . NEEDLE);
    $dm->disk('a')->write('pii.txt', 'ssn ' . SSN);
    $dm->disk('a')->write('ok.txt', 'hello');
    return [$fm, $dm];
}

echo "\n== Scan on copy / cross-disk copy / cross-disk move ==\n\n";

test('same-disk copy of a stored infected file -> 422 virus_detected, no copy', function () {
    [$fm, $dm] = make(true, false);
    expectApi(fn () => $fm->copy('a', 'evil.txt', 'evil2.txt'), 422, 'virus_detected');
    assertTrue(!$dm->disk('a')->fileExists('evil2.txt'));
});
test('cross-disk copy of an infected file into a scanning token -> 422, nothing written', function () {
    [$fm, $dm] = make(true, false);
    expectApi(fn () => $fm->crossCopy('a', 'evil.txt', 'b', 'evil.txt'), 422, 'virus_detected');
    assertTrue(!$dm->disk('b')->fileExists('evil.txt'));
});
test('cross-disk move of an infected file -> 422, source kept, nothing written', function () {
    [$fm, $dm] = make(true, false);
    expectApi(fn () => $fm->crossMove('a', 'evil.txt', 'b', 'evil.txt'), 422, 'virus_detected');
    assertTrue(!$dm->disk('b')->fileExists('evil.txt'));
    assertTrue($dm->disk('a')->fileExists('evil.txt'), 'source must survive');
});
test('clean file still copies / crosses with scanning on', function () {
    [$fm, $dm] = make(true, true);
    $fm->copy('a', 'ok.txt', 'ok2.txt');
    $fm->crossCopy('a', 'ok.txt', 'b', 'ok.txt');
    $fm->crossMove('a', 'ok2.txt', 'b', 'ok2.txt');
    assertTrue($dm->disk('a')->fileExists('ok2.txt') === false && $dm->disk('b')->fileExists('ok2.txt'));
});
test('scanner failure fails closed on copy and cross ops', function () {
    [$fm, $dm] = make(false, false);
    $fm->setVirusScanner(function (string $p): array { throw new ApiException('down', 502, 'virus_engine_unavailable'); });
    expectApi(fn () => $fm->copy('a', 'ok.txt', 'c.txt'), 502, 'virus_engine_unavailable');
    expectApi(fn () => $fm->crossCopy('a', 'ok.txt', 'b', 'c.txt'), 502, 'virus_engine_unavailable');
    expectApi(fn () => $fm->crossMove('a', 'ok.txt', 'b', 'c.txt'), 502, 'virus_engine_unavailable');
    assertTrue(!$dm->disk('a')->fileExists('c.txt') && !$dm->disk('b')->fileExists('c.txt'));
});
test('no scanner wired -> copy untouched', function () {
    [$fm, $dm] = make(false, false);
    $fm->copy('a', 'evil.txt', 'e2.txt');
    assertTrue($dm->disk('a')->fileExists('e2.txt'));
});
test('same-disk move is NOT scanned (pure rename)', function () {
    [$fm, $dm] = make(true, true);
    $fm->move('a', 'evil.txt', 'moved.txt');
    assertTrue($dm->disk('a')->fileExists('moved.txt'));
});
test('DLP: copy / crossCopy / crossMove of PII file -> 422 pii_detected', function () {
    [$fm, $dm] = make(false, true);
    expectApi(fn () => $fm->copy('a', 'pii.txt', 'p2.txt'), 422, 'pii_detected');
    expectApi(fn () => $fm->crossCopy('a', 'pii.txt', 'b', 'pii.txt'), 422, 'pii_detected');
    expectApi(fn () => $fm->crossMove('a', 'pii.txt', 'b', 'pii.txt'), 422, 'pii_detected');
    assertTrue(!$dm->disk('b')->fileExists('pii.txt') && !$dm->disk('a')->fileExists('p2.txt'));
});

// ── TOCTOU: a writer swapping the source mid-scan must not reach the destination ──
foreach (['copy', 'crossCopy', 'crossMove'] as $op) {
    test("TOCTOU: source rewritten mid-scan -> {$op} writes the SCANNED bytes", function () use ($op) {
        [$fm, $dm] = make(false, false);
        $fm->setVirusScanner(function (string $p) use ($dm): array {
            $clean = !str_contains((string) file_get_contents($p), NEEDLE);
            $dm->disk('a')->write('ok.txt', 'x ' . NEEDLE); // attacker swaps the source now
            return ['clean' => $clean, 'threat' => null];
        });
        $dst = 'out-' . $op . '.txt';
        if ($op === 'copy') { $fm->copy('a', 'ok.txt', $dst); $d = 'a'; }
        else { $fm->$op('a', 'ok.txt', 'b', $dst); $d = 'b'; }
        assertTrue($dm->disk($d)->read($dst) === 'hello', 'destination must hold the scanned bytes, got: ' . $dm->disk($d)->read($dst));
    });
}

// ── DLP eligibility / refusal semantics ──
test('DLP-only: ineligible extension and oversize are SKIPPED (scanner never called) on copy and cross ops', function () {
    $calls = 0;
    [$fm, $dm] = make(false, false, ['dlp_max_scan_kb' => 1]);
    $fm->setDlpScanner(function (string $p, string $n) use (&$calls): array { $calls++; return ['clean' => false, 'entities' => ['X']]; });
    $dm->disk('a')->write('pic.png', 'ssn ' . SSN);
    $dm->disk('a')->write('big.txt', str_repeat('a', 20000));
    foreach (['pic.png', 'big.txt'] as $f) {
        $fm->copy('a', $f, 'c-' . $f);
        $fm->crossCopy('a', $f, 'b', $f);
        $fm->crossMove('a', 'c-' . $f, 'b', 'm-' . $f);
    }
    assertTrue($calls === 0, "scanner called {$calls} times");
    assertTrue($dm->disk('b')->fileExists('pic.png') && $dm->disk('b')->fileExists('m-big.txt'));
});
test('DLP crossMove refusal keeps the source', function () {
    [$fm, $dm] = make(false, true);
    expectApi(fn () => $fm->crossMove('a', 'pii.txt', 'b', 'pii.txt'), 422, 'pii_detected');
    assertTrue($dm->disk('a')->fileExists('pii.txt') && !$dm->disk('b')->fileExists('pii.txt'));
});
test('staging failure -> 500 virus_failed / dlp_failed, nothing written, source kept', function () {
    foreach ([[true, false, 'virus_failed'], [false, true, 'dlp_failed']] as [$v, $d, $code]) {
        [$fm, $dm] = make($v, $d);
        $dm->disk('a')->write('lock.txt', 'hello');
        $path = null;
        foreach (glob(sys_get_temp_dir() . '/ff-scm-a-*/lock.txt') as $c) { $path = $c; }
        if ($path === null || posix_geteuid() === 0) { continue; }
        chmod($path, 0000);
        try {
            expectApi(fn () => $fm->crossMove('a', 'lock.txt', 'b', 'lock.txt'), 500, $code);
            expectApi(fn () => $fm->copy('a', 'lock.txt', 'lock2.txt'), 500, $code);
        } finally { chmod($path, 0644); }
        assertTrue(!$dm->disk('b')->fileExists('lock.txt') && $dm->disk('a')->fileExists('lock.txt'));
    }
});
test('cross-disk variants are not transferred when a scanner is wired', function () {
    [$fm, $dm] = make(true, false);
    $dm->disk('a')->write('_variants/ok.txt_thumb.webp', 'planted');
    $fm->crossCopy('a', 'ok.txt', 'b', 'ok.txt');
    assertTrue(!$dm->disk('b')->fileExists('_variants/ok.txt_thumb.webp'));
    // control: without a scanner the variant still travels
    [$fm2, $dm2] = make(false, false);
    $dm2->disk('a')->write('_variants/ok.txt_thumb.webp', 'planted');
    $fm2->crossCopy('a', 'ok.txt', 'b', 'ok.txt');
    assertTrue($dm2->disk('b')->fileExists('_variants/ok.txt_thumb.webp'), 'control');
});

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
