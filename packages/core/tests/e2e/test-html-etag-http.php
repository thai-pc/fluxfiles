<?php

declare(strict_types=1);

// Self-booting: the standalone UI HTML carries an ETag and answers 304 to
// If-None-Match; demo mode (per-visitor __FM_BOOT__ token) must never ETag.

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
    $env = array_merge(getenv(), ['FLUXFILES_SECRET' => str_repeat('etag-http-secret-', 3)], $extra);
    $proc = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', $addr, 'router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $core, $env);
    if (!is_resource($proc)) throw new RuntimeException('Could not start test server');
    for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', (int) substr((string) strrchr($addr, ':'), 1))) break; usleep(100000); }
    return [$proc, 'http://' . $addr];
}
function get(string $url, array $headers = []): array {
    $ch = curl_init($url);
    $hdrs = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$hdrs) {
            if (str_contains($l, ':')) { [$k, $v] = explode(':', $l, 2); $hdrs[strtolower(trim($k))] = trim($v); }
            return strlen($l);
        }]);
    $body = (string) curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $hdrs, $body];
}

[$p1, $b1] = boot($core, ['FLUXFILES_DEMO' => '']);
try {
    test('ETag + 304 on revalidation', function () use ($b1) {
        [$s, $h, $body] = get("$b1/public/index.html");
        check($s === 200 && $body !== '', "first load $s");
        check(isset($h['etag']), 'no ETag');
        check(str_contains($h['cache-control'] ?? '', 'no-cache'), 'no-cache dropped');
        [$s2, , $body2] = get("$b1/public/index.html", ['If-None-Match: ' . $h['etag']]);
        check($s2 === 304 && $body2 === '', "expected 304, got $s2");
        [$s3, , $body3] = get("$b1/public/index.html", ['If-None-Match: "stale"']);
        check($s3 === 200 && $body3 === $body, "stale etag must return full body ($s3)");
    });
} finally { proc_terminate($p1); proc_close($p1); }

[$p2, $b2] = boot($core, ['FLUXFILES_DEMO' => '1']);
try {
    test('demo mode never sends an ETag', function () use ($b2) {
        [$s, $h] = get("$b2/public/index.html");
        check($s === 200, "demo load $s");
        check(!isset($h['etag']), 'ETag present in demo mode');
        [$s2] = get("$b2/public/index.html", ['If-None-Match: "anything"', 'If-None-Match: *']);
        check($s2 === 200, "demo must not 304, got $s2");
    });
} finally { proc_terminate($p2); proc_close($p2); }

echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
