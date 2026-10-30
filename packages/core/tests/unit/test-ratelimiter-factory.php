<?php

/**
 * RateLimiterFactory: env-selected make(), plus the explicit makeFile()/makeDb()
 * entry points the Laravel/WordPress proxies use to get the same limiters.
 *
 * Usage: php tests/unit/test-ratelimiter-factory.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Db\Connection;
use FluxFiles\Db\MigrationRunner;
use FluxFiles\Db\RateLimiterDbStorage;
use FluxFiles\RateLimiterFactory;
use FluxFiles\RateLimiterFileStorage;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m !== '' ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

$dir = sys_get_temp_dir() . '/ff-rlf-' . bin2hex(random_bytes(4));
mkdir($dir, 0755, true);
register_shutdown_function(static function () use ($dir) { exec('rm -rf ' . escapeshellarg($dir)); });
$savedBackend = $_ENV['FLUXFILES_STORAGE_BACKEND'] ?? null;
$savedPath = $_ENV['FLUXFILES_STORAGE_PATH'] ?? null;

echo "\n{$cyan}══ RateLimiterFactory ══{$reset}\n\n";

test('make(): json backend -> file limiter under FLUXFILES_STORAGE_PATH', function () use ($dir) {
    $_ENV['FLUXFILES_STORAGE_BACKEND'] = 'json';
    $_ENV['FLUXFILES_STORAGE_PATH'] = $dir;
    $l = RateLimiterFactory::make(2, 2, 60);
    assertTrue($l instanceof RateLimiterFileStorage);
    $l->check('u', 'read');
    $l->check('u', 'read');
    try { $l->check('u', 'read'); throw new \RuntimeException('third call should 429'); }
    catch (\FluxFiles\ApiException $e) { assertEqual(429, $e->getHttpCode()); }
});

test('makeFile(): same limiter, explicit directory, tolerant of a trailing slash', function () use ($dir) {
    $l = RateLimiterFactory::makeFile($dir . '/', 1, 1);
    assertTrue($l instanceof RateLimiterFileStorage);
    $l->check('x', 'read');
    assertTrue(is_file($dir . '/rate_limit.json'), 'epoch marker lives at <dir>/rate_limit.json');
});

test('makeDb(): DB limiter over an explicit connection regardless of env; make() in db mode uses the given connection', function () {
    $conn = new Connection('sqlite::memory:');
    (new MigrationRunner($conn))->migrate(__DIR__ . '/../../db/migrations');
    $_ENV['FLUXFILES_STORAGE_BACKEND'] = 'json';
    $l = RateLimiterFactory::makeDb($conn, 1, 1);
    assertTrue($l instanceof RateLimiterDbStorage);
    $l->check('u', 'write');
    try { $l->check('u', 'write'); throw new \RuntimeException('should 429'); }
    catch (\FluxFiles\ApiException $e) { assertEqual(429, $e->getHttpCode()); }
    $_ENV['FLUXFILES_STORAGE_BACKEND'] = 'db';
    assertTrue(RateLimiterFactory::make(5, 5, 60, $conn) instanceof RateLimiterDbStorage);
});

foreach (['FLUXFILES_STORAGE_BACKEND' => $savedBackend, 'FLUXFILES_STORAGE_PATH' => $savedPath] as $k => $v) {
    if ($v === null) { unset($_ENV[$k]); } else { $_ENV[$k] = $v; }
}

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
