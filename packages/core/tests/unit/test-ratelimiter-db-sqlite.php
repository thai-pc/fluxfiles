<?php

/**
 * Test suite for FluxFiles\Db\RateLimiterDbStorage — the `db` storage
 * backend's sliding-window-log rate limiter, over a temp SQLite file.
 *
 * Usage:
 *   php tests/unit/test-ratelimiter-db-sqlite.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../embed.php';

use FluxFiles\Db\Connection;
use FluxFiles\Db\MigrationRunner;
use FluxFiles\Db\RateLimiterDbStorage;

$green  = "\033[32m";
$red    = "\033[31m";
$yellow = "\033[33m";
$cyan   = "\033[36m";
$reset  = "\033[0m";

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try {
        $fn();
        echo "  {$green}PASS{$reset} {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

function assertEqual($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            $msg ?: 'Expected ' . json_encode($expected) . ' but got ' . json_encode($actual)
        );
    }
}

function freshDb(string $dbFile): Connection
{
    @unlink($dbFile);
    @unlink($dbFile . '-wal');
    @unlink($dbFile . '-shm');
    $conn = new Connection('sqlite:' . $dbFile);
    (new MigrationRunner($conn))->migrate(__DIR__ . '/../../db/migrations');
    return $conn;
}

$dbFile = '/tmp/ff_test_ratelimit_db_' . getmypid() . '.sqlite3';
$vendorAutoload = realpath(__DIR__ . '/../../vendor/autoload.php');
$embedFile = realpath(__DIR__ . '/../../embed.php');

echo "\n{$cyan}╔══════════════════════════════════════════════════╗{$reset}\n";
echo "{$cyan}║   RateLimiterDbStorage (SQLite) Test Suite        ║{$reset}\n";
echo "{$cyan}╚══════════════════════════════════════════════════╝{$reset}\n\n";

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► Read rate limiting{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('Read within limit passes', function () use ($dbFile) {
    $conn = freshDb($dbFile);
    $limiter = new RateLimiterDbStorage($conn, readLimit: 5, writeLimit: 3, windowSeconds: 60);
    for ($i = 0; $i < 5; $i++) {
        $limiter->check('user-read', 'read');
    }
});

test('Read over limit throws ApiException 429', function () use ($dbFile) {
    $conn = freshDb($dbFile);
    $limiter = new RateLimiterDbStorage($conn, readLimit: 3, writeLimit: 3, windowSeconds: 60);
    for ($i = 0; $i < 3; $i++) {
        $limiter->check('user-read-over', 'read');
    }
    try {
        $limiter->check('user-read-over', 'read');
        throw new \RuntimeException('Should have thrown ApiException');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(429, $e->getCode());
    }
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► Write rate limiting{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('Write within limit passes', function () use ($dbFile) {
    $conn = freshDb($dbFile);
    $limiter = new RateLimiterDbStorage($conn, readLimit: 5, writeLimit: 3, windowSeconds: 60);
    for ($i = 0; $i < 3; $i++) {
        $limiter->check('user-write', 'write');
    }
});

test('Write over limit throws ApiException 429 with Retry-After', function () use ($dbFile) {
    $conn = freshDb($dbFile);
    $limiter = new RateLimiterDbStorage($conn, readLimit: 5, writeLimit: 2, windowSeconds: 60);
    for ($i = 0; $i < 2; $i++) {
        $limiter->check('user-write-over', 'write');
    }
    try {
        $limiter->check('user-write-over', 'write');
        throw new \RuntimeException('Should have thrown ApiException');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(429, $e->getCode());
        assertEqual('rate_limited', $e->getErrorCode());
    }
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► Key isolation{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('Different identifiers have independent limits', function () use ($dbFile) {
    $conn = freshDb($dbFile);
    $limiter = new RateLimiterDbStorage($conn, readLimit: 2, writeLimit: 2, windowSeconds: 60);
    for ($i = 0; $i < 2; $i++) {
        $limiter->check('user-a', 'read');
    }
    try {
        $limiter->check('user-a', 'read');
        throw new \RuntimeException('User A should have been blocked');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(429, $e->getCode());
    }
    $limiter->check('user-b', 'read');
});

test('Different buckets on the same identifier are independent', function () use ($dbFile) {
    $conn = freshDb($dbFile);
    $limiter = new RateLimiterDbStorage($conn, readLimit: 1, writeLimit: 1, windowSeconds: 60);
    $limiter->check('same-user', 'read');
    try {
        $limiter->check('same-user', 'read');
        throw new \RuntimeException('read bucket should have been exhausted');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(429, $e->getCode());
    }
    // write bucket for the same identifier is untouched
    $limiter->check('same-user', 'write');
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► Storage{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('a hit row is inserted per check()', function () use ($dbFile) {
    $conn = freshDb($dbFile);
    $limiter = new RateLimiterDbStorage($conn, readLimit: 5, writeLimit: 5, windowSeconds: 60);
    $limiter->check('user-row', 'read');
    $stmt = $conn->pdo()->prepare('SELECT COUNT(*) FROM rate_limits WHERE identifier = ? AND bucket = ?');
    $stmt->execute(['user-row', 'read']);
    assertEqual(1, (int) $stmt->fetchColumn());
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► Window expiry (sliding log){$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('Rate limit resets after window expires', function () use ($dbFile) {
    $conn = freshDb($dbFile);
    $limiter = new RateLimiterDbStorage($conn, readLimit: 1, writeLimit: 1, windowSeconds: 1);
    $limiter->check('user-expire', 'read');
    try {
        $limiter->check('user-expire', 'read');
        throw new \RuntimeException('Should have been blocked');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(429, $e->getCode());
    }
    sleep(2);
    $limiter->check('user-expire', 'read');
});

test('an old hit outside the window does not count toward the limit', function () use ($dbFile) {
    $conn = freshDb($dbFile);
    $stmt = $conn->pdo()->prepare('INSERT INTO rate_limits (identifier, bucket, ts) VALUES (?, ?, ?)');
    $stmt->execute(['user-old-hit', 'read', time() - 120]);

    $limiter = new RateLimiterDbStorage($conn, readLimit: 1, writeLimit: 1, windowSeconds: 60);
    // The stale row (120s old, 60s window) must be pruned/ignored, leaving room for this check.
    $limiter->check('user-old-hit', 'read');
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► Concurrency (multi-process, same SQLite file){$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('concurrent checks against the same key never exceed the limit', function () use ($dbFile, $vendorAutoload, $embedFile) {
    $conn = freshDb($dbFile);
    $limit = 5;
    $identifier = 'concurrent-user';
    $bucket = 'read';
    $procCount = 12;

    $workerScript = tempnam(sys_get_temp_dir(), 'ff_rl_worker_') . '.php';
    file_put_contents($workerScript, <<<PHP
<?php
require '{$vendorAutoload}';
require '{$embedFile}';
\$conn = new \\FluxFiles\\Db\\Connection('sqlite:{$dbFile}');
\$limiter = new \\FluxFiles\\Db\\RateLimiterDbStorage(\$conn, readLimit: {$limit}, writeLimit: {$limit}, windowSeconds: 60);
try {
    \$limiter->check('{$identifier}', '{$bucket}');
    echo "OK";
} catch (\\FluxFiles\\ApiException \$e) {
    echo "LIMITED";
}
PHP
    );

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $procs = [];
    $pipes = [];
    for ($i = 0; $i < $procCount; $i++) {
        $proc = proc_open(['php', $workerScript], $descriptors, $p);
        if ($proc === false) {
            throw new \RuntimeException('failed to spawn worker process');
        }
        $procs[] = $proc;
        $pipes[] = $p;
    }

    $okCount = 0;
    foreach ($pipes as $i => $p) {
        $out = stream_get_contents($p[1]);
        stream_get_contents($p[2]);
        fclose($p[1]);
        fclose($p[2]);
        proc_close($procs[$i]);
        if ($out === 'OK') {
            $okCount++;
        } elseif ($out !== 'LIMITED') {
            throw new \RuntimeException("worker produced unexpected output: {$out}");
        }
    }

    @unlink($workerScript);

    assertEqual($limit, $okCount, "expected exactly {$limit} accepted hits out of {$procCount} concurrent attempts, got {$okCount}");
});

// ═══════════════════════════════════════════════════════════════
// Cleanup
// ═══════════════════════════════════════════════════════════════

@unlink($dbFile);
@unlink($dbFile . '-wal');
@unlink($dbFile . '-shm');

// ═══════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════

echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "{$cyan}  Results: {$green}{$passed} passed{$reset}";
if ($failed > 0) {
    echo ", {$red}{$failed} failed{$reset}";
}
echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
