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
function make(bool $virus, bool $dlp): array {
    $r1 = sys_get_temp_dir() . '/ff-scm-a-' . uniqid();
    $r2 = sys_get_temp_dir() . '/ff-scm-b-' . uniqid();
    @mkdir($r1, 0777, true); @mkdir($r2, 0777, true);
    $dm = new DiskManager([
        'a' => ['driver' => 'local', 'root' => $r1, 'url' => '/a'],
        'b' => ['driver' => 'local', 'root' => $r2, 'url' => '/b'],
    ]);
    $claims = Claims::fromJwtPayload((object) [
        'sub' => 'u', 'perms' => ['read', 'write', 'delete'], 'disks' => ['a', 'b'],
    ]);
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

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
