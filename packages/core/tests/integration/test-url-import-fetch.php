<?php

/**
 * URL-import FETCH mechanism (M3): the real curl path against a local fixture
 * server — redirects, per-hop SSRF re-validation, status codes, size cap,
 * Content-Disposition, magic-byte deny. Deterministic (no external network):
 * a fixture HTTP server is started locally and pinned via SsrfGuard's test-only
 * $allowTestHosts hook, so the SSRF guard stays fully enforced for everything
 * EXCEPT that one fixture host (and notably NOT for a redirect away from it).
 *
 * Usage: php tests/integration/test-url-import-fetch.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\UrlImporter;
use FluxFiles\SsrfGuard;
use FluxFiles\ApiException;

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
function assertCode(callable $fn, string $code): void
{
    try { $fn(); throw new \RuntimeException("expected {$code}, no exception"); }
    catch (ApiException $e) { assertEqual($code, $e->getErrorCode(), "wrong code"); }
}

// ── Pick a free port, start the fixture server ───────────────────────────────
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$addr = stream_socket_get_name($probe, false);
$port = (int) substr($addr, strrpos($addr, ':') + 1);
fclose($probe);

$docroot = __DIR__ . '/fixtures';
$proc = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/import-fixture.php'],
    [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
    $pipes,
    $docroot
);
if (!is_resource($proc)) { fwrite(STDERR, "Could not start fixture server\n"); exit(1); }

// Wait for it to accept connections.
$base = "http://127.0.0.1:{$port}";
for ($i = 0; $i < 50; $i++) {
    $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($c) { fclose($c); break; }
    usleep(100000);
}

// Pin the fixture host past the SSRF guard (TEST ONLY).
SsrfGuard::$allowTestHosts = ["127.0.0.1:{$port}"];

$root = sys_get_temp_dir() . '/fluxfiles-importfetch-' . uniqid();
@mkdir($root, 0777, true);
$dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
$meta = new StorageMetadataHandler($dm);

function importer(int $maxMb = 0): UrlImporter
{
    global $dm, $meta;
    $claims = new Claims('u1', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, false);
    $claims->allowUrlImport = true;
    if ($maxMb > 0) { $claims->maxImportMb = $maxMb; }
    return new UrlImporter($claims, new FileManager($dm, $claims, $meta));
}

echo "{$yellow}► fetch mechanism (local fixture, real curl){$reset}\n";

test('happy path: fetches /png → upload pipeline, filename from Content-Disposition', function () use ($base, $root) {
    $res = importer()->import('local', "{$base}/png", ['path' => 'in']);
    assertEqual('in/sunset.png', (string) $res['key'], 'Content-Disposition filename used');
    assertTrue(is_file($root . '/' . $res['key']), 'file on disk');
});

test('302 redirect is followed to the final 200', function () use ($base) {
    $res = importer()->import('local', "{$base}/redirect-ok", ['path' => 'r']);
    assertEqual('r/sunset.png', (string) $res['key']);
});

test('SECURITY: a 302 redirect to the metadata IP is blocked per-hop', function () use ($base) {
    assertCode(fn () => importer()->import('local', "{$base}/redirect-private"), 'ssrf_blocked');
});

test('redirect loop is capped → redirect_loop', function () use ($base) {
    assertCode(fn () => importer()->import('local', "{$base}/loop"), 'redirect_loop');
});

test('404 → fetch_failed', function () use ($base) {
    assertCode(fn () => importer()->import('local', "{$base}/notfound"), 'fetch_failed');
});

test('403 → auth_required', function () use ($base) {
    assertCode(fn () => importer()->import('local', "{$base}/forbidden"), 'auth_required');
});

test('a body larger than max_import_mb → size_exceeded', function () use ($base) {
    assertCode(fn () => importer(1)->import('local', "{$base}/big"), 'size_exceeded'); // 1 MB cap vs 2 MB body
});

test('magic-byte beats a lying Content-Type (HTML served as image/png) → content_denied', function () use ($base) {
    assertCode(fn () => importer()->import('local', "{$base}/html-as-png"), 'content_denied');
});

// ── teardown ────────────────────────────────────────────────────────────────
SsrfGuard::$allowTestHosts = [];
proc_terminate($proc);
proc_close($proc);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($root);

echo "\n" . ($failed === 0 ? "{$green}All {$passed} tests passed!{$reset}" : "{$red}{$failed} of " . ($passed + $failed) . " failed{$reset}") . "\n";
exit($failed > 0 ? 1 : 0);
