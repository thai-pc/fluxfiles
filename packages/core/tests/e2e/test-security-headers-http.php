<?php

declare(strict_types=1);

// Self-booting: every JSON API answer carries `X-Content-Type-Options: nosniff`, and
// the UI page defaults to `frame-ancestors 'self'` when it carries a demo token
// (__FM_BOOT__) / SSO login and no FLUXFILES_FRAME_ANCESTORS / ALLOWED_ORIGINS is set,
// while a plain embed keeps sending no frame-ancestors header.

if (!extension_loaded('curl')) { echo "SKIP: curl required\n"; exit(0); }
$core = realpath(__DIR__ . '/../..');
$passed = 0; $failed = 0;
function check(bool $ok, string $m): void { if (!$ok) throw new RuntimeException($m); }
function test(string $name, callable $fn): void {
    global $passed, $failed;
    try { $fn(); $passed++; echo "PASS $name\n"; }
    catch (Throwable $e) { $failed++; echo "FAIL $name: {$e->getMessage()}\n"; }
}
function boot(string $core, array $extra): array {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $addr = stream_socket_get_name($probe, false); fclose($probe);
    $env = array_merge(getenv(), [
        'FLUXFILES_SECRET' => str_repeat('sec-headers-http-', 3),
        'FLUXFILES_DEMO' => '', 'FLUXFILES_FRAME_ANCESTORS' => '', 'FLUXFILES_ALLOWED_ORIGINS' => '',
        'FLUXFILES_SSO_ENABLED' => '',
    ], $extra);
    $proc = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', $addr, 'router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $core, $env);
    if (!is_resource($proc)) throw new RuntimeException('Could not start test server');
    for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', (int) substr((string) strrchr($addr, ':'), 1))) break; usleep(100000); }
    return [$proc, 'http://' . $addr];
}
function get(string $url): array {
    $ch = curl_init($url);
    $hdrs = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$hdrs) {
            if (str_contains($l, ':')) { [$k, $v] = explode(':', $l, 2); $hdrs[strtolower(trim($k))] = trim($v); }
            return strlen($l);
        }]);
    $body = (string) curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $hdrs, $body];
}

[$p1, $b1] = boot($core, []);
try {
    test('JSON API error response carries nosniff', function () use ($b1) {
        [$s, $h, $body] = get("$b1/api/fm/list?disk=local&path=");
        check($s === 401 || $s === 403, "unauthenticated list should be refused, got $s");
        check(str_contains($h['content-type'] ?? '', 'application/json'), 'not JSON');
        check(($h['x-content-type-options'] ?? '') === 'nosniff', 'missing nosniff: ' . json_encode($h));
    });
    test('plain embed: no frame-ancestors header without config (README-documented behaviour)', function () use ($b1) {
        [$s, $h] = get("$b1/public/index.html");
        check($s === 200, "load $s");
        check(!isset($h['content-security-policy']), 'unexpected CSP: ' . ($h['content-security-policy'] ?? ''));
    });
} finally { proc_terminate($p1); proc_close($p1); }

[$p2, $b2] = boot($core, ['FLUXFILES_DEMO' => '1']);
try {
    test("demo mode: frame-ancestors 'self' when nothing is configured", function () use ($b2) {
        [$s, $h] = get("$b2/public/index.html");
        check($s === 200, "load $s");
        check(($h['content-security-policy'] ?? '') === "frame-ancestors 'self'", 'CSP: ' . ($h['content-security-policy'] ?? '(none)'));
    });
} finally { proc_terminate($p2); proc_close($p2); }

[$p3, $b3] = boot($core, ['FLUXFILES_DEMO' => '1', 'FLUXFILES_FRAME_ANCESTORS' => "'self' https://site.example"]);
try {
    test('demo mode: an explicit FLUXFILES_FRAME_ANCESTORS wins', function () use ($b3) {
        [, $h] = get("$b3/public/index.html");
        check(($h['content-security-policy'] ?? '') === "frame-ancestors 'self' https://site.example", 'CSP: ' . ($h['content-security-policy'] ?? '(none)'));
    });
} finally { proc_terminate($p3); proc_close($p3); }

echo "\nTotal: " . ($passed + $failed) . "  Passed: $passed  Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
