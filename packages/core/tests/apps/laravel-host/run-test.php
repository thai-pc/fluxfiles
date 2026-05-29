<?php
declare(strict_types=1);
// Minimal harness that mimics enough Laravel container/config to call FluxFilesManager
// without scaffolding a full Laravel app.

$repoRoot = realpath(__DIR__ . '/../../../../..');
require_once $repoRoot . '/packages/core/vendor/autoload.php';

if (!function_exists('config')) {
    $GLOBALS['__cfg'] = [
        'fluxfiles.secret' => 'dev-test-secret-please-change-in-production-32chars',
        'fluxfiles.mode'   => 'standalone',
        'fluxfiles.endpoint' => 'http://localhost:8088',
        'fluxfiles.defaults' => [
            'ttl' => 3600,
            'perms' => ['read', 'write', 'delete'],
            'disks' => ['local'],
            'prefix' => '',
            'max_upload' => 30,
            'allowed_ext' => null,
            'max_storage' => 0,
        ],
        'app.url' => 'http://localhost:8088',
    ];
    function config(string $key) { return $GLOBALS['__cfg'][$key] ?? null; }
}
if (!function_exists('auth')) {
    function auth() { return new class { public function user() { return null; } }; }
}

// Bring FluxFilesManager in directly (PSR-4 autoload via core vendor wouldn't see it)
require_once $repoRoot . '/packages/laravel/src/FluxFilesManager.php';

$mgr = new \FluxFiles\Laravel\FluxFilesManager();

$tok = $mgr->token('laravel-user', ['ttl' => 600]);
echo "TOKEN: " . substr($tok, 0, 40) . "...\n";
echo "ENDPOINT: " . $mgr->endpoint() . "\n";
echo "IFRAME_SRC: " . $mgr->iframeSrc() . "\n";
echo "SDK_URL: " . $mgr->sdkUrl() . "\n";

// Decode the token and assert claims
$parts = explode('.', $tok);
$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
echo "CLAIMS: " . json_encode([
    'sub' => $payload['sub'],
    'perms' => $payload['perms'],
    'disks' => $payload['disks'],
    'max_upload' => $payload['max_upload'],
    'ttl_sec' => $payload['exp'] - $payload['iat'],
]) . "\n";

// Round-trip: hit the running API with this token and verify list works
$ch = curl_init('http://localhost:8088/api/fm/list?disk=local&path=');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok],
    CURLOPT_RETURNTRANSFER => true,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "API HTTP: $code\n";
echo "API BODY: " . substr($resp, 0, 200) . "\n";

if ($code !== 200) { echo "FAIL\n"; exit(1); }
echo "PASS\n";
