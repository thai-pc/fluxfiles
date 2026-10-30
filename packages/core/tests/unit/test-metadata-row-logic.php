<?php

/**
 * Db\MetadataRowLogic: the storage-agnostic half shared by the three SQL metadata
 * handlers. Exercised through a stub host so no database is needed; the real
 * handler is covered by test-db-metadata-sqlite.php.
 *
 * Usage: php tests/unit/test-metadata-row-logic.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Db\DbMetadataHandler;
use FluxFiles\Db\MetadataRowLogic;
use FluxFiles\DiskManager;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m !== '' ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

$root = sys_get_temp_dir() . '/ff-mrl-' . bin2hex(random_bytes(4));
mkdir($root . '/d/sub', 0755, true);
file_put_contents($root . '/d/a.txt', '1');
file_put_contents($root . '/d/sub/b.txt', '2');
file_put_contents($root . '/d/sub/c.meta.json', '{}');
register_shutdown_function(static function () use ($root) { exec('rm -rf ' . escapeshellarg($root)); });

/** Stub host: only the SQL-backed methods the trait expects, backed by arrays. */
class StubHost
{
    use MetadataRowLogic {
        isReservedPath as public;
        isHiddenPath as public;
        pathHash as public;
        highlight as public;
        rowToTrashEntry as public;
        rowToHoldEntry as public;
        matchOverlappingHold as public;
        auditWindowSql as public;
        auditWindowEntries as public;
    }
    public DiskManager $diskManager;
    public array $holds = [];
    public array $tracked = [];
    public ?array $row = null;
    public function __construct(DiskManager $dm) { $this->diskManager = $dm; }
    private function fetchRow(string $disk, string $key): ?array { return $this->row; }
    private function allHolds(string $disk): array { return $this->holds; }
    public function getHold(string $disk, string $id): ?array { return $this->holds[$id] ?? null; }
    public function addHold(string $disk, string $id, array $e): void { $this->holds[$id] = $e; }
    private function trackDir(string $disk, string $dir): void { $this->tracked[] = $dir; }
}
function hold(string $path, ?int $released = null): array {
    return ['path' => $path, 'is_dir' => true, 'disk' => 'local', 'reason' => 'r', 'placed_by' => 'u', 'placed_at' => 1, 'released_at' => $released, 'released_by' => null, 'release_reason' => null];
}
$dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root . '/d']]);

echo "\n{$cyan}══ MetadataRowLogic ══{$reset}\n\n";

test('the real DbMetadataHandler uses the trait (no private copies left)', function () {
    $uses = class_uses(DbMetadataHandler::class);
    assertTrue(isset($uses[MetadataRowLogic::class]));
    // Trait methods report the using class as declaring class; the file tells them apart.
    $self = (new ReflectionClass(DbMetadataHandler::class))->getFileName();
    $own = array_map(fn ($m) => $m->getName(), array_filter(
        (new ReflectionClass(DbMetadataHandler::class))->getMethods(),
        fn ($m) => $m->getFileName() === $self
    ));
    foreach (['isReservedPath', 'isHiddenPath', 'highlight', 'rowToTrashEntry', 'rowToHoldEntry', 'holdCovering', 'holdBlocking', 'holdsCoveringMany', 'syncToS3Tags', 'readAuditArchive'] as $m) {
        assertTrue(!in_array($m, $own, true), "{$m} still duplicated in the handler");
    }
});

test('path predicates', function () use ($dm) {
    $h = new StubHost($dm);
    assertTrue($h->isReservedPath('a/_fluxfiles/x') && $h->isReservedPath('_variants') && !$h->isReservedPath('a/b'));
    assertTrue($h->isHiddenPath('a/.git/x') && !$h->isHiddenPath('a/b.c'));
    assertEqual(hash('sha256', 'k'), $h->pathHash('k'));
});

test('highlight escapes first, then marks', function () use ($dm) {
    $h = new StubHost($dm);
    assertEqual('<mark>a</mark>&lt;b&gt;', $h->highlight('a<b>', 'a'));
    assertEqual(null, $h->highlight('', 'a'));
    assertEqual(null, $h->highlight('x', ''));
});

test('rowToTrashEntry / rowToHoldEntry normalise types', function () use ($dm) {
    $h = new StubHost($dm);
    $t = $h->rowToTrashEntry(['original_key' => 'a', 'disk' => 'local', 'basename' => 'a', 'is_dir' => 0, 'size' => '5', 'deleted_at' => '9', 'owner' => 'u', 'variants' => '["v"]', 'meta' => null, 'files' => '{bad', 'dirs' => null]);
    assertEqual(5, $t['size']);
    assertEqual(['v'], $t['variants']);
    assertEqual([], $t['meta']);
    assertEqual([], $t['files']);
    $r = $h->rowToHoldEntry(['path' => 'p', 'is_dir' => '1', 'disk' => 'local', 'reason' => 'r', 'placed_by' => 'u', 'placed_at' => '3', 'released_at' => null, 'released_by' => null, 'release_reason' => null, 'placed_by_scope' => 'tenant']);
    assertEqual(true, $r['is_dir']);
    assertEqual(3, $r['placed_at']);
    assertEqual('tenant', $r['placed_by_scope']);
});

