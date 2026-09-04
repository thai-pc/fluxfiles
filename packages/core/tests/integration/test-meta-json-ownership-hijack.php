<?php

/**
 * Security regression: `{name}.meta.json` is a normal, writable path in the user's
 * own namespace, but StorageMetadataHandler::getFromLocal() trusts whatever JSON a
 * legacy sidecar found there contains — including `uploaded_by` — and auto-migrates
 * it to the real protected sidecar location. Before this fix, a plain `write`-perm
 * token (no ownership of the target file) could forge one via upload/rename/move/copy
 * of a name ending in `.meta.json` and hijack ownership of an unrelated file whose
 * modern sidecar didn't exist yet. FileManager::assertNotSystem() now rejects that
 * filename shape on every write path (mirrors the pre-existing `_fluxfiles`/`_variants`
 * reservation), while StorageMetadataHandler still reads + migrates genuinely
 * pre-existing legacy sidecars for backward compat.
 *
 * Usage: php packages/core/tests/integration/test-meta-json-ownership-hijack.php  (from repo root)
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

/** A FileManager with plain write access (no ownership) over a fresh temp local disk. */
function setup(): array {
    $root = sys_get_temp_dir() . '/ff-meta-hijack-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $meta = new StorageMetadataHandler($dm);
    return [$dm, $meta, $root];
}

function upload(FileManager $fm, string $name, string $content, bool $force = false): array {
    $tmp = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($tmp, $content);
    try {
        return $fm->upload('local', '', [
            'name' => $name,
            'tmp_name' => $tmp,
            'size' => strlen($content),
            'error' => 0,
            'type' => 'text/plain',
        ], $force);
    } finally { @unlink($tmp); }
}

echo "\n{$cyan}══ .meta.json reserved-filename hijack (assertNotSystem) ══{$reset}\n\n";

test('upload: name=<target>.meta.json + force_upload → blocked (the original PoC)', function () {
    [$dm, $meta, $root] = setup();
    // Victim's file already exists, WITHOUT a modern sidecar (pre-dates the scheme).
    file_put_contents("$root/victim.txt", 'victim bytes');

    $attacker = new Claims('mallory', ['read', 'write', 'delete'], ['local'], '', 50, null, 0);
    $fm = new FileManager($dm, $attacker, $meta);
    try {
        upload($fm, 'victim.txt.meta.json', json_encode(['uploaded_by' => 'mallory', 'title' => 'pwned']), true);
        throw new \RuntimeException('expected 403 system_path');
    } catch (ApiException $e) {
        assertEqual('system_path', $e->getErrorCode());
        assertEqual(403, $e->getHttpCode());
    }
    assertTrue(!is_file("$root/victim.txt.meta.json"), 'no forged sidecar left behind');
    // The victim's ownership is untouched (no modern sidecar was ever created).
    assertEqual(null, $meta->get('local', 'victim.txt'), 'no metadata exists for the victim file');
});

test('upload: plain (non-forced) upload of a .meta.json name is also blocked', function () {
    [$dm, $meta] = setup();
    $fm = new FileManager($dm, new Claims('mallory', ['read', 'write'], ['local'], '', 50, null, 0), $meta);
    try {
        upload($fm, 'other.txt.meta.json', '{}');
        throw new \RuntimeException('expected 403 system_path');
    } catch (ApiException $e) {
        assertEqual('system_path', $e->getErrorCode());
    }
});

test('upload: case-insensitive suffix match (.META.JSON) is also blocked', function () {
    [$dm, $meta] = setup();
    $fm = new FileManager($dm, new Claims('mallory', ['read', 'write'], ['local'], '', 50, null, 0), $meta);
    try {
        upload($fm, 'victim.txt.META.JSON', '{}');
        throw new \RuntimeException('expected 403 system_path');
    } catch (ApiException $e) {
        assertEqual('system_path', $e->getErrorCode());
    }
});

test('rename: renaming an owned file INTO a .meta.json name is blocked', function () {
    [$dm, $meta] = setup();
    $fm = new FileManager($dm, new Claims('mallory', ['read', 'write'], ['local'], '', 50, null, 0), $meta);
    upload($fm, 'mine.json', '{"x":1}');
    try {
        // Same extension ("json") both before and after — would otherwise sail past
        // the ext_changed check, since the reserved-suffix check runs first.
        $fm->rename('local', 'mine.json', 'victim.txt.meta.json');
        throw new \RuntimeException('expected 403 system_path');
    } catch (ApiException $e) {
        assertEqual('system_path', $e->getErrorCode());
    }
});

test('move: moving an owned file to a .meta.json destination is blocked', function () {
    [$dm, $meta] = setup();
    $fm = new FileManager($dm, new Claims('mallory', ['read', 'write'], ['local'], '', 50, null, 0), $meta);
    upload($fm, 'mine.txt', 'hi');
    try {
        $fm->move('local', 'mine.txt', 'victim.txt.meta.json');
        throw new \RuntimeException('expected 403 system_path');
    } catch (ApiException $e) {
        assertEqual('system_path', $e->getErrorCode());
    }
});

test('copy: copying an owned file to a .meta.json destination is blocked', function () {
    [$dm, $meta] = setup();
    $fm = new FileManager($dm, new Claims('mallory', ['read', 'write'], ['local'], '', 50, null, 0), $meta);
    upload($fm, 'mine.txt', 'hi');
    try {
        $fm->copy('local', 'mine.txt', 'victim.txt.meta.json');
        throw new \RuntimeException('expected 403 system_path');
    } catch (ApiException $e) {
        assertEqual('system_path', $e->getErrorCode());
    }
});

// ── Backward compat: pre-existing legacy sidecars must still be readable/migrated ──

test('regression: a genuinely pre-existing legacy sidecar is still read + migrated', function () {
    [$dm, $meta, $root] = setup();
    // Simulate a file onboarded before the sidecar scheme existed: the "legacy"
    // sidecar is placed directly via the filesystem (not through any FileManager
    // write path), exactly as it would be for a real pre-existing installation.
    file_put_contents("$root/legacy.txt", 'legacy bytes');
    file_put_contents("$root/legacy.txt.meta.json", json_encode([
        'uploaded_by' => 'real-owner',
        'title' => 'Legacy Title',
    ]));

    $data = $meta->get('local', 'legacy.txt');
    assertTrue($data !== null, 'legacy sidecar is still read');
    assertEqual('real-owner', $data['uploaded_by'] ?? null, 'uploaded_by comes from the legacy sidecar');
    assertEqual('Legacy Title', $data['title'] ?? null, 'title comes from the legacy sidecar');

    // Migrated to the protected location and the legacy file removed.
    assertTrue(is_file("$root/_fluxfiles/meta/legacy.txt.json"), 'migrated to protected sidecar path');
    assertTrue(!is_file("$root/legacy.txt.meta.json"), 'legacy sidecar cleaned up after migration');

    // A second read now comes from the protected sidecar and still matches.
    $data2 = $meta->get('local', 'legacy.txt');
    assertEqual('real-owner', $data2['uploaded_by'] ?? null, 'second read still correct (from migrated sidecar)');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
