<?php

/**
 * Disk config `hidden_files`: files a host adapter wrote into the disk root to
 * protect it (the WordPress plugin's .htaccess / index.php) are hidden at that
 * exact root key from list(), the visible-file walk and the duplicate finder;
 * the same name in a subfolder is the user's file and stays visible.
 *
 * Usage: php tests/unit/test-guard-files.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\QuotaManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }

function guardEnv(?array $hidden): array {
    $base = sys_get_temp_dir() . '/fluxfiles-guard-' . uniqid();
    mkdir($base . '/sub', 0777, true);
    foreach (['.htaccess', 'index.php', 'a.txt', 'sub/.htaccess', 'sub/index.php'] as $f) {
        file_put_contents("{$base}/{$f}", 'same-bytes');
    }
    $cfg = ['driver' => 'local', 'root' => $base, 'url' => '/s'];
    if ($hidden !== null) { $cfg['hidden_files'] = $hidden; }
    $dm = new DiskManager(['local' => $cfg]);
    $c = new Claims('u', ['read'], ['local'], '', 50, null, 0, false);
    $c->showHidden = true;
    return [new FileManager($dm, $c, new StorageMetadataHandler($dm)), $dm];
}

echo "\n{$cyan}══ Hidden guard files ══{$reset}\n\n";

test('root guard files are hidden from list(); subfolder namesakes stay', function () {
    [$fm] = guardEnv(['.htaccess', 'index.php']);
    $root = array_column($fm->list('local', ''), 'name');
    assertTrue(!in_array('.htaccess', $root, true) && !in_array('index.php', $root, true), json_encode($root));
    assertTrue(in_array('a.txt', $root, true), 'real files stay');
    $sub = array_column($fm->list('local', 'sub'), 'name');
    assertTrue(in_array('.htaccess', $sub, true) && in_array('index.php', $sub, true), json_encode($sub));
});

test('no hidden_files => nothing changes', function () {
    [$fm] = guardEnv(null);
    $root = array_column($fm->list('local', ''), 'name');
    assertTrue(in_array('.htaccess', $root, true) && in_array('index.php', $root, true), json_encode($root));
});

test('visible-file walk, duplicate finder and quota count skip them', function () {
    [$fm, $dm] = guardEnv(['.htaccess', 'index.php']);
    $keys = [];
    foreach ($fm->visibleFiles('local') as $f) { $keys[] = is_array($f) ? $f['key'] : (string) $f; }
    assertTrue(!in_array('.htaccess', $keys, true) && !in_array('index.php', $keys, true), json_encode($keys));
    assertTrue(in_array('sub/.htaccess', $keys, true), 'namesake kept: ' . json_encode($keys));
    $dups = $fm->findDuplicates('local');
    $files = array_merge(...array_map(fn ($g) => array_column($g['files'], 'key'), $dups['groups']));
    assertTrue(!in_array('.htaccess', $files, true) && !in_array('index.php', $files, true), json_encode($files));
    assertTrue((new QuotaManager($dm))->walk('local', '')['files'] === 3, 'a.txt + 2 subfolder files');
});

test('isGuardKey is exact and case-sensitive', function () {
    $cfg = ['hidden_files' => ['.htaccess']];
    assertTrue(FileManager::isGuardKey($cfg, '.htaccess'), 'exact');
    assertTrue(!FileManager::isGuardKey($cfg, 'sub/.htaccess') && !FileManager::isGuardKey($cfg, '.HTACCESS'), 'only the root key');
    assertTrue(!FileManager::isGuardKey([], '.htaccess'), 'unset => false');
});

echo "\n  " . ($failed === 0 ? $green : $red) . "{$passed} passed, {$failed} failed{$reset}\n\n";
exit($failed === 0 ? 0 : 1);
