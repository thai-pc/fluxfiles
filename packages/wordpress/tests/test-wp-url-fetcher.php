<?php

/**
 * FluxFilesUrlFetcher (URL import over wp_remote_get) against a local fixture server.
 *
 * wp_remote_get() is emulated the way WordPress' cURL transport behaves: it builds a real
 * cURL handle, fires the `http_api_curl` action with it, runs it and streams the body to
 * `filename`. That lets the test prove the SSRF guarantees hold on the real wire:
 *   - the pin really applies (the fixture host name does not resolve in DNS),
 *   - a redirect hop is re-validated through SsrfGuard,
 *   - a private connected address is refused,
 *   - a non-cURL transport (hook never fires / use_curl_transport=false) fails closed,
 *   - the hook is scoped to one request and removed afterwards,
 *   - WordPress' kses rewrite of the URL (upper-case scheme), Requests' redirect re-entry (the
 *     hook firing twice), a hook URL for a different host and a rejected cURL option each end
 *     in NO request (curl_exec never runs).
 *
 * Usage: php packages/wordpress/tests/test-wp-url-fetcher.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$core = __DIR__ . '/../../core';
require_once $core . '/vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\SsrfGuard;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\UrlImporter;

// ── WordPress stubs ──────────────────────────────────────────────────────────
$GLOBALS['WP_ACTIONS'] = [];
$GLOBALS['WP_FILTER_CURL'] = true;
$GLOBALS['WP_FIRE_HOOK'] = true;
$GLOBALS['WP_LAST_REQUEST'] = null;
$GLOBALS['WP_EXEC_COUNT'] = 0;
$GLOBALS['WP_HOOK_URL'] = null;   // ?callable(string): string -- the URL the hook is told about
$GLOBALS['WP_REENTER'] = null;    // ?string -- a second http_api_curl firing (redirect hop) with this URL

class WP_Error { public function __construct(public string $code = '', public string $message = '') {} }
function is_wp_error($x) { return $x instanceof WP_Error; }
function add_action($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['WP_ACTIONS'][$tag][] = $cb; return true; }
function remove_action($tag, $cb, $prio = 10) {
    $GLOBALS['WP_ACTIONS'][$tag] = array_values(array_filter($GLOBALS['WP_ACTIONS'][$tag] ?? [], fn ($c) => $c !== $cb));
    return true;
}
function apply_filters($tag, $value, ...$a) { return $tag === 'use_curl_transport' ? $GLOBALS['WP_FILTER_CURL'] : $value; }
function wp_allowed_protocols() { return ['http', 'https']; }
/** Like WP's kses: strips NULs and lower-cases an allowed scheme; '' for a disallowed one. */
function wp_kses_bad_protocol($s, $allowed)
{
    $s = str_replace("\0", '', (string) $s);
    if (!preg_match('~^([A-Za-z][A-Za-z0-9+.-]*):(.*)$~s', $s, $m)) { return $s; }
    return in_array(strtolower($m[1]), $allowed, true) ? strtolower($m[1]) . ':' . $m[2] : '';
}
function wp_remote_retrieve_response_code($r) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_header($r, $h) { return $r['headers'][strtolower($h)] ?? ''; }
function wp_remote_get($url, $args = [])
{
    $GLOBALS['WP_LAST_REQUEST'] = [$url, $args];
    $url = wp_kses_bad_protocol($url, wp_allowed_protocols());   // WP_Http::request() rewrites the URL first
    $fh = fopen($args['filename'], 'w');
    $headers = [];
    $limit = (int) ($args['limit_response_size'] ?? 0);
    $got = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_TIMEOUT        => (int) $args['timeout'],
        CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$headers) {
            if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $headers[strtolower(trim($k))] = trim($v); }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => static function ($c, $data) use ($fh, $limit, &$got) {
            if ($limit > 0 && $got + strlen($data) > $limit) { $data = substr($data, 0, max(0, $limit - $got)); }
            $got += strlen($data);
            fwrite($fh, $data);
            return strlen($data) ?: 1;
        },
    ]);
    if ($GLOBALS['WP_FIRE_HOOK']) {
        $hookUrl = $GLOBALS['WP_HOOK_URL'] ? ($GLOBALS['WP_HOOK_URL'])($url) : $url;
        foreach ($GLOBALS['WP_ACTIONS']['http_api_curl'] ?? [] as $cb) { $cb($ch, $args, $hookUrl); }
        if ($GLOBALS['WP_REENTER'] !== null) {
            foreach ($GLOBALS['WP_ACTIONS']['http_api_curl'] ?? [] as $cb) { $cb($ch, $args, $GLOBALS['WP_REENTER']); }
        }
    }
    $GLOBALS['WP_EXEC_COUNT']++;
    $ok = curl_exec($ch);
    fclose($fh);
    if ($ok === false) { return new WP_Error('http_request_failed', curl_error($ch)); }
    return ['response' => ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE)], 'headers' => $headers];
}

