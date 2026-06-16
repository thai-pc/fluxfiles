<?php

/**
 * Unit tests for FluxFiles\SsrfGuard — the SSRF denylist + URL validation used by
 * URL import (and the BYOB endpoint check). Deterministic: every case uses a
 * literal / numeric-obfuscated IP or a static rule, so NO network is touched.
 *
 * Usage: php tests/unit/test-ssrf-guard.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\SsrfGuard;
use FluxFiles\ApiException;

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertFalse($c, string $m = ''): void { if ($c) throw new \RuntimeException($m ?: 'expected false'); }
/** Assert assertSafeUrl throws with a given error code. */
function assertBlocked(string $url, ?string $code = null, ?array $allowlist = null): void
{
    try {
        SsrfGuard::assertSafeUrl($url, $allowlist);
        throw new \RuntimeException("expected block for {$url}");
    } catch (ApiException $e) {
        if ($code !== null && $e->getErrorCode() !== $code) {
            throw new \RuntimeException("for {$url}: expected code {$code}, got " . $e->getErrorCode());
        }
        assertTrue($e->getHttpCode() === 422, "for {$url}: expected 422");
    }
}

echo "\n{$cyan}══ SsrfGuard ══{$reset}\n";

echo "{$yellow}► isPublicIp — genuinely public addresses pass{$reset}\n";
foreach (['8.8.8.8', '1.1.1.1', '93.184.216.34', '140.82.121.3',
          '2606:4700:4700::1111', '2001:4860:4860::8888'] as $ip) {
    test("public IP {$ip} passes", function () use ($ip) { assertTrue(SsrfGuard::isPublicIp($ip), $ip); });
}

echo "{$yellow}► isPublicIp — private / reserved / metadata blocked{$reset}\n";
$blockedIps = [
    'loopback v4'      => '127.0.0.1',
    'loopback edge'    => '127.255.255.254',
    'private 10/8'     => '10.1.2.3',
    'private 172.16/12'=> '172.16.5.9',
    'private 192.168'  => '192.168.1.100',
    'link-local'       => '169.254.13.37',
    'cloud metadata'   => '169.254.169.254',
    'CGNAT 100.64/10'  => '100.64.0.1',
    'this network'     => '0.0.0.0',
    'IPv6 loopback'    => '::1',
    'IPv6 ULA fd00'    => 'fd00::1',
    'IPv6 link-local'  => 'fe80::1',
    'mapped v4 loopback' => '::ffff:127.0.0.1',
    'mapped v4 metadata' => '::ffff:169.254.169.254',
];
foreach ($blockedIps as $label => $ip) {
    test("blocked: {$label} ({$ip})", function () use ($ip) { assertFalse(SsrfGuard::isPublicIp($ip), $ip); });
}

test('isPublicIp: a non-IP string is not public', function () {
    assertFalse(SsrfGuard::isPublicIp('not-an-ip'));
    assertFalse(SsrfGuard::isPublicIp(''));
});

echo "{$yellow}► assertSafeUrl — scheme / format rejections{$reset}\n";
foreach (['file:///etc/passwd', 'ftp://example.com/x', 'gopher://evil/', 'dict://x:11/', 'jar:http://x!/'] as $u) {
    test("scheme rejected: {$u}", function () use ($u) { assertBlocked($u); });
}
test('malformed URL rejected', function () { assertBlocked('http://', null); assertBlocked('::::', null); });
test('credentials in URL rejected', function () { assertBlocked('http://user:pass@example.com/x', 'url_invalid'); });

echo "{$yellow}► assertSafeUrl — SSRF targets (literal + obfuscated, no network){$reset}\n";
$ssrf = [
    'loopback'         => 'http://127.0.0.1/',
    'loopback :port'   => 'http://127.0.0.1:9000/admin',
    'private 10.x'     => 'http://10.0.0.5/',
    'private 192.168'  => 'https://192.168.0.1/',
    'metadata IP'      => 'http://169.254.169.254/latest/meta-data/',
    'CGNAT'            => 'http://100.64.1.1/',
    'IPv6 loopback'    => 'http://[::1]/',
    'IPv6 ULA'         => 'http://[fd00::1]/',
    'decimal IP'       => 'http://2130706433/',       // = 127.0.0.1
    'hex IP'           => 'http://0x7f000001/',        // = 127.0.0.1
    'localhost name'   => 'http://localhost/',
    'sub.localhost'    => 'http://api.localhost/',
    '.local mDNS'      => 'http://printer.local/',
    '.internal'        => 'http://db.internal/',
];
foreach ($ssrf as $label => $u) {
    test("SSRF blocked: {$label}", function () use ($u) { assertBlocked($u, 'ssrf_blocked'); });
}

echo "{$yellow}► host allowlist (glob){$reset}\n";
test('hostMatchesAllowlist: exact + wildcard + bare', function () {
    assertTrue(SsrfGuard::hostMatchesAllowlist('unsplash.com', ['unsplash.com']));
    assertTrue(SsrfGuard::hostMatchesAllowlist('images.unsplash.com', ['*.unsplash.com']));
    assertTrue(SsrfGuard::hostMatchesAllowlist('unsplash.com', ['*.unsplash.com']), 'wildcard covers bare');
    assertFalse(SsrfGuard::hostMatchesAllowlist('evil.com', ['*.unsplash.com', 'pexels.com']));
    assertFalse(SsrfGuard::hostMatchesAllowlist('notunsplash.com', ['*.unsplash.com']), 'no suffix-confusion');
    assertFalse(SsrfGuard::hostMatchesAllowlist('unsplash.com.evil.com', ['*.unsplash.com']));
});
test('assertSafeUrl: host not in allowlist is rejected before any DNS', function () {
    // Reject happens before resolution → deterministic, no network.
    assertBlocked('http://evil.example/x', 'host_not_allowed', ['*.unsplash.com']);
});

echo "{$cyan}──────────────{$reset}\n";
echo "  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
