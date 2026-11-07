<?php

/**
 * HttpTransportInterface: the default CurlTransport against a real local socket, and
 * injection into AiTagger, BucketDoctor and OidcDiscovery. UrlImporter is deliberately
 * NOT on the transport (DNS-pinned cURL), which the last check pins.
 *
 * Usage: php tests/integration/test-http-transport.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\AiTagger;
use FluxFiles\ApiException;
use FluxFiles\BucketDoctor;
use FluxFiles\CurlTransport;
use FluxFiles\DiskManager;
use FluxFiles\HttpTransportInterface;
use FluxFiles\OidcDiscovery;

// Any warning, notice or DEPRECATION (e.g. curl_close() on PHP 8.5) fails the run.
set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) { return false; }   // an @-silenced call stays silent
    throw new \ErrorException($str, 0, $no, $file, $line);
});

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }

/** Records every request and answers from a queue (or a default). */
final class FakeTransport implements HttpTransportInterface {
    public array $calls = [];
    public array $queue = [];
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array {
        $this->calls[] = compact('method', 'url', 'headers', 'body', 'timeout');
        return array_shift($this->queue) ?? ['status' => 200, 'headers' => [], 'body' => '{}'];
    }
}

// ── A real socket for CurlTransport ──────────────────────────────────────────
$docroot = sys_get_temp_dir() . '/ff-transport-' . uniqid();
mkdir($docroot);
file_put_contents($docroot . '/index.php', <<<'PHP'
<?php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/redirect') { header('Location: /echo', true, 302); exit; }
if ($p === '/chunked') { while (@ob_end_clean()) {} for ($i = 0; $i < 40; $i++) { echo str_repeat('x', 65536); flush(); } exit; }
if ($p === '/bigerr') { http_response_code(500); header('Content-Length: 3145728'); echo str_repeat('x', 3145728); exit; }
if ($p === '/bigredir') { header('Location: /echo', true, 302); header('Content-Length: 3145728'); echo str_repeat('x', 3145728); exit; }
if ($p === '/sized') { header('Content-Length: 3145728'); echo str_repeat('x', 3145728); exit; }
header('X-Probe: yes');
header('Content-Type: application/json');
http_response_code($p === '/teapot' ? 418 : 200);
echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'body' => file_get_contents('php://input'),
    'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null, 'x' => $_SERVER['HTTP_X_CUSTOM'] ?? null]);
PHP);
$sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
$port = (int) explode(':', (string) stream_socket_get_name($sock, false))[1];
fclose($sock);
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot, $docroot . '/index.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port, $e1, $e2, 0.1)) { break; } usleep(100000); }
register_shutdown_function(function () use ($srv) { proc_terminate($srv); });
$base = "http://127.0.0.1:{$port}";

echo "\n{$cyan}══ CurlTransport (default) ══{$reset}\n\n";

test('GET: status, lower-cased response headers and body', function () use ($base) {
    $r = (new CurlTransport())->request('GET', "{$base}/echo", [], null, 5);
    assertEqual(200, $r['status']);
    assertEqual('yes', $r['headers']['x-probe'] ?? null);
    assertEqual('GET', json_decode($r['body'], true)['method']);
});

test('POST with a body and headers in both accepted shapes', function () use ($base) {
    $r = (new CurlTransport())->request('POST', "{$base}/echo", ['Authorization: Bearer abc', 'X-Custom' => 'v1'], '{"a":1}', 5);
    $j = json_decode($r['body'], true);
    assertEqual('POST', $j['method']);
    assertEqual('{"a":1}', $j['body']);
    assertEqual('Bearer abc', $j['auth']);
    assertEqual('v1', $j['x']);
});

test('a 4xx is a normal response (status kept), not an exception', function () use ($base) {
    $r = (new CurlTransport())->request('GET', "{$base}/teapot", [], null, 5);
    assertEqual(418, $r['status']);
});