test('hold overlap: case-insensitive; covering is one-way, blocking is two-way; released never matches', function () use ($dm) {
    $h = new StubHost($dm);
    $h->holds = ['7' => hold('Contracts'), 'old' => hold('Done', 5)];
    assertEqual('7', $h->holdCovering('local', 'contracts/x.pdf')['hold_id']);
    assertEqual(null, $h->holdCovering('local', 'other/x.pdf'));
    assertEqual(null, $h->holdCovering('local', 'Done/x.pdf'), 'released');
    assertEqual(null, $h->holdCovering('local', ''), 'empty path');
    // A parent of the held path: blocked (you would destroy the hold target) but not "covered".
    assertEqual(null, $h->holdCovering('local', ''));
    $h->holds = ['7' => hold('contracts/2024/q1')];
    assertEqual(null, $h->holdCovering('local', 'contracts'));
    assertEqual('7', $h->holdBlocking('local', 'contracts')['hold_id']);
    assertTrue(is_string($h->holdBlocking('local', 'contracts')['hold_id']), 'all-digit ids stay strings');
    $many = $h->holdsCoveringMany('local', ['contracts/2024/q1/a', 'zzz']);
    assertEqual('7', $many['contracts/2024/q1/a']['hold_id']);
    assertEqual(null, $many['zzz']);
});

test('releaseHold merges release info into the existing hold; unknown id is a no-op', function () use ($dm) {
    $h = new StubHost($dm);
    $h->holds = ['h' => hold('p')];
    $h->releaseHold('local', 'h', ['released_at' => 10, 'released_by' => 'me']);
    assertEqual(10, $h->holds['h']['released_at']);
    assertEqual('p', $h->holds['h']['path']);
    $h->releaseHold('local', 'missing', ['released_at' => 1]);
    assertEqual(['h'], array_keys($h->holds));
});

test('get maps a row to the metadata shape; owner-less + title-less row is null', function () use ($dm) {
    $h = new StubHost($dm);
    assertEqual(null, $h->get('local', 'k'));
    $h->row = ['title' => null, 'owner' => null];
    assertEqual(null, $h->get('local', 'k'));
    $h->row = ['title' => 'T', 'alt_text' => null, 'caption' => 'C', 'tags' => 'x', 'owner' => 'u'];
    assertEqual(['title' => 'T', 'alt_text' => '', 'caption' => 'C', 'tags' => 'x', 'uploaded_by' => 'u'], $h->get('local', 'k'));
});

test('trackParents walks each ancestor, skipping reserved/dot segments', function () use ($dm) {
    $h = new StubHost($dm);
    $h->trackParents('local', 'a/b/c/file.txt');
    assertEqual(['a', 'a/b', 'a/b/c'], $h->tracked);
    $h->tracked = [];
    $h->trackParents('local', '_fluxfiles/x/y.json');
    $h->trackParents('local', 'file.txt');
    assertEqual([], $h->tracked);
});

test('countChildren skips sidecars; syncToS3Tags/readAuditArchive are inert', function () use ($dm) {
    $h = new StubHost($dm);
    assertEqual(2, $h->countChildren('local', ''));
    $h->syncToS3Tags('local', 'k', [], $dm);
    assertEqual([], $h->readAuditArchive('local'));
});

test('auditWindowSql: filters compose, limit takes the newest rows and is inlined', function () use ($dm) {
    $h = new StubHost($dm);
    assertEqual(["SELECT * FROM t WHERE disk = ? ORDER BY id ASC", ['d']], $h->auditWindowSql('t', 'd', null, null, null));
    assertEqual(["SELECT * FROM t WHERE disk = ? AND owner = ? AND created_at >= ? ORDER BY id DESC LIMIT 5", ['d', 'u', 100]], $h->auditWindowSql('t', 'd', 100, 5, 'u'));
    assertTrue(str_ends_with($h->auditWindowSql('t', 'd', null, -3, null)[0], 'LIMIT 0'));
});

test('auditWindowEntries maps rows and restores chronological order for a limit query', function () use ($dm) {
    $h = new StubHost($dm);
    $rows = [
        ['owner' => 'u', 'action' => 'b', 'file_key' => 'k2', 'ip' => null, 'user_agent' => null, 'detail' => null, 'created_at' => '2', 'prev_hash' => 'p', 'entry_hash' => 'e'],
        ['owner' => 'u', 'action' => 'a', 'file_key' => 'k1', 'ip' => null, 'user_agent' => null, 'detail' => null, 'created_at' => '1'],
    ];
    $asc = $h->auditWindowEntries($rows, 'local', null);
    assertEqual(['b', 'a'], array_column($asc, 'action'));
    $lim = $h->auditWindowEntries($rows, 'local', 2);
    assertEqual(['a', 'b'], array_column($lim, 'action'));
    assertEqual(2, $lim[1]['created_at']);
    assertEqual(null, $lim[0]['entry_hash']);
    assertEqual('local', $lim[0]['disk']);
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
