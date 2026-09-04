<?php

/**
 * Sidecar namespace — local metadata sidecars live under the protected
 * `_fluxfiles/meta/` namespace, NOT in the user file namespace. `*.meta.json` is a
 * reserved filename shape (FileManager::assertNotSystem()) so a user can no longer
 * create/rename/move/copy a file into that shape at all — closing the ownership-
 * hijack hole where a forged legacy sidecar used to be trusted on read for a target
 * with no modern sidecar yet. A genuinely pre-existing legacy `{file}.meta.json`
 * sidecar (predating the guard) is still migrated on read, for backward compat.
 *
 * Usage:
 *   php tests/integration/test-sidecar-namespace.php
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

test('user-uploaded *.meta.json is REJECTED as a reserved filename', function () {
    [$fm, , $fs] = makeEnv();
    try {
        up($fm, 'report.meta.json', '{"mine":true}');
        throw new \RuntimeException('expected 403 system_path');
    } catch (ApiException $e) {
        assertEqual('system_path', $e->getErrorCode(), 'reserved filename rejected');
    }
    assertTrue(!$fs->fileExists('report.meta.json'), 'nothing was written');
});

test('uploading {file}.meta.json (even with force_upload) can\'t forge that file\'s sidecar', function () {
    [$fm, $meta, $fs] = makeEnv();
    up($fm, 'a.txt', 'real');
    $meta->save('local', 'a.txt', ['title' => 'Real Title', 'uploaded_by' => 'owner']);
    // Attacker tries to upload a.txt.meta.json (force_upload=true, as `up()` always does)
    // to forge ownership of a.txt — blocked outright as a reserved filename.
    try {
        up($fm, 'a.txt.meta.json', '{"uploaded_by":"attacker"}');
        throw new \RuntimeException('expected 403 system_path');
    } catch (ApiException $e) {
        assertEqual('system_path', $e->getErrorCode());
    }
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
