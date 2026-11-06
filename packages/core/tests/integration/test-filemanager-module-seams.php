<?php

/**
 * FileManager sweep seams for modules: filesystemFor / scopedKey / relativeKey /
 * isVisibleKey / isHeld / visibleFiles / removeKeys. They are thin wrappers over the
 * private helpers runRetention() and runFixityCheck() use, and must not relax a single
 * check: disk + perm gates, reserved and hidden filtering, owner_only and legal holds.
 *
 * Usage: php tests/integration/test-filemanager-module-seams.php
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
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function expectCode(callable $f, string $code): void {
    try { $f(); } catch (ApiException $e) { assertEqual($code, $e->getErrorCode(), 'error code'); return; }
    throw new \RuntimeException("expected {$code}, no throw");
}

/**
 * Tenant prefix `t`. @return array{0:FileManager,1:StorageMetadataHandler,2:string}
 */
function seamEnv(array $perms = ['read', 'write', 'delete'], bool $ownerOnly = false, bool $showHidden = false, string $user = 'u'): array {
    $base = sys_get_temp_dir() . '/fluxfiles-seam-' . uniqid();
    $files = ['t/a.txt', 't/sub/b.txt', 't/sub/.dot.txt', 't/.hidden/c.txt', 't/_fluxfiles/x.json', 't/pic.meta.json', 'other/z.txt'];
    foreach ($files as $rel) {
        @mkdir(dirname("{$base}/{$rel}"), 0777, true);
        file_put_contents("{$base}/{$rel}", $rel);
    }
    touch("{$base}/t/a.txt", 1_700_000_000);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $base, 'url' => '/s'], 'other' => ['driver' => 'local', 'root' => $base, 'url' => '/s']]);
    $meta = new StorageMetadataHandler($dm);
    $c = new Claims($user, $perms, ['local'], 't', 50, null, 0, $ownerOnly);
    $c->showHidden = $showHidden;
    return [new FileManager($dm, $c, $meta), $meta, $base];
}

echo "\n{$cyan}══ FileManager module seams ══{$reset}\n\n";

test('scopedKey / relativeKey round-trip and keep dot-segment stripping', function () {
    [$fm] = seamEnv();
    assertEqual('t/sub/b.txt', $fm->scopedKey('sub/b.txt'));
    assertEqual('t/sub/b.txt', $fm->scopedKey('../../sub/./b.txt'), 'traversal removed, still inside the prefix');
    assertEqual('t/sub/b.txt', $fm->scopedKey('t/sub/b.txt'), 'idempotent');
    assertEqual('sub/b.txt', $fm->relativeKey('t/sub/b.txt'));
    assertEqual('t', $fm->scopedKey(''));
});

test('isVisibleKey: reserved and hidden keys are not visible; showHidden lifts only the hidden rule', function () {
    [$fm] = seamEnv();
    assertTrue($fm->isVisibleKey('t/a.txt'));
    assertTrue(!$fm->isVisibleKey('t/_fluxfiles/x.json'), 'internal dir');
    assertTrue(!$fm->isVisibleKey('t/pic.meta.json'), 'reserved sidecar shape');
    assertTrue(!$fm->isVisibleKey('t/.hidden/c.txt'), 'dotted segment');
    [$fm2] = seamEnv(['read'], false, true);
    assertTrue($fm2->isVisibleKey('t/.hidden/c.txt'), 'show_hidden');
    assertTrue(!$fm2->isVisibleKey('t/_fluxfiles/x.json'), 'reserved stays reserved');
});

test('visibleFiles lists only visible files under the tenant root, with key/rel/mtime/size', function () {
    [$fm] = seamEnv(['read']);
    $rows = iterator_to_array($fm->visibleFiles('local', '', 'read'), false);
    $keys = array_column($rows, 'key');
    sort($keys);
    assertEqual(['t/a.txt', 't/sub/b.txt'], $keys);
    $a = array_values(array_filter($rows, fn ($r) => $r['key'] === 't/a.txt'))[0];
    assertEqual('a.txt', $a['rel']);
    assertEqual(1_700_000_000, $a['mtime']);
    assertEqual(strlen('t/a.txt'), $a['size']);
});

test('visibleFiles honours a relative root and never leaves the prefix', function () {
    [$fm] = seamEnv(['read']);
    assertEqual(['t/sub/b.txt'], array_column(iterator_to_array($fm->visibleFiles('local', 'sub'), false), 'key'));
    $esc = array_column(iterator_to_array($fm->visibleFiles('local', '../other'), false), 'key');
    assertTrue(!in_array('other/z.txt', $esc, true), 'a traversal root stays sandboxed');
    assertEqual([], iterator_to_array($fm->visibleFiles('local', '_fluxfiles'), false), 'reserved root is empty');
});

test('visibleFiles / filesystemFor gate disk and perm eagerly (at call time, not first iteration)', function () {
    [$fm] = seamEnv(['read']);
    expectCode(fn () => $fm->visibleFiles('other'), 'disk_denied');
    expectCode(fn () => $fm->visibleFiles('local', '', 'delete'), 'permission_denied');
    expectCode(fn () => $fm->filesystemFor('local', 'write'), 'permission_denied');
    assertTrue($fm->filesystemFor('local', 'read') instanceof \League\Flysystem\FilesystemOperator);
});

