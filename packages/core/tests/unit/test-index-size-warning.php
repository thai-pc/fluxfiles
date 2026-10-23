<?php

/**
 * The JSON backend's disk-wide metadata index: its size is reported to the usage
 * dashboard, and past FLUXFILES_INDEX_WARN_MB the server logs (at most hourly per
 * disk) that it's time for the database backend.
 *
 * Usage:
 *   php tests/unit/test-index-size-warning.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\DiskManager;
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

function env(?string $v): void
{
    if ($v === null) {
        unset($_ENV['FLUXFILES_INDEX_WARN_MB']);
        putenv('FLUXFILES_INDEX_WARN_MB');
    } else {
        $_ENV['FLUXFILES_INDEX_WARN_MB'] = $v;
    }
}

/** A fresh local disk + handler; the warn marker is keyed on the root, so each is new. */
function handler(): array
{
    $root = sys_get_temp_dir() . '/ff-idxwarn-' . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/s']]);
    return [new StorageMetadataHandler($dm), $root];
}

/** Run $fn with error_log() captured; returns the lines it logged. */
function logged(callable $fn): array
{
    $file = tempnam(sys_get_temp_dir(), 'fflog');
    $prev = ini_set('error_log', $file);
    try { $fn(); } finally { ini_set('error_log', (string) $prev); }
    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));
    @unlink($file);
    return $lines;
}

echo "\n{$cyan}══ Metadata index size warning ══{$reset}\n\n";

test('threshold: 5 MB by default, configurable, 0 = off', function () {
    env(null);
    assertTrue(StorageMetadataHandler::indexWarnBytes() === 5 * 1024 * 1024, 'default');
    env('0.5');
    assertTrue(StorageMetadataHandler::indexWarnBytes() === 524288, 'fractional MB');
    env('0');
    assertTrue(StorageMetadataHandler::indexWarnBytes() === 0, 'off');
    env(null);
});

test('indexBytes: null without an index, the file size once one is written', function () {
    [$meta, $root] = handler();
    assertTrue($meta->indexBytes('local') === null, 'no index yet');
    $meta->save('local', 'a.jpg', ['title' => 'A']);
    $size = $meta->indexBytes('local');
    assertTrue($size !== null && $size === filesize("{$root}/_fluxfiles/index.json"), "size {$size}");
});

test('a write past the threshold logs once, then stays quiet for the hour', function () {
    [$meta] = handler();
    env('0.0001'); // ~100 bytes
    $lines = logged(function () use ($meta) {
        $meta->save('local', 'a.jpg', ['title' => str_repeat('A', 200)]);
        $meta->save('local', 'b.jpg', ['title' => 'B']);
        $meta->save('local', 'c.jpg', ['title' => 'C']);
    });
    env(null);
    $hits = array_filter($lines, fn ($l) => strpos($l, 'metadata index for disk') !== false);
    assertTrue(count($hits) === 1, 'logged ' . count($hits) . ' times');
    assertTrue(strpos(implode("\n", $hits), 'FLUXFILES_STORAGE_BACKEND=db') !== false, 'names the fix');
});

test('under the threshold, or with the warning off, nothing is logged', function () {
    [$meta] = handler();
    env(null);
    $quiet = logged(fn () => $meta->save('local', 'a.jpg', ['title' => 'A']));
    [$meta2] = handler();
    env('0');
    $off = logged(fn () => $meta2->save('local', 'a.jpg', ['title' => str_repeat('A', 5000)]));
    env(null);
    assertTrue($quiet === [] && $off === [], 'quiet: ' . json_encode([$quiet, $off]));
});

echo "\n  Total: " . ($passed + $failed) . "  Passed: {$passed}  Failed: {$failed}\n\n";
exit($failed > 0 ? 1 : 0);
