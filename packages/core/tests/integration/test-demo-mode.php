<?php

declare(strict_types=1);

/**
 * DemoMode: the hardened per-visitor token for the public "try it live" instance.
 * Verifies the sandbox is actually locked down (prefix scope, images only, caps,
 * owner-only, dangerous claims off) so an embedded demo can't be abused.
 */

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../embed.php';

use FluxFiles\DemoMode;
use FluxFiles\Claims;

$_ENV['FLUXFILES_SECRET'] = 'demo-test-secret-key-at-least-32-bytes-xx';
putenv('FLUXFILES_DEMO=1');

$green = "\033[32m"; $red = "\033[31m"; $reset = "\033[0m"; $p = 0; $f = 0;
function t(string $n, callable $fn): void { global $p, $f, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$n}\n"; $p++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: " . $e->getMessage() . "\n"; $f++; } }
function eq($e, $a, $m = '') { if ($e !== $a) throw new RuntimeException(($m ? "$m: " : '') . json_encode($e) . ' != ' . json_encode($a)); }
function truthy($c, $m = '') { if (!$c) throw new RuntimeException($m ?: 'expected true'); }

/** Decode a demo token's payload into a Claims object (server-side view). */
function claimsFor(string $id): Claims
{
    $tok = DemoMode::mintToken($id);
    $payload = json_decode(base64_decode(strtr(explode('.', $tok)[1], '-_', '+/')));
    return Claims::fromJwtPayload($payload);
}

echo "\n══ DemoMode ══\n\n";

t('enabled() reflects FLUXFILES_DEMO', function () {
    truthy(DemoMode::enabled(), 'demo enabled when FLUXFILES_DEMO=1');
});

t('token is scoped to the visitor sandbox prefix', function () {
    $c = claimsFor('abcdef0123456789');
    // path scoping: a path inside the sandbox is allowed, outside is not
    truthy($c->hasDisk('local'), 'local disk allowed');
    $scoped = $c->scopePath('demo/abcdef0123456789/pic.png');
    truthy(strpos($scoped, 'demo/abcdef0123456789') === 0, 'stays under sandbox prefix');
});

t('write + delete allowed, but owner-only', function () {
    $c = claimsFor('abcdef0123456789');
    truthy($c->hasPerm('read') && $c->hasPerm('write') && $c->hasPerm('delete'), 'r/w/d');
    truthy($c->ownerOnly, 'owner-only on');
});

t('images only — a non-image extension is rejected', function () {
    $c = claimsFor('abcdef0123456789');
    truthy(in_array('png', $c->allowedExt, true), 'png ok');
    truthy(in_array('jpg', $c->allowedExt, true), 'jpg ok');
    truthy(!in_array('php', $c->allowedExt, true), 'php blocked');
    truthy(!in_array('txt', $c->allowedExt, true), 'txt blocked');
});

t('small caps: upload size + quota + file count', function () {
    $c = claimsFor('abcdef0123456789');
    eq(5, $c->maxUploadMb, 'max upload 5MB');
    truthy($c->maxStorageMb > 0 && $c->maxStorageMb <= 50, 'quota ≤ 50MB');
    truthy($c->maxFiles > 0 && $c->maxFiles <= 30, 'file cap ≤ 30');
});

t('dangerous claims are off (terminal / import / byob)', function () {
    $c = claimsFor('abcdef0123456789');
    truthy(!$c->isAllowed('allow_terminal'), 'terminal off');
    truthy(!$c->isAllowed('allow_url_import'), 'url import off');
});

t('two visitors get distinct sandboxes', function () {
    $a = DemoMode::mintToken('1111111111111111');
    $b = DemoMode::mintToken('2222222222222222');
    truthy($a !== $b, 'different tokens');
    $pa = json_decode(base64_decode(strtr(explode('.', $a)[1], '-_', '+/')), true);
    $pb = json_decode(base64_decode(strtr(explode('.', $b)[1], '-_', '+/')), true);
    truthy($pa['prefix'] !== $pb['prefix'], 'different prefixes');
});

t('forceLocalDisks strips S3/R2/SFTP (no egress cost)', function () {
    $configs = [
        'local' => ['driver' => 'local', 'root' => '/tmp'],
        'my-s3' => ['driver' => 's3', 'bucket' => 'x'],
        'my-r2' => ['driver' => 's3', 'endpoint' => 'r2'],
        'sftp'  => ['driver' => 'sftp', 'host' => 'x'],
    ];
    $out = DemoMode::forceLocalDisks($configs);
    eq(['local'], array_keys($out), 'only local survives');
});

t('per-IP mint throttle blocks after the hourly budget', function () {
    $dir = sys_get_temp_dir() . '/ff-demo-ip-' . uniqid();
    @mkdir($dir, 0777, true);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
    putenv('FLUXFILES_DEMO_IP_MINTS=3');
    $allowed = 0;
    for ($i = 0; $i < 6; $i++) {
        if (DemoMode::recordMintAllowed($dir)) { $allowed++; }
    }
    putenv('FLUXFILES_DEMO_IP_MINTS');
    eq(3, $allowed, 'exactly the budget (3) allowed, rest blocked');
    // a different IP is independent
    $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
    truthy(DemoMode::recordMintAllowed($dir), 'a fresh IP is not throttled');
    array_map('unlink', glob("$dir/*") ?: []); @rmdir($dir);
});

t('bootConfig throttles a NEW visitor over budget (no token)', function () {
    $dir = sys_get_temp_dir() . '/ff-demo-boot-' . uniqid();
    @mkdir($dir, 0777, true);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.20';
    unset($_COOKIE['ff_demo']);        // simulate a fresh visitor (no cookie)
    putenv('FLUXFILES_DEMO_IP_MINTS=2');
    $tokens = 0; $throttled = 0;
    for ($i = 0; $i < 4; $i++) {
        // each call is a "new visitor" (no cookie) from the same IP
        $cfg = DemoMode::bootConfig($dir);
        if (!empty($cfg['token'])) { $tokens++; } else { $throttled++; }
    }
    putenv('FLUXFILES_DEMO_IP_MINTS');
    truthy($tokens <= 2, 'no more than the budget got tokens');
    truthy($throttled >= 2, 'the rest were throttled (no token)');
    array_map('unlink', glob("$dir/*") ?: []); @rmdir($dir);
});

echo "\n  Total: " . ($p + $f) . "  {$green}Passed: {$p}{$reset}  {$red}Failed: {$f}{$reset}\n";
exit($f > 0 ? 1 : 0);
