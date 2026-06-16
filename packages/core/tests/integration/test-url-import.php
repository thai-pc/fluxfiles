<?php

/**
 * UrlImporter (Upload from URL, v1 sync) — the parts that don't touch the network:
 * gating, SSRF surfacing, filename/extension resolution, MIME deny-list, and the
 * post-fetch hand-off into FileManager::upload. The live curl fetch is covered by
 * an env-gated integration test (M3).
 *
 * Usage: php tests/integration/test-url-import.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

foreach ([__DIR__ . '/../..', __DIR__ . '/../../../..'] as $envDir) {
    if (is_file($envDir . '/.env')) {
        Dotenv\Dotenv::createImmutable($envDir)->safeLoad();
        break;
    }
}

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;
use FluxFiles\UrlImporter;
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
// Unique pixel per call so the upload pipeline's dedup doesn't treat two test
// images as the same file.
function pngTemp(): string {
    static $n = 0; $n++;
    $t = tempnam(sys_get_temp_dir(), 'ffimptest_');
    $im = imagecreatetruecolor(8, 8);
    imagesetpixel($im, 0, 0, imagecolorallocate($im, ($n * 37) % 256, ($n * 91) % 256, ($n * 53) % 256));
    imagepng($im, $t); imagedestroy($im);
    return $t;
}
function htmlTemp(): string { $t = tempnam(sys_get_temp_dir(), 'ffimptest_'); file_put_contents($t, "<!doctype html><html><body><script>alert(1)</script></body></html>"); return $t; }

$root = sys_get_temp_dir() . '/fluxfiles-import-' . uniqid();
@mkdir($root, 0777, true);
$dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);
$meta = new StorageMetadataHandler($dm);

/** Build a FileManager + Claims (import enabled by default for the tests). */
function makeImporter(bool $allowImport = true, ?string $forcePath = null): array
{
    global $dm, $meta;
    $claims = new Claims('u1', ['read', 'write', 'delete'], ['local'], '', 50, null, 0, false);
    $claims->allowUrlImport = $allowImport;
    if ($forcePath !== null) { $claims->importPath = $forcePath; }
    $fm = new FileManager($dm, $claims, $meta);
    return [new UrlImporter($claims, $fm), $claims, $fm];
}

echo "{$yellow}► gating + SSRF surfacing{$reset}\n";

test('allow_url_import=false → 403 forbidden', function () {
    [$imp] = makeImporter(false);
    try { $imp->import('local', 'https://example.com/a.png'); throw new \RuntimeException('should block'); }
    catch (ApiException $e) { assertEqual('forbidden', $e->getErrorCode()); assertEqual(403, $e->getHttpCode()); }
});

test('empty URL → 422 url_invalid', function () {
    [$imp] = makeImporter();
    try { $imp->import('local', '  '); throw new \RuntimeException('should block'); }
    catch (ApiException $e) { assertEqual('url_invalid', $e->getErrorCode()); }
});

test('import of a loopback URL is SSRF-blocked before any fetch', function () {
    [$imp] = makeImporter();
    try { $imp->import('local', 'http://127.0.0.1/secret'); throw new \RuntimeException('should block'); }
    catch (ApiException $e) { assertEqual('ssrf_blocked', $e->getErrorCode()); }
});

test('import of the cloud-metadata IP is SSRF-blocked', function () {
    [$imp] = makeImporter();
    try { $imp->import('local', 'http://169.254.169.254/latest/meta-data/'); throw new \RuntimeException('should block'); }
    catch (ApiException $e) { assertEqual('ssrf_blocked', $e->getErrorCode()); }
});

echo "{$yellow}► filename / extension resolution{$reset}\n";

test('filename from URL path, extension matched to MIME', function () {
    [$imp] = makeImporter();
    assertEqual('photo.jpg', $imp->resolveFilename('https://cdn.x/a/photo.jpg', '', null, 'image/jpeg'));
});

test('URL without extension gets one from the detected MIME', function () {
    [$imp] = makeImporter();
    assertEqual('photo.png', $imp->resolveFilename('https://cdn.x/photo', '', null, 'image/png'));
});

test('Content-Disposition filename wins over URL', function () {
    [$imp] = makeImporter();
    assertEqual('report.pdf', $imp->resolveFilename('https://x/download?id=9', 'attachment; filename="report.pdf"', null, 'application/pdf'));
});

test('user filename wins over everything; traversal stripped', function () {
    [$imp] = makeImporter();
    assertEqual('safe.png', $imp->resolveFilename('https://x/a.png', 'filename="b.png"', '../../etc/safe.png', 'image/png'));
});

test('wrong extension is corrected to the real MIME (png mislabeled .jpg)', function () {
    [$imp] = makeImporter();
    assertEqual('a.png', $imp->resolveFilename('https://x/a.jpg', '', null, 'image/png'));
});

echo "{$yellow}► finishImport — MIME deny + real upload{$reset}\n";

test('HTML content is denied (magic-byte, not header)', function () {
    [$imp] = makeImporter();
    $tmp = htmlTemp();
    try { $imp->finishImport($tmp, 'local', 'https://x/page', '', []); throw new \RuntimeException('should deny'); }
    catch (ApiException $e) { assertEqual('content_denied', $e->getErrorCode()); }
    finally { @unlink($tmp); }
});

test('SVG denied by default', function () {
    [$imp] = makeImporter();
    $tmp = tempnam(sys_get_temp_dir(), 'ffimptest_');
    file_put_contents($tmp, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    try { $imp->finishImport($tmp, 'local', 'https://x/a.svg', '', []); throw new \RuntimeException('should deny'); }
    catch (ApiException $e) { assertEqual('content_denied', $e->getErrorCode()); }
    finally { @unlink($tmp); }
});

test('a real PNG imports into the upload pipeline (lands on disk)', function () use ($root) {
    [$imp] = makeImporter();
    $tmp = pngTemp();
    $res = $imp->finishImport($tmp, 'local', 'https://cdn.example/imported/sunset.png', '', []);
    @unlink($tmp);
    assertTrue(is_array($res) && isset($res['key']), 'returns a file record');
    assertEqual('sunset.png', basename((string) $res['key']));
    assertTrue(is_file($root . '/' . $res['key']), 'file written to disk');
});

test('importPath claim forces the destination, ignoring the request path', function () use ($root) {
    [$imp] = makeImporter(true, 'forced');
    $tmp = pngTemp();
    $res = $imp->finishImport($tmp, 'local', 'https://cdn.example/x.png', '', ['path' => 'ignored-folder']);
    @unlink($tmp);
    assertEqual('forced/x.png', (string) $res['key'], 'saved under the forced path');
});

// cleanup
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($root);

echo "\n" . ($failed === 0 ? "{$green}All {$passed} tests passed!{$reset}" : "{$red}{$failed} of " . ($passed + $failed) . " failed{$reset}") . "\n";
exit($failed > 0 ? 1 : 0);
