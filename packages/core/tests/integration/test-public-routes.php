<?php

/**
 * PublicRoutes: routing + the gate for the public Share/Intake routes.
 *
 *   gate order  secret 500 → not installed 501 → not licensed 402 / expired 402 → handler
 *   module      a PublicModuleInterface module serves the route and its pages
 *   legacy      a module that predates the interface is served by the in-core handlers
 *   page        the API base is injected as <meta name="fm-api-base">, never inline script
 *
 * The real share/intake packages are private, so fake modules are registered under the
 * real ids. Runs the dispatcher in-process with output buffering.
 *
 * Usage: php tests/integration/test-public-routes.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\ApiException;
use FluxFiles\LicenseCheckInterface;
use FluxFiles\ModuleInterface;
use FluxFiles\ModuleRegistry;
use FluxFiles\NoLicense;
use FluxFiles\PublicContext;
use FluxFiles\PublicModuleInterface;
use FluxFiles\PublicRoutes;

// Buffer everything so header() works like it does under a web server (the CLI would
// otherwise treat the PASS lines as "headers already sent").
ob_start();
$_ENV['FLUXFILES_SECRET'] = 'unit-test-secret-0123456789abcdef0123456789';

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed, $green, $red, $reset;
    try { $f(); echo "  {$green}PASS{$reset} {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException(($m ? $m . ': ' : '') . 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }

final class AllLicense implements LicenseCheckInterface {
    public function __construct(private string $status = 'active', private bool $all = true) {}
    public function licensed(string $module): bool { return $this->all; }
    public function status(): string { return $this->status; }
    public function info(): array { return []; }
}

/** What the fake module saw, for assertions. */
final class Seen { public static array $calls = []; public static string $pageFile = ''; }

/** A current module: implements PublicModuleInterface. */
final class FakePublicShare implements PublicModuleInterface {
    public static function id(): string { return 'share'; }
    public static function claim(): string { return 'allow_share'; }
    public static function pages(): array { return ['share' => Seen::$pageFile]; }
    public function handlePublic(string $method, string $uri, PublicContext $ctx): void {
        Seen::$calls[] = [$method, $uri, $ctx->secret, $ctx->param($_GET, 'token')];
        if ($uri === '/api/fm/share/file') {
            throw new ApiException('Link expired', 410, 'share_expired', ['x' => 1]);
        }
        echo json_encode(['data' => ['ok' => true], 'error' => null]);
    }
}

/** A module from before the interface: only ModuleInterface. */
final class LegacyShare implements ModuleInterface {
    public static function id(): string { return 'share'; }
    public static function claim(): string { return 'allow_share'; }
}

// What the in-core share handler instantiates. A throwing shareInfo() proves the legacy
// handler ran (it is wrapped into the standard envelope).
eval('namespace FluxFiles\\Share; final class ShareModule {
    public function shareInfo($dm, string $secret, string $token): array {
        throw new \\FluxFiles\\ApiException("legacy handler reached", 418, "legacy_hit");
    }
}');

/** Run dispatch() capturing the body + status. @return array{0:int,1:array|string|null} */
function run(string $id, string $method, string $uri, PublicContext $ctx): array {
    http_response_code(200);
    ob_start();
    PublicRoutes::dispatch($id, $method, $uri, $ctx);
    $out = (string) ob_get_clean();
    $json = json_decode($out, true);
    return [http_response_code() ?: 200, $json ?? $out];
}
function ctx(?LicenseCheckInterface $lic = null, string $secret = 'unit-test-secret-0123456789abcdef0123456789'): PublicContext {
    return new PublicContext(null, null, null, $secret, $lic ?? new AllLicense());
}

$tmp = sys_get_temp_dir() . '/ff-pr-' . uniqid();
mkdir($tmp);
file_put_contents($tmp . '/share.html', "<!doctype html><html><head><title>t</title></head><body>page</body></html>");
Seen::$pageFile = $tmp . '/share.html';

echo "\n{$cyan}══ PublicRoutes ══{$reset}\n\n";

