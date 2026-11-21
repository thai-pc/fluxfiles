<?php

/**
 * A 60 s limiter's sweep must not wipe a longer-window limit (the daily mail caps).
 *
 * Regression (audit 2026-11-20, R4-1): both storages swept by the CALLER's
 * window. File: a 60 s check deleted any shard in its directory untouched for
 * > 2 min, daily caps included. DB: the 1 % global sweep ran
 * `DELETE … WHERE ts < now-60` across every bucket. Either way an anonymous
 * Intake sender could get more than the 3 receipts/day cap to a victim.
 *
 * Usage: php tests/unit/test-rate-limit-window-sweep.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Db\Connection;
use FluxFiles\Db\MigrationRunner;
use FluxFiles\RateLimiterFactory;
use FluxFiles\RateLimiterFileStorage;

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try { $fn(); echo "  PASS {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  FAIL {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m): void { if (!$c) throw new \RuntimeException($m); }
function allowed(callable $check, int $n): int
{
    $ok = 0;
    for ($i = 0; $i < $n; $i++) {
        try { $check(); $ok++; } catch (ApiException $e) { /* limited */ }
    }
    return $ok;
}

const DAILY_BUCKET = 'notify_86400';   // what NotifySupport::allow() uses for a 24 h cap

echo "\nrate-limit sweep respects each window\n";

test('file storage: a daily shard survives 60 s sweeps in its directory', function () {
    $base = sys_get_temp_dir() . '/fxrl-' . uniqid();
    mkdir($base);
    $f = $base . '/rate_limit.json';
    $daily = new RateLimiterFileStorage($f, 3, 3, 86400);
    $key = 'notify_addr_receipt:victim';
    assertTrue(allowed(fn () => $daily->check($key, DAILY_BUCKET), 5) === 3, 'daily cap 3 in a burst');
    $h = sha1($key . ':' . DAILY_BUCKET);
    $shard = "$base/rate_limit.d/" . substr($h, 0, 2) . "/$h.json";
    touch($shard, time() - 600);   // the 3 sends were 10 minutes ago
    for ($n = 0; ; $n++) { $k = "user$n"; if (substr(sha1($k . ':read'), 0, 2) === substr($h, 0, 2)) break; }
    $m = new RateLimiterFileStorage($f, 100000, 100000, 60);
    for ($i = 0; $i < 1500; $i++) { $m->check($k, 'read'); }
    clearstatcache();
    assertTrue(file_exists($shard), 'the daily shard was swept by a 60 s limiter');
    assertTrue(allowed(fn () => $daily->check($key, DAILY_BUCKET), 5) === 0, 'the daily cap must still hold');
});

test('file storage: an expired 60 s shard is still swept', function () {
    $base = sys_get_temp_dir() . '/fxrl-' . uniqid();
    mkdir($base);
    $f = $base . '/rate_limit.json';
    $m = new RateLimiterFileStorage($f, 100000, 100000, 60);
    $m->check('old-user', 'read');
    $h = sha1('old-user:read');
    $shard = "$base/rate_limit.d/" . substr($h, 0, 2) . "/$h.json";
    touch($shard, time() - 600);
    for ($n = 0; ; $n++) { $k = "u$n"; if ($k !== 'old-user' && substr(sha1($k . ':read'), 0, 2) === substr($h, 0, 2)) break; }
    for ($i = 0; $i < 1500 && file_exists($shard); $i++) { $m->check($k, 'read'); clearstatcache(); }
    assertTrue(!file_exists($shard), 'a stale 60 s shard must still be cleaned up');
});

test('DB storage (sqlite): the global sweep leaves other buckets alone', function () {
    $conn = new Connection('sqlite::memory:');
    (new MigrationRunner($conn))->migrate(__DIR__ . '/../../db/migrations');
    $daily = fn () => RateLimiterFactory::makeDb($conn, 3, 3, 86400);
    $key = 'notify_addr_receipt:' . hash('sha256', 'victim@example.com');
    assertTrue(allowed(fn () => $daily()->check($key, DAILY_BUCKET), 4) === 3, 'daily cap 3');
    $conn->pdo()->exec('UPDATE rate_limits SET ts = ts - 600');
    for ($n = 1; $n <= 1500; $n++) {
        RateLimiterFactory::makeDb($conn, 1000, 1000, 60)->check('share:' . $n, 'read');
    }
    $left = (int) $conn->pdo()->query('SELECT COUNT(*) FROM rate_limits WHERE identifier = ' . $conn->pdo()->quote($key))->fetchColumn();
    assertTrue($left === 3, "daily rows must survive 60 s sweeps (left: $left)");
    assertTrue(allowed(fn () => $daily()->check($key, DAILY_BUCKET), 2) === 0, 'receipt #4 the same day is refused');
});

test('NotifySupport::allow uses a per-window bucket that fits the 16-char column', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../api/NotifySupport.php');
    assertTrue(str_contains($src, "'notify_' . \$windowSeconds"), 'allow() must pass a window-specific bucket');
    assertTrue(strlen('notify_' . 31536000) <= 16, 'even a one-year window fits bucket VARCHAR(16)');
});

echo "\n  Total: " . ($passed + $failed) . "  Passed: {$passed}  Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
