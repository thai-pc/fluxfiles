<?php

/**
 * BucketDoctor's presign-check fetch (httpGet) — SSRF hardening regression test.
 *
 * Before the fix, the presign check built a presigned URL via a properly
 * SSRF-pinned S3Client, then fetched it with a bare curl_init($url) that had
 * NO SSRF protection at all — no CURLOPT_RESOLVE pin reuse, no post-connect
 * SsrfGuard check. A BYOB tenant with `write` on their own disk (the only gate
 * on GET /api/fm/disk/doctor) could re-point their endpoint's DNS at an
 * internal host/IP between disk registration and this call, turning the
 * presign check into a blind SSRF probe that reflects status/body-match info
 * back to them.
 *
 * The fix threads the disk's already-SSRF-validated $config['_pinned_ip'] (the
 * same one DiskManager::build() pins the S3Client's own curl handle to — see
 * its CURLOPT_RESOLVE comment) into httpGet(), and enforces
 * SsrfGuard::assertConnectedIpSafe() on it as a post-connect backstop.
 *
 * Uses a plain local fixture HTTP server + reflection to call the private
 * httpGet() directly — this is the exact vulnerable code path, without needing
 * a full S3-compatible fixture. Mirrors the fixture-server + $allowTestHosts
 * pattern from tests/integration/test-url-import-fetch.php.
 *
 * Usage: php tests/integration/test-bucket-doctor-ssrf.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\BucketDoctor;
use FluxFiles\DiskManager;
use FluxFiles\SsrfGuard;

$green = "\033[32m"; $red = "\033[31m"; $yellow = "\033[33m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'Expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }

// ── Pick a free port, start a plain fixture HTTP server (httpGet() doesn't
//    care what it fetches, so no S3-compatible fixture is needed) ────────────
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$addr = stream_socket_get_name($probe, false);
$port = (int) substr($addr, strrpos($addr, ':') + 1);
fclose($probe);

$docroot = sys_get_temp_dir() . '/fluxfiles-doctor-ssrf-' . uniqid();
@mkdir($docroot, 0777, true);
file_put_contents($docroot . '/index.php', "<?php http_response_code(200); echo 'fluxfiles-doctor-probe-body';");

$proc = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", $docroot . '/index.php'],
    [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
    $pipes
);
if (!is_resource($proc)) {
    fwrite(STDERR, "Could not start fixture server\n");
    exit(1);
}

// Wait for it to accept connections.
for ($i = 0; $i < 50; $i++) {
    $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($c) { fclose($c); break; }
    usleep(100000);
}

$dm = new DiskManager(['local' => ['driver' => 'local', 'root' => sys_get_temp_dir(), 'url' => '/storage']]);
$doctor = new BucketDoctor($dm);
$httpGet = new \ReflectionMethod($doctor, 'httpGet');
$httpGet->setAccessible(true);

echo "\n{$yellow}══ BucketDoctor httpGet() — SSRF hardening ══{$reset}\n\n";

test('unpinned (non-BYOB) fetch still works — backward compatible', function () use ($httpGet, $doctor, $port) {
    [$code, $body] = $httpGet->invoke($doctor, "http://127.0.0.1:{$port}/", null);
    assertEqual(200, $code, 'HTTP 200');
    assertEqual('fluxfiles-doctor-probe-body', $body, 'body fetched');
});

test('SECURITY: a pinned IP that is private/loopback is rejected post-connect, never trusted blindly', function () use ($httpGet, $doctor, $port) {
    try {
        $httpGet->invoke($doctor, "http://127.0.0.1:{$port}/", '127.0.0.1');
        throw new \RuntimeException('expected ApiException(ssrf_blocked), none was thrown');
    } catch (ApiException $e) {
        assertEqual('ssrf_blocked', $e->getErrorCode(), 'blocked with the right error code');
    }
});

test('CURLOPT_RESOLVE actually pins the connection to the given IP (host does not resolve in DNS)', function () use ($httpGet, $doctor, $port) {
    // ".invalid" is reserved by RFC 2606 to never resolve. The ONLY way this
    // fetch can succeed is if the pinned IP forced the connection via
    // CURLOPT_RESOLVE — proving the pin is actually applied, not a no-op.
    // The post-connect SsrfGuard check (proven above) is bypassed here only via
    // the test-only $allowTestHosts hook, since 127.0.0.1 is a loopback address
    // and this test's job is to isolate the pin mechanism itself.
    SsrfGuard::$allowTestHosts = ["fluxfiles-doctor-test.invalid:{$port}"];
    try {
        [$code, $body] = $httpGet->invoke($doctor, "http://fluxfiles-doctor-test.invalid:{$port}/", '127.0.0.1');
        assertEqual(200, $code, 'HTTP 200 via the pinned IP');
        assertEqual('fluxfiles-doctor-probe-body', $body, 'body fetched via the pinned IP');
    } finally {
        SsrfGuard::$allowTestHosts = [];
    }
});

test('an unresolvable host with no pin fails closed (curl error → [0, ""], never a fetch to the wrong place)', function () use ($httpGet, $doctor, $port) {
    // No pin, and ".invalid" never resolves — curl_exec() fails and httpGet()
    // surfaces that as HTTP code 0 with an empty body (no exception path here),
    // proving there is no silent fallback that would fetch something else.
    [$code, $body] = $httpGet->invoke($doctor, "http://fluxfiles-doctor-test.invalid:{$port}/", null);
    assertEqual(0, $code, 'curl could not connect');
    assertEqual('', $body, 'no body');
});

// ── teardown ──────────────────────────────────────────────────────────────
SsrfGuard::$allowTestHosts = [];
proc_terminate($proc);
proc_close($proc);
@unlink($docroot . '/index.php');
@rmdir($docroot);

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