test('moduleFor maps every public path to its module and nothing else', function () {
    foreach (['info', 'unlock', 'file', 'page'] as $r) {
        assertEqual('share', PublicRoutes::moduleFor("/api/fm/share/{$r}"));
    }
    foreach (['info', 'upload', 'page'] as $r) {
        assertEqual('intake', PublicRoutes::moduleFor("/api/fm/intake/{$r}"));
    }
    foreach (['/api/fm/share', '/api/fm/share/create', '/api/fm/intake/unlock', '/api/fm/list', '/api/fm/share/info/', ''] as $u) {
        assertEqual(null, PublicRoutes::moduleFor($u), $u);
    }
    assertTrue(PublicRoutes::isPage('share', '/api/fm/share/page') && !PublicRoutes::isPage('share', '/api/fm/share/info'));
});

test('no module installed → 501 module_not_installed in the standard envelope', function () {
    ModuleRegistry::reset();
    ModuleRegistry::register('intake', 'Not\\Installed\\IntakeModule');
    [$code, $body] = run('intake', 'GET', '/api/fm/intake/info', ctx());
    assertEqual(501, $code);
    assertEqual('module_not_installed', $body['error_code']);
    assertEqual(['module' => 'intake'], $body['error_params']);
    assertEqual(null, $body['data']);
    ModuleRegistry::reset();
});

test('installed but unlicensed (NoLicense) → 402 license_required; handler never runs', function () {
    ModuleRegistry::register('share', FakePublicShare::class);
    Seen::$calls = [];
    [$code, $body] = run('share', 'GET', '/api/fm/share/info', ctx(new NoLicense()));
    assertEqual(402, $code);
    assertEqual('license_required', $body['error_code']);
    assertEqual([], Seen::$calls);
    ModuleRegistry::reset();
});

test('expired licence → 402 license_expired', function () {
    ModuleRegistry::register('share', FakePublicShare::class);
    [$code, $body] = run('share', 'GET', '/api/fm/share/info', ctx(new AllLicense('expired', false)));
    assertEqual(402, $code);
    assertEqual('license_expired', $body['error_code']);
    ModuleRegistry::reset();
});

test('the gate also covers the page route (no page for an unlicensed server)', function () {
    ModuleRegistry::register('share', FakePublicShare::class);
    [$code, $body] = run('share', 'GET', '/api/fm/share/page', ctx(new NoLicense()));
    assertEqual(402, $code);
    ModuleRegistry::reset();
});

test('an unconfigured secret is refused before the module is even looked up', function () {
    ModuleRegistry::register('share', FakePublicShare::class);
    foreach (['', PublicContext::DEFAULT_SECRET] as $s) {
        [$code, $body] = run('share', 'GET', '/api/fm/share/info', ctx(null, $s));
        assertEqual(500, $code);
        assertEqual('server_error', $body['error_code']);
    }
    ModuleRegistry::reset();
});

test('licensed + installed: the module serves the route with the context', function () {
    ModuleRegistry::register('share', FakePublicShare::class);
    Seen::$calls = [];
    $_GET = ['token' => 'tok123'];
    [$code, $body] = run('share', 'GET', '/api/fm/share/info', ctx());
    $_GET = [];
    assertEqual(200, $code);
    assertEqual(['ok' => true], $body['data']);
    assertEqual(['GET', '/api/fm/share/info', 'unit-test-secret-0123456789abcdef0123456789', 'tok123'], Seen::$calls[0]);
    ModuleRegistry::reset();
});

test('an ApiException from the module becomes the envelope with code, status and params', function () {
    ModuleRegistry::register('share', FakePublicShare::class);
    [$code, $body] = run('share', 'GET', '/api/fm/share/file', ctx());
    assertEqual(410, $code);
    assertEqual('share_expired', $body['error_code']);
    assertEqual(['x' => 1], $body['error_params']);
    ModuleRegistry::reset();
});

test('a path that does not belong to the module is a 404, not a handler call', function () {
    ModuleRegistry::register('share', FakePublicShare::class);
    Seen::$calls = [];
    [$code, $body] = run('share', 'GET', '/api/fm/intake/info', ctx());
    assertEqual(404, $code);
    assertEqual([], Seen::$calls);
    ModuleRegistry::reset();
});

test('page route: serves the module page with the meta tag, no inline script', function () {
    ModuleRegistry::register('share', FakePublicShare::class);
    [$code, $html] = run('share', 'GET', '/api/fm/share/page', ctx());
    assertEqual(200, $code);
    assertTrue(is_string($html) && str_contains($html, '<meta name="fm-api-base" content="/api/fm">'), (string) $html);
    assertTrue(!str_contains($html, '__FM_API_BASE__') && !str_contains($html, '<script'), 'no inline script');
    assertTrue(str_contains($html, 'page'), 'original body kept');
    // (The Referrer-Policy / Cache-Control headers are not observable under the CLI SAPI.)
    [$code2] = run('share', 'POST', '/api/fm/share/page', ctx());
    assertEqual(404, $code2, 'pages are GET only');
    ModuleRegistry::reset();
});

