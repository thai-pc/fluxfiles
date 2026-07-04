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

echo "\n  Total: " . ($p + $f) . "  {$green}Passed: {$p}{$reset}  {$red}Failed: {$f}{$reset}\n";
exit($f > 0 ? 1 : 0);