test('redirects are NOT followed', function () use ($base) {
    $r = (new CurlTransport())->request('GET', "{$base}/redirect", [], null, 5);
    assertEqual(302, $r['status']);
    assertEqual('/echo', $r['headers']['location'] ?? null);
});

test('a body past the cap (no Content-Length, streamed) fails with status 0 and a clear error', function () use ($base) {
    $r = (new CurlTransport(5, 1048576))->request('GET', "{$base}/chunked", [], null, 10);
    assertEqual(0, $r['status']);
    assertEqual('', $r['body']);
    assertTrue(str_contains($r['error'] ?? '', 'response too large'), 'error: ' . ($r['error'] ?? ''));
});

test('an oversized declared Content-Length is refused before the body is read', function () use ($base) {
    $r = (new CurlTransport(5, 1048576))->request('GET', "{$base}/sized", [], null, 10);
    assertEqual(0, $r['status']);
    assertTrue(str_contains($r['error'] ?? '', 'response too large'), 'error: ' . ($r['error'] ?? ''));
});

test('HEAD with a large Content-Length is not an oversize error', function () use ($base) {
    $r = (new CurlTransport(5, 1048576))->request('HEAD', "{$base}/sized", [], null, 10);
    assertEqual(200, $r['status']);
    assertEqual('3145728', $r['headers']['content-length'] ?? null);
    assertEqual('', $r['body']);
});

test('a non-2xx answer with a huge body keeps status + headers (body dropped), redirects stay visible', function () use ($base) {
    $r = (new CurlTransport(5, 1048576))->request('GET', "{$base}/bigerr", [], null, 10);
    assertEqual(500, $r['status']);
    assertEqual('', $r['body']);
    $r = (new CurlTransport(5, 1048576))->request('GET', "{$base}/bigredir", [], null, 10);
    assertEqual(302, $r['status']);
    assertEqual('/echo', $r['headers']['location'] ?? null);
});

test('a body under the cap passes; the default cap is 8 MB', function () use ($base) {
    $r = (new CurlTransport(5, 4 * 1048576))->request('GET', "{$base}/sized", [], null, 10);
    assertEqual(200, $r['status']);
    assertEqual(3145728, strlen($r['body']));
    assertEqual(8 * 1024 * 1024, CurlTransport::DEFAULT_MAX_BYTES);
});

test('OidcDiscovery::forgetJwks drops only that URI\'s cached JWKS', function () {
    $dir = sys_get_temp_dir() . '/ff-oidc-' . uniqid();
    $ft = new FakeTransport();
    $ft->queue = [
        ['status' => 200, 'headers' => [], 'body' => '{"keys":[{"kid":"a"}]}'],
        ['status' => 200, 'headers' => [], 'body' => '{"keys":[{"kid":"b"}]}'],
    ];
    $d = new OidcDiscovery($dir, $ft);
    assertEqual('a', $d->jwks('https://idp/jwks')['keys'][0]['kid']);
    assertEqual('a', $d->jwks('https://idp/jwks')['keys'][0]['kid'], 'cached');
    $d->forgetJwks('https://idp/jwks');
    assertEqual('b', $d->jwks('https://idp/jwks')['keys'][0]['kid'], 're-fetched after forget');
    $d->forgetJwks('https://never/cached'); // no-op, no warning
    $ft->queue = [['status' => 200, 'headers' => [], 'body' => '{"keys":[{"kid":"c"}]}']];
    $d->forgetJwks(); // no argument: every cached key set
    assertEqual('c', $d->jwks('https://idp/jwks')['keys'][0]['kid'], 're-fetched after forget-all');
});

