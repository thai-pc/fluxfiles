<?php

/**
 * Test script for RateLimiterFileStorage.
 *
 * Usage:
 *   php tests/test-ratelimiter.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

require_once __DIR__ . '/../embed.php';

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
            $msg ?: "Expected " . json_encode($expected) . " but got " . json_encode($actual)
        );
    }
}

$testFile = '/tmp/ff_test_ratelimit.json';

// Clean up any leftover file from previous runs
if (file_exists($testFile)) {
    unlink($testFile);
}

echo "\n{$cyan}╔══════════════════════════════════════════════════╗{$reset}\n";
echo "{$cyan}║      FluxFiles RateLimiter Test Suite             ║{$reset}\n";
echo "{$cyan}╚══════════════════════════════════════════════════╝{$reset}\n\n";

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► Read rate limiting{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('Read within limit passes', function () use ($testFile) {
    @unlink($testFile);
    $limiter = new FluxFiles\RateLimiterFileStorage($testFile, readLimit: 5, writeLimit: 3, windowSeconds: 60);

    // 5 reads should all pass
    for ($i = 0; $i < 5; $i++) {
        $limiter->check('user-read', 'read');
    }
    // If we got here without exception, the test passes
});

test('Read over limit throws ApiException 429', function () use ($testFile) {
    @unlink($testFile);
    $limiter = new FluxFiles\RateLimiterFileStorage($testFile, readLimit: 3, writeLimit: 3, windowSeconds: 60);

    // Use up all 3 read slots
    for ($i = 0; $i < 3; $i++) {
        $limiter->check('user-read-over', 'read');
    }

    // The 4th read should throw
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

test('Write within limit passes', function () use ($testFile) {
    @unlink($testFile);
    $limiter = new FluxFiles\RateLimiterFileStorage($testFile, readLimit: 5, writeLimit: 3, windowSeconds: 60);

    // 3 writes should all pass
    for ($i = 0; $i < 3; $i++) {
        $limiter->check('user-write', 'write');
    }
});

test('Write over limit throws ApiException 429', function () use ($testFile) {
    @unlink($testFile);
    $limiter = new FluxFiles\RateLimiterFileStorage($testFile, readLimit: 5, writeLimit: 2, windowSeconds: 60);

    // Use up all 2 write slots
    for ($i = 0; $i < 2; $i++) {
        $limiter->check('user-write-over', 'write');
    }

    // The 3rd write should throw
    try {
        $limiter->check('user-write-over', 'write');
        throw new \RuntimeException('Should have thrown ApiException');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(429, $e->getCode());
    }
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► User isolation{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('Different users have independent limits', function () use ($testFile) {
    @unlink($testFile);
    $limiter = new FluxFiles\RateLimiterFileStorage($testFile, readLimit: 2, writeLimit: 2, windowSeconds: 60);

    // User A uses up all read slots
    for ($i = 0; $i < 2; $i++) {
        $limiter->check('user-a', 'read');
    }

    // User A should be blocked
    try {
        $limiter->check('user-a', 'read');
        throw new \RuntimeException('User A should have been blocked');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(429, $e->getCode());
    }

    // User B should still be able to read
    $limiter->check('user-b', 'read');
    // If we got here, user B was not blocked — test passes
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► File storage{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('Rate limit file is created', function () use ($testFile) {
    @unlink($testFile);
    assertEqual(false, file_exists($testFile));

    $limiter = new FluxFiles\RateLimiterFileStorage($testFile, readLimit: 5, writeLimit: 5, windowSeconds: 60);
    $limiter->check('user-file', 'read');

    assertEqual(true, file_exists($testFile), 'Rate limit file should exist after first check');

    // Verify it contains valid JSON
    $data = json_decode(file_get_contents($testFile), true);
    assertEqual(true, is_array($data), 'File should contain valid JSON array');
    assertEqual(true, isset($data['user-file:read']), 'File should have entry for user-file:read');
});

// ═══════════════════════════════════════════════════════════════
echo "\n{$yellow}► Window expiry{$reset}\n";
// ═══════════════════════════════════════════════════════════════

test('Rate limit resets after window expires', function () use ($testFile) {
    @unlink($testFile);
    // Use a 1-second window with a limit of 1
    $limiter = new FluxFiles\RateLimiterFileStorage($testFile, readLimit: 1, writeLimit: 1, windowSeconds: 1);

    // Use up the single read slot
    $limiter->check('user-expire', 'read');

    // Should be blocked now
    try {
        $limiter->check('user-expire', 'read');
        throw new \RuntimeException('Should have been blocked');
    } catch (FluxFiles\ApiException $e) {
        assertEqual(429, $e->getCode());
    }

    // Wait for the window to expire
    sleep(2);

    // Should be allowed again
    $limiter->check('user-expire', 'read');
    // If we got here without exception, the window reset worked
});

// ═══════════════════════════════════════════════════════════════
// Cleanup
// ═══════════════════════════════════════════════════════════════

if (file_exists($testFile)) {
    unlink($testFile);
}

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
