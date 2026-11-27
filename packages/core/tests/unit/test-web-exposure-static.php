<?php

declare(strict_types=1);

/**
 * Static guards for the pre-launch "web-exposed scripts" fix: docker/nginx.conf may hand
 * only api/index.php to FastCGI, every PHP script outside api/ is CLI-only, and the dev
 * router has no `return false;` fall-through. (Live checks: tests/e2e/test-router-allowlist-http.php.)
 */

$root = realpath(__DIR__ . '/../../../..');
$core = $root . '/packages/core';
$p = 0; $f = 0;
function t(string $n, callable $fn): void { global $p, $f;
    try { $fn(); echo "  PASS {$n}\n"; $p++; } catch (\Throwable $e) { echo "  FAIL {$n}: " . $e->getMessage() . "\n"; $f++; } }
function truthy($c, string $m = ''): void { if (!$c) throw new RuntimeException($m ?: 'expected true'); }

$nginx = (string) file_get_contents($root . '/docker/nginx.conf');
$code = preg_replace('/^\s*#.*$/m', '', $nginx);

t('nginx: the only fastcgi_pass lives in `location = /api/index.php`', function () use ($code) {
    truthy(substr_count($code, 'fastcgi_pass') === 1, 'exactly one fastcgi_pass');
    truthy(preg_match('#location\s*=\s*/api/index\.php\s*\{[^}]*fastcgi_pass#s', $code) === 1, 'inside the exact api/index.php location');
    truthy(preg_match('#SCRIPT_FILENAME\s+\$document_root/api/index\.php;#', $code) === 1, 'SCRIPT_FILENAME pinned');
});
t('nginx: no generic `location ~ \.php$` handler and no try_files $uri under /api/', function () use ($code) {
    truthy(!preg_match('#location\s*~\*?\s*\\\\\.php\$\s*\{[^}]*fastcgi#s', $code), 'generic php handler');
    truthy(!preg_match('#location\s+/api/\s*\{[^}]*try_files#s', $code), 'try_files under /api/ serves raw source');
});
t('nginx: other .php paths 404 and the catch-all is default-deny', function () use ($code) {
    truthy(preg_match('#location\s*~\*\s*\\\\\.\(php\[0-9\]\?[^)]*\)\$\s*\{\s*return 404;#', $code) === 1, 'php 404 rule');
    truthy(preg_match('#location\s*/\s*\{\s*return 404;\s*\}#', $code) === 1, 'location / { return 404; }');
});

t('every packages/core/scripts/*.php and tests/generate-token.php is CLI-only', function () use ($core) {
    $files = array_merge(glob($core . '/scripts/*.php') ?: [], [$core . '/tests/generate-token.php']);
    truthy(count($files) >= 6, 'found the scripts');
    foreach ($files as $file) {
        $head = substr((string) file_get_contents($file), 0, 900);
        truthy(preg_match("/if \(PHP_SAPI !== 'cli'\) \{\s*http_response_code\(404\);\s*exit\(1\);/", $head) === 1, basename($file) . ' lacks the CLI guard');
    }
});

t('router.php has no bare static fall-through', function () use ($core) {
    $src = (string) file_get_contents($core . '/router.php');
    truthy(!preg_match('/^return false;\s*$/m', $src), 'top-level `return false;` serves .env / runs .php');
});

t('docker image drops tests/ and scripts/; core dist export-ignores tests/', function () use ($root, $core) {
    $df = (string) file_get_contents($root . '/docker/Dockerfile.prod');
    truthy(preg_match('#rm -rf[^\n]*packages/core/tests[^\n]*packages/core/scripts#', $df) === 1, 'Dockerfile.prod removes them');
    truthy(preg_match('#^/tests export-ignore#m', (string) file_get_contents($core . '/.gitattributes')) === 1, 'core .gitattributes');
});

echo "\n  Total: " . ($p + $f) . "  Passed: {$p}  Failed: {$f}\n";
exit($f > 0 ? 1 : 0);
