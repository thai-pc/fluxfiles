<?php

/**
 * Legal hold enforcement — free/core and license-independent
 * (docs/RETENTION-LEGAL-HOLD-DESIGN.md §2/§6/§9).
 *
 * Every hold in this file is placed via StorageMetadataHandler::addHold()
 * directly — NEVER through \FluxFiles\LegalHold\LegalHoldModule, which is a
 * private, gitignored package absent from this MIT checkout. That is itself
 * the point: FileManager::assertNoActiveHold() must block regardless of
 * whether the paid module class exists, is licensed, or the claim is set —
 * this whole suite is the proof that it genuinely does.
 *
 * The paid module's own management-layer behavior (place()/release()/list(),
 * reason validation, the active-hold cap, duplicate-hold 409) is tested in
 * the private packages/legal-hold/tests/test-legal-hold.php instead.
 *
 * Usage: php packages/core/tests/integration/test-legal-hold-enforcement.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function expectHold(callable $fn): void
{
    try {
        $fn();
        throw new \RuntimeException('expected ApiException legal_hold_active, none thrown');
    } catch (ApiException $e) {
        assertEqual('legal_hold_active', $e->getErrorCode(), 'error code');
        assertEqual(403, $e->getHttpCode(), 'http code');
    }
}

/** Fresh local disk + FileManager, with the `audit` perm so hold detail is visible. Returns [fm, fs, root, meta, dm]. */
function makeFM(string $prefix = '', bool $ownerOnly = false): array
{
    $root = sys_get_temp_dir() . '/fluxfiles-legal-hold-enforce-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager([
        'local'  => ['driver' => 'local', 'root' => $root, 'url' => '/storage'],
        'local2' => ['driver' => 'local', 'root' => $root . '-2', 'url' => '/storage2'],
    ]);
    $meta = new StorageMetadataHandler($dm);
    $claims = new Claims('tester', ['read', 'write', 'delete', 'audit'], ['local', 'local2'], $prefix, 50, null, 0, $ownerOnly);
    $fm = new FileManager($dm, $claims, $meta);
    return [$fm, $dm->disk('local'), $root, $meta, $dm];
}

function upload(FileManager $fm, string $path, string $name, string $content = 'hello', string $disk = 'local'): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'fxt');
    file_put_contents($tmp, $content);
    return $fm->upload($disk, $path, ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'type' => 'text/plain', 'error' => 0]);
}

/** Places a hold directly in the storage-resident holds.json — no LegalHoldModule involved. */
function placeHold(StorageMetadataHandler $meta, string $disk, string $path, bool $isDir = false): string
{
    $id = bin2hex(random_bytes(4));
    $meta->addHold($disk, $id, [
        'path' => trim($path, '/'), 'is_dir' => $isDir, 'disk' => $disk,
        'reason' => 'test hold', 'placed_by' => 'admin', 'placed_at' => time(),
        'released_at' => null, 'released_by' => null, 'release_reason' => null,
    ]);
    return $id;
}