require_once __DIR__ . '/../includes/FluxFilesUrlFetcher.php';

$green = "\033[32m"; $red = "\033[31m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertCode(callable $fn, string $code): void
{
    try { $fn(); throw new \RuntimeException("expected {$code}, no exception"); }
    catch (ApiException $e) { assertEqual($code, $e->getErrorCode(), 'error code'); }
}

// ── fixture server ───────────────────────────────────────────────────────────
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(stream_socket_get_name($probe, false), strrpos(stream_socket_get_name($probe, false), ':') + 1);
fclose($probe);
$fixtures = $core . '/tests/integration/fixtures';
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $fixtures . '/import-fixture.php'],
    [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, $fixtures);
for ($i = 0; $i < 50; $i++) {
    $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($c) { fclose($c); break; }
    usleep(100000);
}
SsrfGuard::$allowTestHosts = ["127.0.0.1:{$port}", "pin-fixture.invalid:{$port}"];

$root = sys_get_temp_dir() . '/fluxfiles-wpfetch-' . uniqid();
@mkdir($root, 0777, true);
$dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
$meta = new StorageMetadataHandler($dm);
function importer(int $maxMb = 0): UrlImporter
{
    global $dm, $meta;
    $claims = new Claims('u1', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, false);
    $claims->allowUrlImport = true;
    if ($maxMb > 0) { $claims->maxImportMb = $maxMb; }
    return new UrlImporter($claims, new FileManager($dm, $claims, $meta), new FluxFilesUrlFetcher());
}
$fetch = static function (string $url, array $pin, ?array $allowed, int $max = 1048576) {
    $tmp = tempnam(sys_get_temp_dir(), 'ffw_');
    try { return [(new FluxFilesUrlFetcher())->fetch($url, $pin, $allowed, $tmp, $max, 10), (string) file_get_contents($tmp)]; }
    finally { @unlink($tmp); }
};
$base = "http://127.0.0.1:{$port}";

echo "\nFluxFilesUrlFetcher (wp_remote_get)\n";

test('the pin is applied: a host that does not resolve in DNS is reached through the vetted IP', function () use ($fetch, $port) {
    [$res, $body] = $fetch("http://pin-fixture.invalid:{$port}/png", ['127.0.0.1'], ['127.0.0.1']);
    assertEqual(200, $res['status']);
    assertTrue(str_starts_with($body, "\x89PNG"), 'body streamed to the temp file');
    assertTrue(str_contains($res['disposition'], 'sunset.png'), 'Content-Disposition returned');
});

test('request args: no redirects, streamed to a file, size-limited, SsrfGuard (not WP) is the authority', function () use ($fetch, $port) {
    $fetch("http://pin-fixture.invalid:{$port}/png", ['127.0.0.1'], ['127.0.0.1']);
    $args = $GLOBALS['WP_LAST_REQUEST'][1];
    assertEqual(0, $args['redirection']);
    assertTrue($args['stream'] === true && $args['filename'] !== '');
    assertEqual(1048577, $args['limit_response_size']);
});

test('SECURITY: the connected address must be public unless it is the allow-listed pin', function () use ($fetch, $port) {
    assertCode(fn () => $fetch("http://pin-fixture.invalid:{$port}/png", ['127.0.0.1'], null), 'ssrf_blocked');
});

