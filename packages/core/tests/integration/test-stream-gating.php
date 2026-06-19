<?php

/**
 * Integration test for gated-local URL minting (M4): a local disk marked
 * `private => true` with a stream secret serves files through tokened
 * /api/fm/stream URLs; otherwise it returns the static disk URL.
 *
 * Usage: php tests/integration/test-stream-gating.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\StreamToken;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
$SECRET = str_repeat('k', 40);

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

/** A FileManager on a fresh local disk; $private toggles gated serving. */
function makeFM(bool $private, bool $withSecret): array
{
    global $SECRET;
    $root = sys_get_temp_dir() . '/ff-stream-' . uniqid();
    @mkdir($root . '/album', 0777, true);
    file_put_contents($root . '/album/clip.mp4', str_repeat('M', 2048));

    $cfg = ['driver' => 'local', 'root' => $root, 'url' => '/storage/uploads'];
    if ($private) { $cfg['private'] = true; }
    $dm = new DiskManager(['local' => $cfg]);
    $claims = new Claims('u1', ['read', 'write'], ['local'], '', 50, null, 0);
    $fm = new FileManager($dm, $claims, new StorageMetadataHandler($dm));
    if ($withSecret) { $fm->setStreamSecret($SECRET); }
    return [$fm, $root];
}

/** The `url` of clip.mp4 from a list() call. */
function clipUrl(FileManager $fm): string
{
    $res = $fm->list('local', 'album');
    foreach (($res['items'] ?? $res) as $it) {
        if (($it['name'] ?? '') === 'clip.mp4') { return (string) ($it['url'] ?? ''); }
    }
    throw new \RuntimeException('clip.mp4 not listed');
}

echo "\n{$cyan}══ Gated-local URL minting (M4) ══{$reset}\n\n";

test('private local + secret → tokened /api/fm/stream URL that verifies to the file', function () use ($SECRET) {
    [$fm] = makeFM(true, true);
    $url = clipUrl($fm);
    assertTrue(strpos($url, '/api/fm/stream?token=') === 0, "got: $url");
    parse_str(parse_url($url, PHP_URL_QUERY), $q);
    $scope = StreamToken::verify($q['token'], $SECRET);
    assertEqual('local', $scope['disk']);
    assertEqual('album/clip.mp4', $scope['path']);
});

test('private local WITHOUT a secret → feature off, static URL', function () {
    [$fm] = makeFM(true, false);
    $url = clipUrl($fm);
    assertEqual('/storage/uploads/album/clip.mp4', $url);
});

test('non-private local → static URL even with a secret', function () {
    [$fm] = makeFM(false, true);
    $url = clipUrl($fm);
    assertEqual('/storage/uploads/album/clip.mp4', $url);
});

test('gated local has NO permanent URL (private → null)', function () {
    [$fm] = makeFM(true, true);
    $m = new \ReflectionMethod($fm, 'permanentUrl');
    $m->setAccessible(true);
    $cfg = new \ReflectionMethod($fm, 'isGatedLocal');
    assertEqual(null, $m->invoke($fm, 'local', 'album/clip.mp4'), 'gated → no permanent url');
});

test('non-gated local still has a permanent URL', function () {
    [$fm] = makeFM(false, true);
    $m = new \ReflectionMethod($fm, 'permanentUrl');
    $m->setAccessible(true);
    assertEqual('/storage/uploads/album/clip.mp4', $m->invoke($fm, 'local', 'album/clip.mp4'));
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
