<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }

/** @return array{0:FileManager,1:StorageMetadataHandler} */
function makeFM(): array
{
    $root = sys_get_temp_dir() . '/fluxfiles-search-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $meta = new StorageMetadataHandler($dm);
    $fm = new FileManager($dm, new Claims('u', ['read', 'write', 'delete'], ['local'], '', 50, null, 0), $meta);
    return [$fm, $meta];
}

function up(FileManager $fm, string $path, string $name, string $content = 'x'): void
{
    $tmp = tempnam(sys_get_temp_dir(), 'fxs');
    file_put_contents($tmp, $content);
    $fm->upload('local', $path, ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'type' => 'text/plain', 'error' => 0]);
}

echo "\n{$cyan}══ FluxFiles Search ══{$reset}\n\n";

test('matches files by name (substring)', function () {
    [$fm, $meta] = makeFM();
    up($fm, '', 'quarterly-report.txt');
    up($fm, '', 'invoice.pdf');
    $rows = $meta->search('local', 'report');
    assertEqual(1, count($rows), 'one match');
    assertEqual('quarterly-report.txt', $rows[0]['file_key'], 'right file');
});

test('matches files by metadata (title/tags) + returns highlight', function () {
    [$fm, $meta] = makeFM();
    up($fm, '', 'a.txt');
    $meta->save('local', 'a.txt', ['title' => 'Sunset over Hanoi', 'tags' => 'travel, vietnam']);
    assertTrue(count($meta->search('local', 'hanoi')) === 1, 'by title');
    assertTrue(count($meta->search('local', 'vietnam')) === 1, 'by tag');
    $row = $meta->search('local', 'hanoi')[0];
    assertTrue(isset($row['title_hl']) && str_contains((string) $row['title_hl'], '<'), 'has highlight markup');
});

test('search rows carry created / size / modified (for client-side sort)', function () {
    [$fm, $meta] = makeFM();
    up($fm, '', 'doc.txt', str_repeat('z', 321));
    $row = $meta->search('local', 'doc')[0];
    assertEqual(321, (int) ($row['size'] ?? 0), 'size');
    assertTrue(is_int($row['created'] ?? null) && $row['created'] > 0, 'created');
    assertTrue(is_int($row['modified'] ?? null) && $row['modified'] > 0, 'modified');
});

test('searchFolders matches folders and returns created', function () {
    [$fm, $meta] = makeFM();
    $fm->mkdir('local', 'Travel-photos');
    $fm->mkdir('local', 'Invoices');
    $rows = $meta->searchFolders('local', 'travel');
    assertEqual(1, count($rows), 'one folder match');
    assertEqual('Travel-photos', $rows[0]['dir_key'], 'right folder');
    assertTrue(is_int($rows[0]['created'] ?? null) && $rows[0]['created'] > 0, 'folder created in result');
});

test('search excludes internal _fluxfiles / _variants paths', function () {
    [$fm, $meta] = makeFM();
    up($fm, '', 'pic.txt');
    // an index/variants hit must never leak into results
    foreach ($meta->search('local', 'fluxfiles') as $r) {
        assertTrue(!str_contains($r['file_key'], '_fluxfiles'), 'no _fluxfiles');
    }
    foreach ($meta->search('local', 'variants') as $r) {
        assertTrue(!str_contains($r['file_key'], '_variants'), 'no _variants');
    }
});

test('search hides dotfiles (.env/.gitignore) by default, shows them with includeHidden', function () {
    [$fm, $meta] = makeFM();
    $meta->indexFile('local', '.env', ['title' => 'secret env']);
    $meta->indexFile('local', '.gitignore', ['title' => 'git ignore']);
    $meta->indexFile('local', 'config/.htaccess', ['title' => 'htaccess']);
    $meta->indexFile('local', 'readme.txt', ['title' => 'env notes']); // a normal file mentioning "env"

    // Default: no dotfile leaks, even when the query matches one.
    foreach (['env', 'git', 'htaccess'] as $q) {
        foreach ($meta->search('local', $q) as $r) {
            assertTrue(strpos(basename($r['file_key']), '.') !== 0, "no dotfile for '$q': " . $r['file_key']);
            assertTrue(!str_contains($r['file_key'], '/.'), "no nested dotfile for '$q': " . $r['file_key']);
        }
    }
    // The normal readme is still found.
    assertEqual(1, count($meta->search('local', 'readme')), 'normal file found');

    // Opt-in surfaces them.
    $hidden = $meta->search('local', 'env', 50, '', true);
    $keys = array_column($hidden, 'file_key');
    assertTrue(in_array('.env', $keys, true), '.env surfaces with includeHidden');
});

test('searchFolders hides dotfolders (.git) by default', function () {
    [$fm, $meta] = makeFM();
    $meta->trackDir('local', '.git');
    $meta->trackDir('local', '.git/hooks');
    $meta->trackDir('local', 'photos');
    assertEqual(0, count($meta->searchFolders('local', 'git')), 'no .git by default');
    assertTrue(count($meta->searchFolders('local', 'git', 50, '', true)) >= 1, '.git with includeHidden');
    assertEqual(1, count($meta->searchFolders('local', 'photos')), 'normal folder found');
});

test('list() hides dotfiles by default; show_hidden surfaces them', function () {
    $root = sys_get_temp_dir() . '/fluxfiles-hidden-' . uniqid();
    @mkdir($root, 0777, true);
    file_put_contents("$root/visible.txt", 'hi');
    file_put_contents("$root/.env", 'SECRET=1');
    @mkdir("$root/.git", 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    $meta = new StorageMetadataHandler($dm);

    $hide = new FileManager($dm, new Claims('u', ['read', 'write', 'delete'], ['local'], '', 50, null, 0), $meta);
    $names = array_column($hide->list('local', ''), 'name');
    assertTrue(in_array('visible.txt', $names, true), 'normal file shown');
    assertTrue(!in_array('.env', $names, true), '.env hidden by default');
    assertTrue(!in_array('.git', $names, true), '.git hidden by default');

    $show = new Claims('u', ['read', 'write', 'delete'], ['local'], '', 50, null, 0);
    $show->showHidden = true;
    $fmShow = new FileManager($dm, $show, $meta);
    $namesShown = array_column($fmShow->list('local', ''), 'name');
    assertTrue(in_array('.env', $namesShown, true), '.env shown with show_hidden');
    assertTrue(in_array('.git', $namesShown, true), '.git shown with show_hidden');
});

test('respects the path prefix scope', function () {
    [$fm, $meta] = makeFM();
    $fm->mkdir('local', 'u1');
    $fm->mkdir('local', 'u2');
    up($fm, 'u1', 'note.txt');
    up($fm, 'u2', 'note.txt');
    $scoped = $meta->search('local', 'note', 50, 'u1');
    assertEqual(1, count($scoped), 'only the in-scope file');
    assertTrue(str_starts_with($scoped[0]['file_key'], 'u1/'), 'scoped to u1');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