test('OidcDiscovery requires https for discovery and jwks_uri (http only for loopback), also after forgetJwks', function () {
    $dir = sys_get_temp_dir() . '/ff-oidc-' . uniqid();
    $ft = new FakeTransport();
    $d = new OidcDiscovery($dir, $ft);
    foreach (['http://idp.example.com/jwks', 'ftp://idp/jwks', '//idp/jwks'] as $u) {
        try { $d->jwks($u); throw new \RuntimeException("accepted {$u}"); }
        catch (\FluxFiles\ApiException $e) { assertEqual('sso_idp_unreachable', $e->getErrorCode(), $u); }
    }
    try { $d->discover('http://idp.example.com'); throw new \RuntimeException('accepted http issuer'); }
    catch (\FluxFiles\ApiException $e) { assertEqual('sso_idp_unreachable', $e->getErrorCode()); }
    assertEqual([], $ft->calls, 'nothing was fetched');
    foreach (['http://localhost:9/jwks', 'http://127.0.0.1/jwks', 'http://[::1]/jwks', 'https://idp.example.com/jwks'] as $u) {
        $ft->queue = [['status' => 200, 'headers' => [], 'body' => '{"keys":[{"kid":"k"}]}']];
        $d->forgetJwks();
        assertEqual('k', $d->jwks($u)['keys'][0]['kid'], $u);
    }
    $d->forgetJwks();
    try { $d->jwks('http://evil.example/jwks'); throw new \RuntimeException('accepted after forget'); }
    catch (\FluxFiles\ApiException $e) { assertEqual('sso_idp_unreachable', $e->getErrorCode()); }
});

test('a network failure never throws: status 0, empty body, an error string', function () {
    $r = (new CurlTransport(1))->request('GET', 'http://127.0.0.1:1/', [], null, 2);
    assertEqual(0, $r['status']);
    assertEqual('', $r['body']);
    assertTrue(($r['error'] ?? '') !== '', 'error text');
});

test('non-http(s) schemes are refused (file://, gopher://)', function () {
    foreach (['file:///etc/hostname', 'gopher://127.0.0.1:1/'] as $u) {
        assertEqual(0, (new CurlTransport(1))->request('GET', $u, [], null, 2)['status'], $u);
    }
});

echo "\n{$cyan}══ Injection ══{$reset}\n\n";

test('AiTagger posts JSON through the injected transport and parses its response', function () {
    $t = new FakeTransport();
    $t->queue[] = ['status' => 200, 'headers' => [], 'body' => json_encode(['content' => [['type' => 'text', 'text' => '{"tags":["x"],"title":"T","alt_text":"A","caption":"C"}']]])];
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    $r = (new AiTagger('claude', 'sk-test', null, null, $t))->analyze($png, 'image/png');
    assertEqual(['x'], $r['tags']);
    assertEqual(1, count($t->calls));
    assertEqual('POST', $t->calls[0]['method']);
    assertTrue(str_starts_with($t->calls[0]['url'], 'https://api.anthropic.com/'), $t->calls[0]['url']);
    assertEqual(60, $t->calls[0]['timeout']);
    $sent = json_decode((string) $t->calls[0]['body'], true);
    assertTrue(is_array($sent) && isset($sent['messages']), 'JSON request body');
    assertTrue(in_array('x-api-key: sk-test', array_map('strtolower', array_map('strval', $t->calls[0]['headers'])), true) || (bool) array_filter($t->calls[0]['headers'], fn ($h) => stripos((string) $h, 'sk-test') !== false), 'key sent as a header');
});

test('AiTagger maps a transport failure / provider error to 502 without leaking the key', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    foreach ([['status' => 0, 'headers' => [], 'body' => '', 'error' => 'sk-secret timeout'], ['status' => 500, 'headers' => [], 'body' => '{"error":{"message":"boom"}}']] as $resp) {
        $t = new FakeTransport();
        $t->queue[] = $resp;
        try { (new AiTagger('claude', 'sk-secret', null, null, $t))->analyze($png, 'image/png'); throw new \RuntimeException('no throw'); }
        catch (ApiException $e) { assertEqual(502, $e->getHttpCode()); assertTrue(!str_contains($e->getMessage(), 'sk-secret')); }
    }
});

test('AiTagger without a transport still builds (default cURL) — the 4-arg constructor is unchanged', function () {
    assertTrue(new AiTagger('claude', 'k') instanceof AiTagger);
    assertTrue(new AiTagger('openai', 'k', 'm', 'http://x') instanceof AiTagger);
});