test('the http_api_curl hook is removed after the request (it cannot leak onto other requests)', function () use ($fetch, $port) {
    $fetch("http://pin-fixture.invalid:{$port}/png", ['127.0.0.1'], ['127.0.0.1']);
    assertEqual([], $GLOBALS['WP_ACTIONS']['http_api_curl'] ?? []);
    try { $fetch("http://pin-fixture.invalid:{$port}/png", ['127.0.0.1'], null); } catch (ApiException $e) {}
    assertEqual([], $GLOBALS['WP_ACTIONS']['http_api_curl'] ?? [], 'also removed when the check throws');
});

test('SECURITY: a transport that cannot be pinned fails closed (hook never fires)', function () use ($fetch, $port) {
    $GLOBALS['WP_FIRE_HOOK'] = false;
    try { assertCode(fn () => $fetch("http://127.0.0.1:{$port}/png", ['127.0.0.1'], ['127.0.0.1']), 'fetch_failed'); }
    finally { $GLOBALS['WP_FIRE_HOOK'] = true; }
});

test('SECURITY: use_curl_transport=false is refused before any request is made', function () use ($fetch, $port) {
    $GLOBALS['WP_FILTER_CURL'] = false;
    $GLOBALS['WP_LAST_REQUEST'] = null;
    try {
        assertCode(fn () => $fetch("http://127.0.0.1:{$port}/png", ['127.0.0.1'], ['127.0.0.1']), 'fetch_failed');
        assertEqual(null, $GLOBALS['WP_LAST_REQUEST'], 'no request issued');
    } finally { $GLOBALS['WP_FILTER_CURL'] = true; }
});

test('a Content-Length over the cap aborts: size_exceeded', function () use ($fetch, $port) {
    assertCode(fn () => $fetch("http://127.0.0.1:{$port}/big", ['127.0.0.1'], ['127.0.0.1'], 1048576), 'size_exceeded');
});

test('the redirect Location is handed back, not followed', function () use ($fetch, $port) {
    [$res] = $fetch("http://127.0.0.1:{$port}/redirect-ok", ['127.0.0.1'], ['127.0.0.1']);
    assertEqual(302, $res['status']);
    assertEqual("http://127.0.0.1:{$port}/png", $res['redirect_url']);
});

test('an upper-case scheme is normalised, so the hook still matches and pins', function () use ($fetch, $port) {
    [$res] = $fetch("HTTP://pin-fixture.invalid:{$port}/png", ['127.0.0.1'], ['127.0.0.1']);
    assertEqual(200, $res['status']);
    assertEqual("http://pin-fixture.invalid:{$port}/png", $GLOBALS['WP_LAST_REQUEST'][0]);
});

test('hook URL matching ignores host case', function () use ($fetch, $port) {
    $GLOBALS['WP_HOOK_URL'] = static fn (string $u): string => str_replace('pin-fixture.invalid', 'PIN-Fixture.INVALID', $u);
    try { [$res] = $fetch("http://pin-fixture.invalid:{$port}/png", ['127.0.0.1'], ['127.0.0.1']); }
    finally { $GLOBALS['WP_HOOK_URL'] = null; }
    assertEqual(200, $res['status']);
});

/** Run a fetch that must be refused with $code before curl_exec ever runs. */
$noRequest = static function (callable $fn, string $code) {
    $before = $GLOBALS['WP_EXEC_COUNT'];
    assertCode($fn, $code);
    assertEqual($before, $GLOBALS['WP_EXEC_COUNT'], 'curl_exec must not have run (no socket opened)');
    assertEqual([], $GLOBALS['WP_ACTIONS']['http_api_curl'] ?? [], 'hook removed');
};

test('SECURITY: the hook told about a different host throws before curl_exec', function () use ($fetch, $port, $noRequest) {
    $GLOBALS['WP_HOOK_URL'] = static fn (string $u): string => "http://rebind.example:{$GLOBALS['port_']}/png";
    $GLOBALS['port_'] = $port;
    try { $noRequest(fn () => $fetch("http://pin-fixture.invalid:{$port}/png", ['127.0.0.1'], ['127.0.0.1']), 'ssrf_blocked'); }
    finally { $GLOBALS['WP_HOOK_URL'] = null; }
});

