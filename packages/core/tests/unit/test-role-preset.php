<?php

/**
 * Test script for the `role` mint-time preset (docs/ACL-ROLE-PRESETS-DESIGN.md).
 *
 * Vectors are loaded from the shared cross-language fixture
 * `docs/testdata/token-vectors.json` (docs/PYTHON-TOKEN-SDK-DESIGN.md §6.1) so the
 * same role/edition/claims-escape-hatch cases are exercised identically by this
 * file, packages/node/tests/token.test.ts, and the future Python SDK's own suite.
 *
 * Usage:
 *   php tests/unit/test-role-preset.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

// Load .env from packages/core/ if present, otherwise fall back to repo root.
foreach ([__DIR__ . '/../..', __DIR__ . '/../../../..'] as $envDir) {
    if (is_file($envDir . '/.env')) {
        Dotenv\Dotenv::createImmutable($envDir)->safeLoad();
        break;
    }
}

require_once __DIR__ . '/../../embed.php';

$green  = "\033[32m";
$red    = "\033[31m";
$yellow = "\033[33m";
$cyan   = "\033[36m";
$reset  = "\033[0m";

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try {
        $fn();
        echo "  {$green}PASS{$reset} {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

function assertEqual($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            $msg ?: "Expected " . json_encode($expected) . " but got " . json_encode($actual)
        );
    }
}

$secret = $_ENV['FLUXFILES_SECRET'] ?? '';
if ($secret === '') {
    echo "{$red}ERROR: FLUXFILES_SECRET not set in .env{$reset}\n";
    exit(1);
}

echo "\n{$cyan}╔══════════════════════════════════════════════════╗{$reset}\n";
echo "{$cyan}║      FluxFiles Role Preset Test Suite            ║{$reset}\n";
echo "{$cyan}╚══════════════════════════════════════════════════╝{$reset}\n\n";

function decode(string $token, string $secret)
{
    return \FluxFiles\JwtCompat::decode($token, $secret);
}

$vectorsPath = __DIR__ . '/../../../../docs/testdata/token-vectors.json';
$vectors = json_decode(file_get_contents($vectorsPath), true);
if (!is_array($vectors)) {
    echo "{$red}ERROR: could not load {$vectorsPath}{$reset}\n";
    exit(1);
}

/**
 * Mint a vector's `input` via the one-options-array API, decode, and assert its
 * `expect`/`expect_true`/`expect_absent` fields against the decoded JWT payload.
 *
 * `<claim>_present` in `expect` asserts effective presence (isset AND === true),
 * not literal key presence — this is what actually distinguishes "enabled" from
 * "not enabled" for a boolean claim, whether an SDK represents "off" by omitting
 * the key or by setting it explicitly false (both decode to the same non-owner-
 * scoped/non-role-granted behavior server-side).
 */
function assertVector(array $vector, string $secret): void
{
    $input = $vector['input'];
    $opts = ['user' => $input['user_id']];
    if (isset($input['role'])) {
        $opts['role'] = $input['role'];
    }
    if (isset($input['edition'])) {
        $opts['edition'] = $input['edition'];
    }
    if (isset($input['ttl_seconds'])) {
        $opts['ttl'] = $input['ttl_seconds'];
    }
    if (isset($input['claims'])) {
        $opts['claims'] = $input['claims'];
    }

    $payload = decode(fluxfiles_token($opts), $secret);

    foreach ($vector['expect'] ?? [] as $key => $expected) {
        if (str_ends_with($key, '_present')) {
            $claim = substr($key, 0, -strlen('_present'));
            $actual = property_exists($payload, $claim) && $payload->{$claim} === true;
            assertEqual($expected, $actual, "{$vector['name']}: {$claim} presence");
            continue;
        }
        if ($key === 'ttl_seconds') {
            assertEqual($expected, $payload->exp - $payload->iat, "{$vector['name']}: ttl_seconds");
            continue;
        }
        // Raw comparison — a genuinely absent claim is `null`, NOT coerced to
        // `false`. Coercing here would make an omitted key indistinguishable
        // from an explicit `false`, which is exactly the historical B1 bug
        // (allow_extract/allow_chmod default to TRUE when absent — see
        // Claims::fromJwtPayload) — mirrors byob_role_presets' loop below and
        // in test-byob.php, which never coerced.
        $actual = $payload->{$key} ?? null;
        if (is_array($expected)) {
            $actual = (array) $actual;
        }
        assertEqual($expected, $actual, "{$vector['name']}: {$key} expected " . json_encode($expected) . " got " . json_encode($actual));
    }

    foreach ($vector['expect_true'] ?? [] as $claim) {
        assertEqual(true, $payload->{$claim} ?? null, "{$vector['name']}: expected {$claim} to be true");
    }

    foreach ($vector['expect_absent'] ?? [] as $claim) {
        assertEqual(false, isset($payload->{$claim}), "{$vector['name']}: expected {$claim} to be absent");
    }
}

foreach (['plain_tokens', 'role_presets', 'edition_presets'] as $group) {
    echo "{$yellow}► {$group}{$reset}\n";
    foreach ($vectors[$group] ?? [] as $vector) {
        test($vector['name'], function () use ($vector, $secret) {
            assertVector($vector, $secret);
        });
    }
    echo "\n";
}

// ═══════════════════════════════════════════════════════════════
echo "{$yellow}► decode-level effective claims (B1 regression, ACL-ROLE-PRESETS-DESIGN.md:625-627){$reset}\n";
// ═══════════════════════════════════════════════════════════════
// Assert against Claims::fromJwtPayload()'s DECODED, effective value — not just
// the raw JWT payload the fixture-driven loop above checks — since asserting
// only isset()/raw-equality is exactly what let the historical B1 bug through
// undetected: an absent allow_extract/allow_chmod key still resolves to `true`
// after decode (unlike most other allow_* claims, which default `false`).

test('viewer role: effective Claims::fromJwtPayload()->allowExtract/allowChmod are false', function () use ($secret) {
    $payload = decode(fluxfiles_token(['user' => 'u', 'role' => 'viewer']), $secret);
    $claims = \FluxFiles\Claims::fromJwtPayload($payload, $secret);
    assertEqual(false, $claims->allowExtract);
    assertEqual(false, $claims->allowChmod);
});

test('editor role: effective Claims::fromJwtPayload()->allowExtract is true, allowChmod is false', function () use ($secret) {
    $payload = decode(fluxfiles_token(['user' => 'u', 'role' => 'editor']), $secret);
    $claims = \FluxFiles\Claims::fromJwtPayload($payload, $secret);
    assertEqual(true, $claims->allowExtract);
    assertEqual(false, $claims->allowChmod);
});

echo "\n";

echo "{$cyan}══════════════════════════════════════════════════{$reset}\n";
echo "{$cyan}  Results: {$green}{$passed} passed{$reset}";
if ($failed > 0) {
    echo ", {$red}{$failed} failed{$reset}";
}
echo "\n{$cyan}══════════════════════════════════════════════════{$reset}\n\n";

exit($failed > 0 ? 1 : 0);