test('BucketDoctor: an UNPINNED fetch goes through the injected transport', function () {
    $t = new FakeTransport();
    $t->queue[] = ['status' => 200, 'headers' => [], 'body' => 'abc'];
    $doc = new BucketDoctor(new DiskManager(['local' => ['driver' => 'local', 'root' => sys_get_temp_dir(), 'url' => '/s']]), $t);
    $m = new ReflectionMethod($doc, 'httpGet');
    assertEqual([200, 'abc'], $m->invoke($doc, 'https://bucket.example/obj?sig=1', null));
    assertEqual('GET', $t->calls[0]['method']);
    assertEqual('https://bucket.example/obj?sig=1', $t->calls[0]['url']);
});

test('BucketDoctor: a PINNED (BYOB) fetch keeps its own DNS-pinned cURL — the transport is not used', function () use ($port) {
    $t = new FakeTransport();
    $doc = new BucketDoctor(new DiskManager(['local' => ['driver' => 'local', 'root' => sys_get_temp_dir(), 'url' => '/s']]), $t);
    $m = new ReflectionMethod($doc, 'httpGet');
    try { $m->invoke($doc, "http://127.0.0.1:{$port}/echo", '127.0.0.1'); } catch (\Throwable $e) { /* loopback is rejected post-connect: that is the guard working */ }
    assertEqual([], $t->calls, 'pinned fetches never reach the transport');
});

test('OidcDiscovery: discovery + JWKS via the injected transport, with the Accept header, then cached', function () {
    $dir = sys_get_temp_dir() . '/ff-oidc-' . uniqid();
    mkdir($dir);
    $t = new FakeTransport();
    $t->queue[] = ['status' => 200, 'headers' => [], 'body' => json_encode([
        'authorization_endpoint' => 'https://idp/auth', 'token_endpoint' => 'https://idp/token',
        'jwks_uri' => 'https://idp/jwks', 'issuer' => 'https://idp'])];
    $t->queue[] = ['status' => 200, 'headers' => [], 'body' => json_encode(['keys' => [['kty' => 'RSA']]])];
    $o = new OidcDiscovery($dir, $t);
    $d = $o->discover('https://idp/');
    assertEqual('https://idp/token', $d['token_endpoint']);
    assertEqual('https://idp/.well-known/openid-configuration', $t->calls[0]['url']);
    assertTrue(in_array('Accept: application/json', $t->calls[0]['headers'], true));
    assertEqual(10, $t->calls[0]['timeout']);
    assertEqual('RSA', $o->jwks('https://idp/jwks')['keys'][0]['kty']);
    $o->discover('https://idp/');
    assertEqual(2, count($t->calls), 'second discover() served from the cache');
});

test('OidcDiscovery: unreachable / non-2xx / non-JSON → 502 sso_idp_unreachable', function () {
    foreach ([['status' => 0, 'headers' => [], 'body' => '', 'error' => 'x'], ['status' => 503, 'headers' => [], 'body' => 'down'], ['status' => 200, 'headers' => [], 'body' => '<html>']] as $resp) {
        $dir = sys_get_temp_dir() . '/ff-oidc-' . uniqid();
        mkdir($dir);
        $t = new FakeTransport();
        $t->queue[] = $resp;
        try { (new OidcDiscovery($dir, $t))->discover('https://idp'); throw new \RuntimeException('no throw'); }
        catch (ApiException $e) { assertEqual('sso_idp_unreachable', $e->getErrorCode()); assertEqual(502, $e->getHttpCode()); }
    }
});

test('UrlImporter stays on its own DNS-pinned cURL (no transport seam, by design)', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../api/UrlImporter.php');
    assertTrue(!str_contains($src, 'HttpTransportInterface'), 'must not use the transport');
    assertTrue(str_contains($src, 'curl_init') && str_contains($src, 'assertConnectedIpSafe'), 'still owns the cURL handle + post-connect IP check');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
