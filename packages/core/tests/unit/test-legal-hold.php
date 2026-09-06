<?php

/**
 * Legal hold — free/core storage primitives (StorageMetadataHandler) + the
 * `allow_legal_hold` claim decode. The paid module's own management-layer
 * behavior (place()/release()/list(), reason validation, cap enforcement) is
 * tested in the private packages/legal-hold/tests/test-legal-hold.php; the
 * enforcement wiring inside FileManager is tested in
 * tests/integration/test-legal-hold-enforcement.php. This file only covers
 * the free/core pieces that ship regardless of the module being installed.
 *
 * Usage: php packages/core/tests/unit/test-legal-hold.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
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

/** Fresh local disk + StorageMetadataHandler. */
function makeMeta(): array
{
    $root = sys_get_temp_dir() . '/fluxfiles-legal-hold-unit-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    return [new StorageMetadataHandler($dm), $dm];
}

echo "\n{$cyan}══ FluxFiles Legal Hold — core storage primitives ══{$reset}\n\n";

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► addHold()/getHold()/releaseHold()/allHolds() round trip{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('addHold() then getHold() returns the stored entry', function () {
    [$meta] = makeMeta();
    $meta->addHold('local', 'h1', [
        'path' => 'contracts/a.pdf', 'is_dir' => false, 'disk' => 'local',
        'reason' => 'litigation', 'placed_by' => 'admin-1', 'placed_at' => 1000,
        'released_at' => null, 'released_by' => null, 'release_reason' => null,
    ]);
    $entry = $meta->getHold('local', 'h1');
    assertTrue($entry !== null, 'entry found');
    assertEqual('contracts/a.pdf', $entry['path']);
    assertEqual('admin-1', $entry['placed_by']);
    assertEqual(null, $entry['released_at']);
});

test('getHold() returns null for an unknown id', function () {
    [$meta] = makeMeta();
    assertEqual(null, $meta->getHold('local', 'nope'));
});

test('releaseHold() marks released but keeps the entry (tombstone, not delete)', function () {
    [$meta] = makeMeta();
    $meta->addHold('local', 'h2', ['path' => 'a.pdf', 'placed_at' => 1, 'released_at' => null]);
    $meta->releaseHold('local', 'h2', ['released_at' => 2, 'released_by' => 'admin-1', 'release_reason' => 'resolved']);
    $entry = $meta->getHold('local', 'h2');
    assertTrue($entry !== null, 'entry still exists after release');
    assertEqual(2, $entry['released_at']);
    assertEqual('admin-1', $entry['released_by']);
    assertEqual('resolved', $entry['release_reason']);
});

test('releaseHold() on an unknown id is a silent no-op', function () {
    [$meta] = makeMeta();
    $meta->releaseHold('local', 'nope', ['released_at' => 2]);
    assertEqual(null, $meta->getHold('local', 'nope'));
});

test('allHolds() returns every hold on the disk, keyed by id', function () {
    [$meta] = makeMeta();
    $meta->addHold('local', 'h3', ['path' => 'x.txt', 'placed_at' => 1]);
    $meta->addHold('local', 'h4', ['path' => 'y.txt', 'placed_at' => 1]);
    $all = $meta->allHolds('local');
    assertTrue(array_key_exists('h3', $all));
    assertTrue(array_key_exists('h4', $all));
});

test('allHolds() on a disk with no holds.json yet returns an empty array', function () {
    [$meta] = makeMeta();
    assertEqual([], $meta->allHolds('local'));
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► countActiveHolds() — released holds don't count{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('countActiveHolds() counts only entries with released_at === null', function () {
    [$meta] = makeMeta();
    $meta->addHold('local', 'h5', ['path' => 'a.txt', 'placed_at' => 1, 'released_at' => null]);
    $meta->addHold('local', 'h6', ['path' => 'b.txt', 'placed_at' => 1, 'released_at' => null]);
    assertEqual(2, $meta->countActiveHolds('local'));

    $meta->releaseHold('local', 'h6', ['released_at' => 2]);
    assertEqual(1, $meta->countActiveHolds('local'));
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► holdCovering()/holdBlocking() — prefix-overlap matrix{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('exact-path match: both holdCovering() and holdBlocking() find it', function () {
    [$meta] = makeMeta();
    $meta->addHold('local', 'h7', ['path' => 'docs/report.pdf', 'placed_at' => 1, 'released_at' => null]);
    assertTrue($meta->holdCovering('local', 'docs/report.pdf') !== null, 'covering: exact match');
    assertTrue($meta->holdBlocking('local', 'docs/report.pdf') !== null, 'blocking: exact match');
});

test('ancestor hold covers a descendant path: both holdCovering() and holdBlocking() find it', function () {
    [$meta] = makeMeta();
    $meta->addHold('local', 'h8', ['path' => 'docs', 'is_dir' => true, 'placed_at' => 1, 'released_at' => null]);
    assertTrue($meta->holdCovering('local', 'docs/sub/report.pdf') !== null, 'covering: descendant of held ancestor');
    assertTrue($meta->holdBlocking('local', 'docs/sub/report.pdf') !== null, 'blocking: descendant of held ancestor');
});

test('descendant-only hold: holdBlocking() finds it on the ancestor, holdCovering() does NOT', function () {
    [$meta] = makeMeta();
    $meta->addHold('local', 'h9', ['path' => 'docs/sub/report.pdf', 'placed_at' => 1, 'released_at' => null]);
    assertEqual(null, $meta->holdCovering('local', 'docs'), 'covering must not see a descendant hold as covering an ancestor');
    assertTrue($meta->holdBlocking('local', 'docs') !== null, 'blocking must see a descendant hold as blocking an ancestor operation');
    assertTrue($meta->holdBlocking('local', 'docs/sub') !== null, 'blocking also matches a closer ancestor');
});

test('sibling path: neither holdCovering() nor holdBlocking() match', function () {
    [$meta] = makeMeta();
    $meta->addHold('local', 'h10', ['path' => 'docs/report.pdf', 'placed_at' => 1, 'released_at' => null]);
    assertEqual(null, $meta->holdCovering('local', 'docs/other.pdf'));
    assertEqual(null, $meta->holdBlocking('local', 'docs/other.pdf'));
    // A path that merely shares a string prefix (not a "/"-bounded segment)
    // must not falsely match either direction.
    assertEqual(null, $meta->holdCovering('local', 'docs-archive/other.pdf'));
    assertEqual(null, $meta->holdBlocking('local', 'docs-archive/other.pdf'));
});

test('a released hold never covers or blocks', function () {
    [$meta] = makeMeta();
    $meta->addHold('local', 'h11', ['path' => 'docs/report.pdf', 'placed_at' => 1, 'released_at' => null]);
    $meta->releaseHold('local', 'h11', ['released_at' => 2, 'released_by' => 'admin', 'release_reason' => 'done']);
    assertEqual(null, $meta->holdCovering('local', 'docs/report.pdf'));
    assertEqual(null, $meta->holdBlocking('local', 'docs/report.pdf'));
});

test('holdCovering()/holdBlocking() return the hold_id alongside the entry fields', function () {
    [$meta] = makeMeta();
    $meta->addHold('local', 'h12', ['path' => 'x.txt', 'reason' => 'r', 'placed_at' => 1, 'released_at' => null]);
    $hit = $meta->holdBlocking('local', 'x.txt');
    assertEqual('h12', $hit['hold_id']);
    assertEqual('r', $hit['reason']);
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► allow_legal_hold claim decode{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('allow_legal_hold defaults to false when absent', function () {
    $c = Claims::fromJwtPayload((object) ['sub' => 'u', 'perms' => ['read'], 'disks' => ['local']]);
    assertEqual(false, $c->allowLegalHold);
    assertEqual(false, $c->isAllowed('allow_legal_hold'));
});

test('allow_legal_hold decodes true and isAllowed() maps it', function () {
    $c = Claims::fromJwtPayload((object) ['sub' => 'u', 'perms' => ['read'], 'disks' => ['local'], 'allow_legal_hold' => true]);
    assertEqual(true, $c->allowLegalHold);
    assertEqual(true, $c->isAllowed('allow_legal_hold'));
});

test('allow_legal_hold decodes explicit false', function () {
    $c = Claims::fromJwtPayload((object) ['sub' => 'u', 'perms' => ['read'], 'disks' => ['local'], 'allow_legal_hold' => false]);
    assertEqual(false, $c->allowLegalHold);
});

// ═══════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