test('page(): meta goes after <head>, the base is attribute-escaped, a headless page gets it prepended', function () use ($tmp) {
    ModuleRegistry::register('share', FakePublicShare::class);
    $h = PublicRoutes::page('share', 'https://x.test/wp-json/ff/v1/api/fm?a="b"&c=<d>');
    assertTrue(is_string($h) && str_starts_with($h, "<!doctype html><html><head>\n<meta name=\"fm-api-base\" content=\""), $h);
    assertTrue(str_contains($h, 'a=&quot;b&quot;&amp;c=&lt;d&gt;'), 'escaped');
    assertEqual(1, substr_count($h, 'fm-api-base'));
    assertTrue(str_starts_with(PublicRoutes::injectApiBase('<p>x</p>', '/api/fm'), '<meta name="fm-api-base"'));
    assertEqual(null, PublicRoutes::page('nope', '/api/fm'));
    ModuleRegistry::register('share', 'Not\\Installed\\ShareModule');
    assertEqual(null, PublicRoutes::page('share', '/api/fm'), 'module not installed → no page');
    ModuleRegistry::reset();
});

test('LEGACY module (no PublicModuleInterface): falls back to the in-core handler, same envelope', function () {
    ModuleRegistry::register('share', LegacyShare::class);
    ModuleRegistry::setLicenseResolver(fn () => new AllLicense());   // the in-core handler gates on the registry licence
    $_GET = ['token' => 'x'];
    [$code, $body] = run('share', 'GET', '/api/fm/share/info', ctx());
    $_GET = [];
    ModuleRegistry::setLicenseResolver(null);
    assertEqual(418, $code, json_encode($body));
    assertEqual('legacy_hit', $body['error_code'], 'reached the legacy handler → ShareModule::shareInfo');
    ModuleRegistry::reset();
});

test('LEGACY module is still gated first: unlicensed → 402 before any legacy code runs', function () {
    ModuleRegistry::register('share', LegacyShare::class);
    [$code, $body] = run('share', 'GET', '/api/fm/share/info', ctx(new NoLicense()));
    assertEqual(402, $code);
    ModuleRegistry::reset();
});

test('LEGACY module page: core public/share.html is served with the meta tag', function () {
    ModuleRegistry::register('share', LegacyShare::class);
    $h = PublicRoutes::page('share', '/api/fm');
    assertTrue(is_string($h) && str_contains($h, '<meta name="fm-api-base"') && str_contains($h, 'fm-api-base'), 'meta injected');
    assertTrue(str_contains($h, "querySelector('meta[name=\"fm-api-base\"]')"), 'the page reads it');
    ModuleRegistry::reset();
});

test('PublicContext: param() collapses array-shaped input; tokenBucket() is per-jti when signed, per-IP otherwise', function () {
    $c = ctx();
    assertEqual('', $c->param(['token' => ['a']], 'token'));
    assertEqual('d', $c->param(['token' => ['a']], 'token', 'd'));
    assertEqual('5', $c->param(['n' => 5], 'n'));
    $jwt = \FluxFiles\JwtCompat::encode(['jti' => 'abcdefgh12345678', 'exp' => time() + 60], $c->secret);
    assertEqual('abcdefgh12345678', $c->tokenBucket($jwt));
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $junk = $c->tokenBucket('not-a-token');
    assertEqual($junk, $c->tokenBucket('another-junk'), 'junk tokens share the per-IP bucket');
    assertTrue(strlen($junk) === 24);
    assertTrue($c->secretConfigured() && !ctx(null, '')->secretConfigured());
});

test('PublicContext::rateLimit throws the translated 429 once the bucket is full', function () {
    $b = 'ffpr:' . uniqid();
    $c = ctx();
    $c->rateLimit($b, 2);
    $c->rateLimit($b, 2);
    try { $c->rateLimit($b, 2); throw new \RuntimeException('no throw'); }
    catch (ApiException $e) { assertEqual(429, $e->getHttpCode()); }
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