test('isHeld reflects an active legal hold, prefix-wise', function () {
    [$fm, $meta] = seamEnv();
    assertTrue(!$fm->isHeld('local', 't/sub/b.txt'));
    $meta->addHold('local', 'h1', ['path' => 't/sub', 'placed_at' => time(), 'released_at' => null]);
    assertTrue($fm->isHeld('local', 't/sub/b.txt'));
    assertTrue(!$fm->isHeld('local', 't/a.txt'));
});

test('removeKeys trash: moves to restorable trash and reports each key', function () {
    [$fm, , $base] = seamEnv();
    $seen = [];
    $fm->removeKeys('local', ['t/a.txt', 't/sub/b.txt'], 'trash', function (string $k, string $rel, ?ApiException $e) use (&$seen) {
        $seen[$k] = [$rel, $e];
    });
    assertEqual(['t/a.txt', 't/sub/b.txt'], array_keys($seen));
    assertEqual('a.txt', $seen['t/a.txt'][0]);
    assertTrue($seen['t/a.txt'][1] === null && $seen['t/sub/b.txt'][1] === null, 'no errors');
    assertTrue(!is_file("{$base}/t/a.txt") && !is_file("{$base}/t/sub/b.txt"), 'moved away');
    assertEqual(2, count($fm->listTrash('local')), 'restorable');
});

test('removeKeys delete: permanent', function () {
    [$fm, , $base] = seamEnv();
    $fm->removeKeys('local', ['t/a.txt'], 'delete', function () {});
    assertTrue(!is_file("{$base}/t/a.txt"));
    assertEqual(0, count($fm->listTrash('local')));
});

test('removeKeys: a legal hold is refused per key, never touched; other keys still go', function () {
    [$fm, $meta, $base] = seamEnv();
    $meta->addHold('local', 'h1', ['path' => 't/sub', 'placed_at' => time(), 'released_at' => null]);
    $codes = [];
    $fm->removeKeys('local', ['t/sub/b.txt', 't/a.txt'], 'delete', function (string $k, string $rel, ?ApiException $e) use (&$codes) {
        $codes[$k] = $e ? $e->getErrorCode() : null;
    });
    assertEqual('legal_hold_active', $codes['t/sub/b.txt']);
    assertEqual(null, $codes['t/a.txt']);
    assertTrue(is_file("{$base}/t/sub/b.txt") && !is_file("{$base}/t/a.txt"));
});

test('removeKeys: owner_only is enforced per key', function () {
    [$fm, $meta, $base] = seamEnv(['read', 'write', 'delete'], true, false, 'alice');
    $meta->save('local', 't/a.txt', ['uploaded_by' => 'alice']);
    $meta->save('local', 't/sub/b.txt', ['uploaded_by' => 'bob']);
    $codes = [];
    $fm->removeKeys('local', ['t/a.txt', 't/sub/b.txt'], 'trash', function (string $k, string $rel, ?ApiException $e) use (&$codes) {
        $codes[$k] = $e ? $e->getErrorCode() : null;
    });
    assertEqual(null, $codes['t/a.txt']);
    assertEqual('owner_only', $codes['t/sub/b.txt']);
    assertTrue(is_file("{$base}/t/sub/b.txt"), "bob's file untouched");
});

test('removeKeys: needs the delete perm and the disk; rejects a bad mode', function () {
    [$fm, , $base] = seamEnv(['read', 'write']);
    expectCode(fn () => $fm->removeKeys('local', ['t/a.txt'], 'delete', function () {}), 'permission_denied');
    assertTrue(is_file("{$base}/t/a.txt"));
    [$fm2] = seamEnv();
    expectCode(fn () => $fm2->removeKeys('other', ['t/a.txt'], 'delete', function () {}), 'disk_denied');
    try { $fm2->removeKeys('local', ['t/a.txt'], 'shred', function () {}); throw new \RuntimeException('no throw'); }
    catch (\InvalidArgumentException $e) { assertTrue(true); }
});

test('removeKeys: system and out-of-scope keys are refused, not acted on', function () {
    [$fm, , $base] = seamEnv();
    $codes = [];
    $fm->removeKeys('local', ['t/_fluxfiles/x.json', 'other/z.txt'], 'delete', function (string $k, string $rel, ?ApiException $e) use (&$codes) {
        $codes[$k] = $e ? $e->getErrorCode() : null;
    });
    assertTrue($codes['t/_fluxfiles/x.json'] !== null, 'system path refused');
    assertTrue($codes['other/z.txt'] !== null, 'foreign prefix refused');
    assertTrue(is_file("{$base}/t/_fluxfiles/x.json") && is_file("{$base}/other/z.txt"));
});

test('the built-in runRetention still behaves the same on top of the same helpers', function () {
    [$fm, $meta, $base] = seamEnv(['read', 'write', 'delete']);
    $c = new Claims('u', ['read', 'write', 'delete'], ['local'], 't', 50, null, 0, false);
    $c->retentionRules = Claims::sanitizeRetentionRules([['path' => '', 'days' => 30, 'action' => 'trash']]);
    $fm2 = new FileManager(new DiskManager(['local' => ['driver' => 'local', 'root' => $base, 'url' => '/s']]), $c, $meta);
    $r = $fm2->runRetention('local', true);
    assertEqual(['a.txt'], array_column($r['items'], 'path'), 'only the old, visible file would be trashed');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