test('SECURITY: a different port throws before curl_exec', function () use ($fetch, $port, $noRequest) {
    $GLOBALS['WP_HOOK_URL'] = static fn (string $u): string => str_replace(":{$GLOBALS['port_']}", ':' . ($GLOBALS['port_'] + 1), $u);
    $GLOBALS['port_'] = $port;
    try { $noRequest(fn () => $fetch("http://pin-fixture.invalid:{$port}/png", ['127.0.0.1'], ['127.0.0.1']), 'ssrf_blocked'); }
    finally { $GLOBALS['WP_HOOK_URL'] = null; }
});

test('SECURITY: a second http_api_curl firing (redirect re-entry) throws before curl_exec', function () use ($fetch, $port, $noRequest) {
    $GLOBALS['WP_REENTER'] = "http://pin-fixture.invalid:{$port}/png";   // even for the same URL: we asked for redirection => 0
    try { $noRequest(fn () => $fetch("http://pin-fixture.invalid:{$port}/png", ['127.0.0.1'], ['127.0.0.1']), 'ssrf_blocked'); }
    finally { $GLOBALS['WP_REENTER'] = null; }
});

test('SECURITY: a URL kses would rewrite (NUL byte) is refused before any request', function () use ($fetch, $port) {
    $GLOBALS['WP_LAST_REQUEST'] = null;
    assertCode(fn () => $fetch("http://pin-fixture.invalid:{$port}/p\0ng", ['127.0.0.1'], ['127.0.0.1']), 'fetch_failed');
    assertEqual(null, $GLOBALS['WP_LAST_REQUEST'], 'no request issued');
});

test('SECURITY: a rejected cURL option (pin not applied) throws before curl_exec', function () use ($fetch, $port, $noRequest) {
    // A negative CURLOPT_MAXFILESIZE makes curl_setopt_array() return false.
    $noRequest(fn () => $fetch("http://pin-fixture.invalid:{$port}/png", ['127.0.0.1'], ['127.0.0.1'], -5), 'ssrf_blocked');
});

echo "UrlImporter + FluxFilesUrlFetcher\n";

test('happy path through the importer', function () use ($base, $root) {
    $res = importer()->import('local', "{$base}/png", ['path' => 'in']);
    assertEqual('in/sunset.png', (string) $res['key']);
    assertTrue(is_file($root . '/' . $res['key']));
});

test('a 302 to an allowed hop is followed', function () use ($base) {
    assertEqual('r/sunset.png', (string) importer()->import('local', "{$base}/redirect-ok", ['path' => 'r'])['key']);
});

test('SECURITY: a redirect to the metadata IP is re-validated and blocked', function () use ($base) {
    assertCode(fn () => importer()->import('local', "{$base}/redirect-private"), 'ssrf_blocked');
});

test('redirect loop is capped; 404 -> fetch_failed; 403 -> auth_required', function () use ($base) {
    assertCode(fn () => importer()->import('local', "{$base}/loop"), 'redirect_loop');
    assertCode(fn () => importer()->import('local', "{$base}/notfound"), 'fetch_failed');
    assertCode(fn () => importer()->import('local', "{$base}/forbidden"), 'auth_required');
});

test('a body larger than max_import_mb -> size_exceeded', function () use ($base) {
    assertCode(fn () => importer(1)->import('local', "{$base}/big"), 'size_exceeded');
});

test('SECURITY: a loopback target that is not allow-listed is refused before connecting', function () use ($port) {
    SsrfGuard::$allowTestHosts = [];
    try { assertCode(fn () => importer()->import('local', "http://127.0.0.1:{$port}/png"), 'ssrf_blocked'); }
    finally { SsrfGuard::$allowTestHosts = ["127.0.0.1:{$port}", "pin-fixture.invalid:{$port}"]; }
});

SsrfGuard::$allowTestHosts = [];
proc_terminate($proc);
proc_close($proc);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($root);

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
