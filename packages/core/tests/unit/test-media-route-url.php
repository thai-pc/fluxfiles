<?php

/**
 * /img and /stream URL minting: the token must be joined with `&` when the API base already
 * carries a query string (WordPress on plain permalinks: index.php?rest_route=/…). With a
 * second `?` the token was swallowed into the rest_route value and every responsive preview
 * and private-storage link 404'd.
 *
 * Usage: php tests/unit/test-media-route-url.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\Claims;
use FluxFiles\DiskManager;
use FluxFiles\FileManager;
use FluxFiles\StorageMetadataHandler;

$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed;
    try { $f(); echo "  \033[32mPASS\033[0m {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  \033[31mFAIL\033[0m {$n}: {$e->getMessage()}\n"; $failed++; }
}
function assertEqual($e, $a): void { if ($e !== $a) throw new \RuntimeException('expected ' . json_encode($e) . ' got ' . json_encode($a)); }

$root = sys_get_temp_dir() . '/ff-mru-' . bin2hex(random_bytes(4));
mkdir($root, 0755, true);
register_shutdown_function(static function () use ($root) { exec('rm -rf ' . escapeshellarg($root)); });
$dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root]]);
$fm = new FileManager($dm, new Claims('u', ['read'], ['local'], '', 10, null, 0), new StorageMetadataHandler($dm));
$url = function (string $base, string $route) use ($fm): string {
    $fm->setApiBasePath($base);
    $m = new ReflectionMethod(FileManager::class, 'mediaRouteUrl');
    $m->setAccessible(true);
    return $m->invoke($fm, $route, 'a+b/c=');
};

echo "\n══ media route URL ══\n\n";
test('pretty base joins the token with ?', function () use ($url) {
    assertEqual('/api/fm/img?token=a%2Bb%2Fc%3D', $url('/api/fm', '/img'));
});
test('base with a query string (plain permalinks) joins with &', function () use ($url) {
    assertEqual('http://x/index.php?rest_route=/fluxfiles/v1/api/fm/stream&token=a%2Bb%2Fc%3D',
        $url('http://x/index.php?rest_route=/fluxfiles/v1/api/fm', '/stream'));
});
test('the result has exactly one ?', function () use ($url) {
    assertEqual(1, substr_count($url('http://x/index.php?rest_route=/fluxfiles/v1/api/fm', '/img'), '?'));
});

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
