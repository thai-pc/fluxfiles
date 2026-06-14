<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\RateLimiterFileStorage;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }

function limiter(int $read, int $write): RateLimiterFileStorage
{
    $path = sys_get_temp_dir() . '/fluxfiles-rl-' . uniqid() . '/rate_limit.json';
    return new RateLimiterFileStorage($path, $read, $write, 60);
}

echo "\n{$cyan}══ FluxFiles Rate Limiter ══{$reset}\n\n";

test('allows up to the limit, then rejects with 429 rate_limited', function () {
    $rl = limiter(3, 10);
    $rl->check('u1', 'read');
    $rl->check('u1', 'read');
    $rl->check('u1', 'read'); // 3 ok
    try {
        $rl->check('u1', 'read'); // 4th over the read limit
        throw new \RuntimeException('should have thrown on the 4th read');
    } catch (ApiException $e) {
        assertEqual('rate_limited', $e->getErrorCode(), 'error code');
        assertEqual(429, $e->getHttpCode(), 'http 429');
    }
});

test('read and write are counted in separate buckets', function () {
    $rl = limiter(1, 1);
    $rl->check('u1', 'read');   // read bucket full
    $rl->check('u1', 'write');  // write bucket independent → ok
    foreach (['read', 'write'] as $type) {
        try { $rl->check('u1', $type); throw new \RuntimeException("should throttle $type"); }
        catch (ApiException $e) { assertEqual('rate_limited', $e->getErrorCode(), "$type throttled"); }
    }
});

test('per-tenant limits are independent across users', function () {
    $rl = limiter(1, 5);
    $rl->check('userA', 'read'); // A's read bucket full
    $rl->check('userB', 'read'); // B is independent → ok
    try { $rl->check('userA', 'read'); throw new \RuntimeException('A should throttle'); }
    catch (ApiException $e) { assertEqual('rate_limited', $e->getErrorCode(), 'A throttled'); }
});

test('a higher per-tenant limit (the rate_read claim path) allows more requests', function () {
    // Simulates a "pro" tenant minted with rate_read=50 vs a default of 3.
    $pro = limiter(50, 10);
    for ($i = 0; $i < 50; $i++) { $pro->check('pro', 'read'); }
    try { $pro->check('pro', 'read'); throw new \RuntimeException('51st should throttle'); }
    catch (ApiException $e) { assertEqual('rate_limited', $e->getErrorCode(), 'throttled at the higher limit'); }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
