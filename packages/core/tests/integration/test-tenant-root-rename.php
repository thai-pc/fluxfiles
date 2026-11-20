<?php

/**
 * A scoped token must not be able to rename its own prefix root.
 *
 * Regression (audit 2026-11-20, R2-1): rename(path "") built the new key from
 * dirname(prefix), outside the prefix, so `users/42` renamed to "99" moved its
 * whole tree to `users/99` (another tenant's folder); a flat prefix `tenant1`
 * renamed to "tenant2" returned 200.
 *
 * Usage:
 *   php tests/integration/test-tenant-root-rename.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

function fm(string $root, string $prefix): FileManager
{
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $claims = new Claims('u42', ['read', 'write', 'delete'], ['local'], $prefix, 50, null, 0, false);
    return new FileManager($dm, $claims, new StorageMetadataHandler($dm));
}
function seed(FileManager $fm): void
{
    $tmp = sys_get_temp_dir() . '/fxtr-' . uniqid() . '.txt';
    file_put_contents($tmp, 'mine');
    $fm->upload('local', '', ['name' => 'a.txt', 'size' => 4, 'tmp_name' => $tmp], true);
}
function expectRootForbidden(callable $fn): void
{
    try { $fn(); } catch (ApiException $e) {
        assertTrue($e->getCode() === 403, 'expected 403, got ' . $e->getCode());
        return;
    }
    throw new \RuntimeException('rename of the tenant root was allowed');
}

echo "\nTenant root rename\n";

foreach (['users/42' => '99', 'tenant1' => 'tenant2'] as $prefix => $target) {
    foreach (['', '.', '/'] as $path) {
        test("prefix '$prefix', path " . json_encode($path) . " → 403, nothing moved", function () use ($prefix, $target, $path) {
            $root = sys_get_temp_dir() . '/fluxfiles-tr-' . uniqid();
            @mkdir($root, 0777, true);
            $fm = fm($root, $prefix);
            seed($fm);
            expectRootForbidden(fn () => $fm->rename('local', $path, $target));
            assertTrue(is_file("$root/$prefix/a.txt"), 'the tenant tree must stay where it was');
            $dest = dirname($prefix) === '.' ? "$root/$target" : "$root/" . dirname($prefix) . "/$target";
            assertTrue(!file_exists($dest), 'no sibling folder may appear');
        });
    }
}

test('renaming a folder inside the prefix still works', function () {
    $root = sys_get_temp_dir() . '/fluxfiles-tr-' . uniqid();
    @mkdir($root, 0777, true);
    $fm = fm($root, 'users/42');
    $fm->mkdir('local', 'docs');
    $fm->rename('local', 'docs', 'papers');
    assertTrue(is_dir("$root/users/42/papers"), 'subfolder rename must keep working');
});

test('unscoped token: disk root rename still refused', function () {
    $root = sys_get_temp_dir() . '/fluxfiles-tr-' . uniqid();
    @mkdir($root, 0777, true);
    $fm = fm($root, '');
    expectRootForbidden(fn () => $fm->rename('local', '', 'x'));
});

echo "\n  Total: " . ($passed + $failed) . "  Passed: {$passed}  Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
