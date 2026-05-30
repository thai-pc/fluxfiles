<?php

/**
 * Sidecar namespace — local metadata sidecars live under the protected
 * `_fluxfiles/meta/` namespace, NOT in the user file namespace, so a user-uploaded
 * `*.meta.json` can't be hidden or overwrite a sidecar. Legacy `{file}.meta.json`
 * sidecars are migrated on read.
 *
 * Usage:
 *   php tests/integration/test-sidecar-namespace.php
 */

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
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: "Expected " . json_encode($e) . " got " . json_encode($a)); }

function up(FileManager $fm, string $name, string $content): array
{
    $t = tempnam(sys_get_temp_dir(), 'sc'); file_put_contents($t, $content);
    $r = $fm->upload('local', '', ['name' => $name, 'size' => filesize($t), 'tmp_name' => $t], true);
    @unlink($t);
    return $r;
}
function makeEnv(): array
{
    $root = sys_get_temp_dir() . '/fluxfiles-sc-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
    $meta = new StorageMetadataHandler($dm);
    $fm = new FileManager($dm, new Claims('u', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, false), $meta);
    return [$fm, $meta, $dm->disk('local'), $root];
}
function names(array $list): array { return array_map(fn($i) => $i['name'], $list); }

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "  FluxFiles Sidecar Namespace Test Suite\n";
echo "{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

test('sidecar is stored under _fluxfiles/meta/, not next to the file', function () {
    [$fm, $meta, $fs] = makeEnv();
    up($fm, 'photo.png', 'x');
    $meta->save('local', 'photo.png', ['title' => 'T']);
    assertTrue($fs->fileExists('_fluxfiles/meta/photo.png.json'), 'sidecar in protected namespace');
    assertTrue(!$fs->fileExists('photo.png.meta.json'), 'no sidecar in user namespace');
    assertEqual('T', $meta->get('local', 'photo.png')['title'] ?? '', 'metadata readable');
});

test('user-uploaded *.meta.json is VISIBLE and not treated as a sidecar', function () {
    [$fm, , $fs] = makeEnv();
    $r = up($fm, 'report.meta.json', '{"mine":true}');
    assertTrue(in_array('report.meta.json', names($fm->list('local', '')), true), 'user .meta.json is listed');
    assertEqual('{"mine":true}', $fs->read('report.meta.json'), 'content untouched (not a sidecar)');
});

test('uploading {file}.meta.json does NOT overwrite that file\'s sidecar', function () {
    [$fm, $meta, $fs] = makeEnv();
    up($fm, 'a.txt', 'real');
    $meta->save('local', 'a.txt', ['title' => 'Real Title', 'uploaded_by' => 'owner']);
    // Attacker uploads a.txt.meta.json as a normal user file
    up($fm, 'a.txt.meta.json', '{"uploaded_by":"attacker"}');
    // The real sidecar is untouched
    assertEqual('Real Title', $meta->get('local', 'a.txt')['title'] ?? '', 'sidecar title intact');
    assertEqual('owner', $meta->get('local', 'a.txt')['uploaded_by'] ?? '', 'owner not spoofed');
});

test('a legacy {file}.meta.json sidecar is migrated to the new location on read', function () {
    [$fm, $meta, $fs, $root] = makeEnv();
    file_put_contents($root . '/old.txt', 'data');
    file_put_contents($root . '/old.txt.meta.json', json_encode(['title' => 'Legacy']));
    assertEqual('Legacy', $meta->get('local', 'old.txt')['title'] ?? '', 'reads legacy value');
    assertTrue($fs->fileExists('_fluxfiles/meta/old.txt.json'), 'migrated to new location');
    assertTrue(!$fs->fileExists('old.txt.meta.json'), 'legacy removed');
});

test('move relocates the sidecar; delete removes it', function () {
    [$fm, $meta, $fs] = makeEnv();
    up($fm, 'src.png', 'x');
    $meta->save('local', 'src.png', ['title' => 'Moved']);
    $fm->move('local', 'src.png', 'sub/dst.png');
    assertEqual('Moved', $meta->get('local', 'sub/dst.png')['title'] ?? '', 'metadata followed the move');
    assertTrue($fs->fileExists('_fluxfiles/meta/sub/dst.png.json'), 'sidecar at new key');
    assertTrue(!$fs->fileExists('_fluxfiles/meta/src.png.json'), 'old sidecar gone');

    $fm->delete('local', 'sub/dst.png');
    assertTrue(!$fs->fileExists('_fluxfiles/meta/sub/dst.png.json'), 'sidecar deleted with the file');
});

echo "\n{$cyan}──────────────────────────────────────────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
echo "{$cyan}──────────────────────────────────────────────────{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
