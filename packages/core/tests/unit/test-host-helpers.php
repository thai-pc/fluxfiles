<?php

/**
 * The function-only helpers every host shares (api/helpers.php): ff_str_param() and
 * ff_public_meta_repo(). The Share/Intake handlers that used to sit next to them ship in
 * their modules now (packages/share|intake/tests).
 *
 * Usage: php tests/unit/test-host-helpers.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../api/helpers.php';

use FluxFiles\DiskManager;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

echo "\n{$cyan}══ Host helpers (api/helpers.php) ══{$reset}\n\n";

test('ff_str_param: scalars stringify, arrays and missing keys collapse to the default', function () {
    assertTrue(ff_str_param(['token' => 'abc'], 'token') === 'abc');
    assertTrue(ff_str_param(['n' => 5], 'n') === '5');
    assertTrue(ff_str_param(['token' => ['a']], 'token', 'dflt') === 'dflt', '?token[]= must not warn');
    assertTrue(ff_str_param([], 'token') === '');
});

test('N2: the public routes use the configured metadata backend (db → DbMetadataHandler)', function () {
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => sys_get_temp_dir(), 'url' => '/s']]);
    $prev = $_ENV['FLUXFILES_STORAGE_BACKEND'] ?? null;
    try {
        unset($_ENV['FLUXFILES_STORAGE_BACKEND']);
        assertTrue(ff_public_meta_repo($dm) instanceof \FluxFiles\StorageMetadataHandler, 'json by default');
        $_ENV['FLUXFILES_STORAGE_BACKEND'] = 'db';
        $_ENV['FLUXFILES_DB_DSN'] = 'sqlite::memory:';
        $repo = ff_public_meta_repo($dm);
        assertTrue($repo instanceof \FluxFiles\Db\DbMetadataHandler, 'db mode → DbMetadataHandler, not the JSON sidecars');
        // A host that injected its own disks (WordPress/Laravel) keeps JSON unless
        // it also injects its repository — its env isn't the standalone app's.
        assertTrue(ff_public_meta_repo($dm, null, true) instanceof \FluxFiles\StorageMetadataHandler, 'host-injected disks: json default');
        $injected = new \FluxFiles\StorageMetadataHandler($dm);
        assertTrue(ff_public_meta_repo($dm, $injected, true) === $injected, 'an injected repository wins');
    } finally {
        if ($prev === null) { unset($_ENV['FLUXFILES_STORAGE_BACKEND']); } else { $_ENV['FLUXFILES_STORAGE_BACKEND'] = $prev; }
        unset($_ENV['FLUXFILES_DB_DSN']);
    }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
