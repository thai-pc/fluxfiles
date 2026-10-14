<?php

/**
 * FileManager::scanForViruses() — the on-demand "scan a stored file" behind
 * POST /api/fm/virus/scan — plus the `virus_scanned` flag on uploads. The
 * scanner is a fake callable here; the real ClamAV/VirusTotal engine and the
 * module gate are covered by the virus module's own tests and test-virus-http.php.
 *
 * Usage: php tests/unit/test-virus-scan-now.php
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
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function expectCode(string $code, callable $fn): void {
    try { $fn(); } catch (ApiException $e) {
        assertTrue($e->getErrorCode() === $code, "expected {$code}, got " . (string) $e->getErrorCode());
        return;
    }
    throw new \RuntimeException("expected {$code}, nothing thrown");
}
function vsEnv(array $perms = ['read', 'write']): array {
    $root = sys_get_temp_dir() . '/fluxfiles-vs-' . uniqid();
    @mkdir($root . '/t', 0777, true);
    file_put_contents($root . '/t/doc.txt', 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR');
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $c = new Claims('u', $perms, ['local'], 't', 50, null, 0, false);
    return [new FileManager($dm, $c, new StorageMetadataHandler($dm)), $root];
}

echo "\n{$cyan}══ On-demand virus scan ══{$reset}\n\n";

test('the scanner sees the stored bytes in a temp copy that is removed afterwards', function () {
    [$fm] = vsEnv();
    $seen = null; $seenPath = null;
    $r = $fm->scanForViruses('local', 'doc.txt', function (string $p) use (&$seen, &$seenPath) {
        $seenPath = $p; $seen = file_get_contents($p);
        return ['clean' => false, 'threat' => 'Eicar-Test-Signature'];
    });
    assertTrue(str_contains((string) $seen, 'EICAR'), 'bytes handed to the scanner');
    assertTrue(!file_exists((string) $seenPath), 'temp copy cleaned up');
    assertTrue($r['clean'] === false && $r['threat'] === 'Eicar-Test-Signature' && $r['path'] === 'doc.txt', json_encode($r));
});

test('a clean verdict reports clean with no threat; a malformed verdict is NOT clean', function () {
    [$fm] = vsEnv();
    $r = $fm->scanForViruses('local', 'doc.txt', fn () => ['clean' => true]);
    assertTrue($r['clean'] === true && $r['threat'] === null, 'clean');
    $r = $fm->scanForViruses('local', 'doc.txt', fn () => 'garbage');
    assertTrue($r['clean'] === false, 'malformed → not clean (fail-closed)');
});

test('read perm, scope, reserved paths and existence are enforced', function () {
    [$fm] = vsEnv(['write']);
    expectCode('permission_denied', fn () => $fm->scanForViruses('local', 'doc.txt', fn () => ['clean' => true]));
    [$fm] = vsEnv();
    expectCode('system_path', fn () => $fm->scanForViruses('local', '_fluxfiles/audit.jsonl', fn () => ['clean' => true]));
    expectCode('not_found', fn () => $fm->scanForViruses('local', 'missing.txt', fn () => ['clean' => true]));
    expectCode('disk_denied', fn () => $fm->scanForViruses('s3', 'doc.txt', fn () => ['clean' => true]));
});

test('an upload that passed the scanner is flagged virus_scanned', function () {
    [$fm] = vsEnv();
    $fm->setVirusScanner(fn () => ['clean' => true]);
    $tmp = tempnam(sys_get_temp_dir(), 'vsu');
    file_put_contents($tmp, 'hello');
    $r = $fm->upload('local', '', ['name' => 'ok.txt', 'size' => 5, 'tmp_name' => $tmp]);
    @unlink($tmp);
    assertTrue(($r['virus_scanned'] ?? false) === true, 'flag set');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
