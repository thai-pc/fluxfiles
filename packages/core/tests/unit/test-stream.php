<?php

/**
 * Unit tests for gated local media streaming (M4):
 *   - RangeStreamer::parseRange (HTTP Range parsing, the seek-without-rewind core)
 *   - RangeStreamer::stream     (206/200/416 + correct bytes via fseek)
 *   - StreamToken               (mint/verify, scope, tamper, expiry, wrong type)
 *
 * Usage: php tests/unit/test-stream.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\RangeStreamer;
use FluxFiles\StreamToken;
use FluxFiles\ApiException;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

echo "\n{$cyan}══ Gated local media streaming (M4) ══{$reset}\n\n";

// ── RangeStreamer::parseRange ─────────────────────────────────────────────
test('parseRange: no header → whole file [0, size-1]', function () {
    assertEqual([0, 99], RangeStreamer::parseRange(null, 100));
    assertEqual([0, 99], RangeStreamer::parseRange('', 100));
});

test('parseRange: explicit start-end', function () {
    assertEqual([10, 20], RangeStreamer::parseRange('bytes=10-20', 100));
});

test('parseRange: open-ended start- → to last byte', function () {
    assertEqual([50, 99], RangeStreamer::parseRange('bytes=50-', 100));
});

test('parseRange: suffix -N → last N bytes', function () {
    assertEqual([90, 99], RangeStreamer::parseRange('bytes=-10', 100));
    assertEqual([0, 99], RangeStreamer::parseRange('bytes=-500', 100)); // clamp to start
});

test('parseRange: end clamped to size-1', function () {
    assertEqual([10, 99], RangeStreamer::parseRange('bytes=10-9999', 100));
});

test('parseRange: unsatisfiable → null (caller sends 416)', function () {
    assertEqual(null, RangeStreamer::parseRange('bytes=100-200', 100), 'start >= size');
    assertEqual(null, RangeStreamer::parseRange('bytes=50-10', 100), 'start > end');
    assertEqual(null, RangeStreamer::parseRange('bytes=-', 100), 'empty range');
    assertEqual(null, RangeStreamer::parseRange('bytes=abc', 100), 'garbage');
    assertEqual(null, RangeStreamer::parseRange('items=0-10', 100), 'wrong unit');
    assertEqual(null, RangeStreamer::parseRange('bytes=0-10', 0), 'explicit range against empty file');
});

test('parseRange: 0-byte file with no Range header → [0, -1] (empty 200, not 416)', function () {
    assertEqual([0, -1], RangeStreamer::parseRange(null, 0));
    assertEqual([0, -1], RangeStreamer::parseRange('', 0));
});

// ── RangeStreamer::stream (capture headers + body) ────────────────────────
/** Run stream() against a temp file, capturing emitted headers + body. */
function runStream(string $content, ?string $range): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'ffstream');
    file_put_contents($tmp, $content);
    $headers = [];
    $code = 200;
    $sink = function (string $h, int $c = 0) use (&$headers, &$code): void {
        $headers[] = $h;
        if ($c > 0) { $code = $c; }
    };
    ob_start();
    RangeStreamer::stream($tmp, 'video/mp4', $range, $sink);
    $body = ob_get_clean();
    @unlink($tmp);
    return ['code' => $code, 'headers' => $headers, 'body' => $body];
}

test('stream: no Range → 200 full body + Accept-Ranges', function () {
    $r = runStream('ABCDEFGHIJ', null);
    assertEqual(200, $r['code']);
    assertEqual('ABCDEFGHIJ', $r['body']);
    assertTrue(in_array('Accept-Ranges: bytes', $r['headers'], true), 'advertises ranges');
    assertTrue(in_array('Content-Length: 10', $r['headers'], true), 'content-length');
});

test('stream: Range bytes=2-5 → 206 + exact slice (fseek, no rewind)', function () {
    $r = runStream('ABCDEFGHIJ', 'bytes=2-5');
    assertEqual(206, $r['code']);
    assertEqual('CDEF', $r['body']);
    assertTrue(in_array('Content-Range: bytes 2-5/10', $r['headers'], true), 'content-range');
    assertTrue(in_array('Content-Length: 4', $r['headers'], true), 'partial length');
});

test('stream: suffix Range bytes=-3 → last 3 bytes', function () {
    $r = runStream('ABCDEFGHIJ', 'bytes=-3');
    assertEqual(206, $r['code']);
    assertEqual('HIJ', $r['body']);
});

test('stream: unsatisfiable Range → 416 + Content-Range */size, no body', function () {
    $r = runStream('ABCDEFGHIJ', 'bytes=50-60');
    assertEqual(416, $r['code']);
    assertEqual('', $r['body']);
    assertTrue(in_array('Content-Range: bytes */10', $r['headers'], true), '416 extent');
});

test('stream: large file seek returns the correct middle slice', function () {
    $content = str_repeat('X', 1_000_000) . 'NEEDLE' . str_repeat('Y', 1_000_000);
    $start = 1_000_000; $end = 1_000_005; // "NEEDLE"
    $r = runStream($content, "bytes={$start}-{$end}");
    assertEqual(206, $r['code']);
    assertEqual('NEEDLE', $r['body'], 'seek landed exactly, no rewind');
});

// ── StreamToken ───────────────────────────────────────────────────────────
$secret = str_repeat('s', 40);

test('StreamToken: mint → verify round-trips disk/path/sub', function () use ($secret) {
    $tok = StreamToken::mint('local', 'user_1/clip.mp4', 'u1', 3600, $secret);
    $scope = StreamToken::verify($tok, $secret);
    assertEqual('local', $scope['disk']);
    assertEqual('user_1/clip.mp4', $scope['path']);
    assertEqual('u1', $scope['sub']);
});

test('StreamToken: wrong secret → 403', function () use ($secret) {
    $tok = StreamToken::mint('local', 'a.mp4', 'u1', 3600, $secret);
    try { StreamToken::verify($tok, str_repeat('x', 40)); throw new \RuntimeException('should reject'); }
    catch (ApiException $e) { assertEqual('stream_token_invalid', $e->getErrorCode()); }
});

test('StreamToken: expired → 403', function () use ($secret) {
    // Mint already-expired by signing a payload in the past via JwtCompat directly.
    $past = ['t' => 'stream', 'disk' => 'local', 'path' => 'a.mp4', 'sub' => 'u1',
             'iat' => time() - 100, 'exp' => time() - 10];
    $tok = \FluxFiles\JwtCompat::encode($past, $secret);
    try { StreamToken::verify($tok, $secret); throw new \RuntimeException('should reject'); }
    catch (ApiException $e) { assertEqual('stream_token_invalid', $e->getErrorCode()); }
});

test('StreamToken: a main access JWT (no t=stream) is rejected', function () use ($secret) {
    $access = \FluxFiles\JwtCompat::encode(
        ['sub' => 'u1', 'perms' => ['read'], 'disks' => ['local'], 'exp' => time() + 3600],
        $secret
    );
    try { StreamToken::verify($access, $secret); throw new \RuntimeException('should reject'); }
    catch (ApiException $e) { assertEqual('stream_token_invalid', $e->getErrorCode()); }
});

test('StreamToken: TTL is clamped to MAX_TTL', function () use ($secret) {
    $tok = StreamToken::mint('local', 'a.mp4', 'u1', 999999, $secret);
    $p = \FluxFiles\JwtCompat::decode($tok, $secret);
    assertTrue(($p->exp - $p->iat) <= StreamToken::MAX_TTL, 'ttl clamped');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