echo "\n{$cyan}══ FluxFiles Legal Hold — free/core enforcement ══{$reset}\n\n";

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► single-file hold blocks delete/trash/rename/move, not copy{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('delete() throws legal_hold_active on a held file', function () {
    [$fm, , , $meta] = makeFM();
    upload($fm, '', 'held.txt');
    placeHold($meta, 'local', 'held.txt');
    expectHold(fn () => $fm->delete('local', 'held.txt'));
});

test('trash() throws legal_hold_active on a held file', function () {
    [$fm, , , $meta] = makeFM();
    upload($fm, '', 'held.txt');
    placeHold($meta, 'local', 'held.txt');
    expectHold(fn () => $fm->trash('local', 'held.txt'));
});

test('rename() throws legal_hold_active on a held file', function () {
    [$fm, , , $meta] = makeFM();
    upload($fm, '', 'held.txt');
    placeHold($meta, 'local', 'held.txt');
    expectHold(fn () => $fm->rename('local', 'held.txt', 'renamed.txt'));
});

test('move() throws legal_hold_active on a held file', function () {
    [$fm, , , $meta] = makeFM();
    upload($fm, '', 'held.txt');
    placeHold($meta, 'local', 'held.txt');
    expectHold(fn () => $fm->move('local', 'held.txt', 'moved.txt'));
});

test('copy() is NOT blocked on a held file (holds only guard destructive/mutating ops)', function () {
    [$fm, $fs, , $meta] = makeFM();
    upload($fm, '', 'held.txt');
    placeHold($meta, 'local', 'held.txt');
    $fm->copy('local', 'held.txt', 'held-copy.txt');
    assertTrue($fs->fileExists('held-copy.txt'), 'copy succeeded despite the hold');
});

test('crossCopy() is NOT blocked on a held file', function () {
    [$fm, , , $meta, $dm] = makeFM();
    upload($fm, '', 'held.txt');
    placeHold($meta, 'local', 'held.txt');
    $fm->crossCopy('local', 'held.txt', 'local2', 'held.txt');
    assertTrue($dm->disk('local2')->fileExists('held.txt'), 'cross-disk copy succeeded despite the hold');
});

test('crossMove() throws legal_hold_active on a held source file', function () {
    [$fm, , , $meta] = makeFM();
    upload($fm, '', 'held.txt');
    placeHold($meta, 'local', 'held.txt');
    expectHold(fn () => $fm->crossMove('local', 'held.txt', 'local2', 'held.txt'));
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► folder hold — descendant coverage, live prefix re-check{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('holding a folder blocks delete/rename/move of the folder itself', function () {
    [$fm, , , $meta] = makeFM();
    upload($fm, 'held-folder', 'a.txt');
    placeHold($meta, 'local', 'held-folder', true);
    expectHold(fn () => $fm->delete('local', 'held-folder'));
    expectHold(fn () => $fm->rename('local', 'held-folder', 'renamed-folder'));
    expectHold(fn () => $fm->move('local', 'held-folder', 'moved-folder'));
});

test('holding a folder blocks delete of a file living inside it (descendant coverage)', function () {
    [$fm, , , $meta] = makeFM();
    upload($fm, 'held-folder2', 'inside.txt');
    placeHold($meta, 'local', 'held-folder2', true);
    expectHold(fn () => $fm->delete('local', 'held-folder2/inside.txt'));
});

test('a file UPLOADED AFTER the folder hold is placed is also blocked (live prefix re-check, not a snapshot)', function () {
    [$fm, , , $meta] = makeFM();
    upload($fm, 'held-folder3', 'first.txt');
    placeHold($meta, 'local', 'held-folder3', true);
    // Upload happens strictly after the hold exists.
    upload($fm, 'held-folder3', 'second.txt');
    expectHold(fn () => $fm->delete('local', 'held-folder3/second.txt'));
});

test('holding a single FILE blocks delete/rename/move of its PARENT folder (ancestor-touches-held-descendant)', function () {
    [$fm, , , $meta] = makeFM();
    upload($fm, 'parent-folder', 'child.txt');
    placeHold($meta, 'local', 'parent-folder/child.txt');
    expectHold(fn () => $fm->delete('local', 'parent-folder'));
    expectHold(fn () => $fm->rename('local', 'parent-folder', 'renamed-parent'));
    expectHold(fn () => $fm->move('local', 'parent-folder', 'moved-parent'));
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► trash purge — checked against the ORIGINAL key, not the trash payload path{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('purgeTrash() throws legal_hold_active when the hold is placed AFTER trashing (checked live, not at trash time — trash() itself already blocks the "hold placed before trashing" case above)', function () {
    [$fm, , , $meta] = makeFM();
    upload($fm, '', 'trash-then-hold.txt');
    $trashId = $fm->trash('local', 'trash-then-hold.txt')['trash_id'];
    // Hold placed on the ORIGINAL key, after the file already sits in trash.
    placeHold($meta, 'local', 'trash-then-hold.txt');
    expectHold(fn () => $fm->purgeTrash('local', $trashId));
});

test('emptyTrash() over a mixed scope purges the unheld entry and reports the held one separately', function () {
    [$fm, , , $meta] = makeFM();
    upload($fm, '', 'unheld.txt');
    upload($fm, '', 'to-be-held.txt');
    $fm->trash('local', 'unheld.txt');
    $fm->trash('local', 'to-be-held.txt');
    placeHold($meta, 'local', 'to-be-held.txt');

    $result = $fm->emptyTrash('local');
    assertEqual(1, $result['purged'], 'the unheld entry was purged');
    assertEqual(1, $result['held'], 'the held entry was skipped, not silently dropped');
    assertEqual(1, count($fm->listTrash('local')), 'the held entry is still in trash afterward');
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► release() lifts the block{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('releasing the hold (releaseHold) allows a previously-blocked delete to succeed', function () {
    [$fm, $fs, , $meta] = makeFM();
    upload($fm, '', 'to-release.txt');
    $id = placeHold($meta, 'local', 'to-release.txt');
    expectHold(fn () => $fm->delete('local', 'to-release.txt'));

    $meta->releaseHold('local', $id, ['released_at' => time(), 'released_by' => 'admin', 'release_reason' => 'resolved']);
    $fm->delete('local', 'to-release.txt');
    assertTrue(!$fs->fileExists('to-release.txt'), 'delete succeeded after release');
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► license-independence — enforcement never touches ModuleRegistry/LegalHoldModule{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('a hold left over in holds.json blocks even though \\FluxFiles\\LegalHold\\LegalHoldModule does not exist in this checkout', function () {
    // Every test above already does this implicitly (the private module class is
    // never loaded anywhere in this suite or in FileManager itself), but this
    // test makes the guarantee explicit and self-documenting: an uninstalled or
    // never-licensed module can never silently stop enforcing an already-placed
    // hold, because FileManager::assertNoActiveHold() has no
    // ModuleRegistry/LicenseManager/Claims::isAllowed() call in it at all — it
    // reads straight from MetadataRepositoryInterface::holdBlocking().
    assertTrue(
        !class_exists('\\FluxFiles\\LegalHold\\LegalHoldModule', false),
        'the paid module class must not be autoloaded for this proof to mean anything'
    );
    [$fm, , , $meta] = makeFM();
    upload($fm, '', 'orphaned-hold.txt');
    placeHold($meta, 'local', 'orphaned-hold.txt');
    expectHold(fn () => $fm->delete('local', 'orphaned-hold.txt'));
});

// ═══════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
