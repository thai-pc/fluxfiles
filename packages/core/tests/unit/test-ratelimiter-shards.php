<?php

/**
 * RateLimiterFileStorage keeps one small file per userId:actionType instead of
 * one global rate_limit.json: limits are still enforced per caller, callers
 * don't share a lock or a file, the legacy constructor (a file path) still
 * works, and stale shard files are swept.
 *
 *   php tests/unit/test-ratelimiter-shards.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\RateLimiterFileStorage;

$green = "\033[32m"; $red = "\033[31m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function throttled(RateLimiterFileStorage $rl, string $u, string $a): bool
{
    try { $rl->check($u, $a); return false; }
    catch (ApiException $e) { return $e->getErrorCode() === 'rate_limited' && $e->getHttpCode() === 429; }
}
function tmpPath(): string { return sys_get_temp_dir() . '/ff-rls-' . bin2hex(random_bytes(4)) . '/rate_limit.json'; }
function shardFiles(string $filePath): array
{
    $root = dirname($filePath) . '/rate_limit.d';
    return is_dir($root) ? glob($root . '/*/*.json') : [];
}

test('legacy constructor (file path) works and enforces the limit', function () {
    $rl = new RateLimiterFileStorage(tmpPath(), 3, 10, 60);
    for ($i = 0; $i < 3; $i++) { $rl->check('u', 'read'); }
    assertTrue(throttled($rl, 'u', 'read'), '4th read is 429');
    $rl->check('u', 'write'); // separate bucket
});

test('two users do not block each other and keep separate files', function () {
    $p = tmpPath();
    $rl = new RateLimiterFileStorage($p, 2, 2, 60);
    $rl->check('alice', 'read'); $rl->check('alice', 'read');
    assertTrue(throttled($rl, 'alice', 'read'), 'alice throttled');
    $rl->check('bob', 'read'); $rl->check('bob', 'read');
    assertTrue(throttled($rl, 'bob', 'read'), 'bob throttled independently');
    assertTrue(count(shardFiles($p)) === 2, 'one shard file per caller+action');
});

test('a pre-sharding global file is replaced by an epoch marker; its counters are ignored', function () {
    $p = tmpPath();
    mkdir(dirname($p), 0775, true);
    file_put_contents($p, json_encode(['u:read' => array_fill(0, 99, time())]));
    $rl = new RateLimiterFileStorage($p, 2, 2, 60);
    $rl->check('u', 'read'); // would be throttled if the old counters were honoured
    assertTrue(preg_match('/^ffrl1:[0-9a-f]{16}$/', (string) file_get_contents($p)) === 1, 'marker written');
});

test('deleting rate_limit.json still resets every limit (the long-standing reset gesture)', function () {
    $p = tmpPath();
    $rl = new RateLimiterFileStorage($p, 1, 1, 60);
    $rl->check('a', 'read'); $rl->check('b', 'read');
    assertTrue(throttled($rl, 'a', 'read') && throttled($rl, 'b', 'read'), 'both throttled');
    unlink($p);
    $rl->check('a', 'read'); $rl->check('b', 'read'); // fresh buckets
    assertTrue(throttled($rl, 'a', 'read'), 'counting resumes after the reset');
});

test('the epoch marker is stable between requests (no reset)', function () {
    $p = tmpPath();
    $rl = new RateLimiterFileStorage($p, 1, 1, 60);
    $rl->check('a', 'read');
    $m = file_get_contents($p);
    assertTrue(throttled($rl, 'a', 'read'), 'throttled');
    assertTrue(file_get_contents($p) === $m, 'marker unchanged');
});

test('the shard holds only that caller\'s timestamps and is 0600', function () {
    $p = tmpPath();
    $rl = new RateLimiterFileStorage($p, 5, 5, 60);
    $rl->check('x', 'read'); $rl->check('y', 'read'); $rl->check('x', 'read');
    $h = sha1('x:read');
    $f = dirname($p) . '/rate_limit.d/' . substr($h, 0, 2) . '/' . $h . '.json';
    $d = json_decode((string) file_get_contents($f), true);
    assertTrue(is_array($d) && count($d['t'] ?? []) === 2, 'x has two timestamps');
    assertTrue((fileperms($f) & 0777) === 0600, 'mode 0600');
});

test('an unwritable storage dir still surfaces storage_not_writable', function () {
    $blocker = sys_get_temp_dir() . '/ff-rls-file-' . bin2hex(random_bytes(4));
    file_put_contents($blocker, 'x');
    $rl = new RateLimiterFileStorage($blocker . '/sub/rate_limit.json', 2, 2, 60);
    try { $rl->check('u', 'read'); throw new \RuntimeException('should throw'); }
    catch (ApiException $e) { assertTrue($e->getErrorCode() === 'storage_not_writable', 'code ' . $e->getErrorCode()); }
    unlink($blocker);
});

test('sweep removes shard files whose window is long gone, keeps fresh ones', function () {
    $p = tmpPath();
    $rl = new RateLimiterFileStorage($p, 5, 5, 60);
    $rl->check('old', 'read');
    $rl->check('fresh', 'read');
    $h = sha1('old:read');
    $oldFile = dirname($p) . '/rate_limit.d/' . substr($h, 0, 2) . '/' . $h . '.json';
    touch($oldFile, time() - 600);
    $m = new ReflectionMethod($rl, 'sweep');
    $m->setAccessible(true);
    foreach (glob(dirname($p) . '/rate_limit.d/*', GLOB_ONLYDIR) as $dir) { $m->invoke($rl, $dir, time()); }
    assertTrue(!file_exists($oldFile), 'stale shard swept');
    assertTrue(count(shardFiles($p)) === 1, 'fresh shard kept');
});

test('cost per request is independent of how many other callers exist', function () {
    $p = tmpPath();
    $rl = new RateLimiterFileStorage($p, 100000, 100000, 60);
    for ($u = 0; $u < 300; $u++) { $rl->check("user$u", 'read'); }
    $t = hrtime(true);
    for ($i = 0; $i < 50; $i++) { $rl->check('me', 'read'); }
    $ms = (hrtime(true) - $t) / 1e6 / 50;
    assertTrue($ms < 5, sprintf('%.2f ms/request with 300 other callers', $ms));
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
