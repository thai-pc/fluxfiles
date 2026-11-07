<?php

/**
 * Regression: the webhook HMAC key must never travel in readable form in the embed
 * JWT. A JWT is signed, not encrypted, so the browser holding it could base64-decode
 * `webhook_secret` and forge signed events to the operator's receiver.
 *
 * Usage: FLUXFILES_SECRET=<>=32 bytes> php tests/unit/test-webhook-secret-sealed.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

$server = (string) (getenv('FLUXFILES_SECRET') ?: '');
if (strlen($server) < 32) {
    $server = str_repeat('s', 40);
}
$_ENV['FLUXFILES_SECRET'] = $server;
putenv('FLUXFILES_SECRET=' . $server);

require_once __DIR__ . '/../../embed.php';

use FluxFiles\Claims;
use FluxFiles\CredentialEncryptor;
use FluxFiles\JwtCompat;

$passed = 0; $failed = 0;
function test(string $n, callable $f): void {
    global $passed, $failed;
    try { $f(); echo "  \033[32mPASS\033[0m {$n}\n"; $passed++; }
    catch (\Throwable $e) { echo "  \033[31mFAIL\033[0m {$n}: {$e->getMessage()}\n"; $failed++; }
}
function check(bool $c, string $m): void { if (!$c) { throw new \RuntimeException($m); } }

/** What the end user (browser) can do: base64url-decode the payload, no secret. */
function peek(string $jwt): array {
    $part = explode('.', $jwt)[1];
    return json_decode(base64_decode(strtr($part, '-_', '+/')), true);
}

$KEY = 'whsec_super_secret_value_123';

echo "\n\033[36m══ webhook_secret is sealed in the token ══\033[0m\n\n";

test('named option: payload (decoded without the server secret) never contains the key', function () use ($KEY, $server) {
    $jwt = fluxfiles_token(['user' => 'u1', 'claims' => [
        'allow_webhooks' => true, 'webhook_url' => 'https://hooks.example.com/x', 'webhook_secret' => $KEY,
    ]]);
    $p = peek($jwt);
    check(!array_key_exists('webhook_secret', $p), 'plaintext webhook_secret claim present in token');
    check(strpos(json_encode($p), $KEY) === false, 'plaintext key readable anywhere in the payload');
    check(!empty($p['webhook_secret_enc']), 'webhook_secret_enc missing');
    // Server side: decrypts back to the exact key the module signs with.
    $c = Claims::fromJwtPayload(JwtCompat::decode($jwt, $server), $server);
    check($c->webhookSecret === $KEY, 'server did not recover the key');
    check($c->webhookSecretPlaintext === false, 'sealed token flagged as plaintext');
});

test('a wrong server secret cannot recover it (tamper / other tenant)', function () use ($KEY, $server) {
    $blob = CredentialEncryptor::encryptSecret($KEY, $server);
    $threw = false;
    try { CredentialEncryptor::decryptSecret($blob, str_repeat('x', 40)); } catch (\FluxFiles\ApiException $e) { $threw = true; }
    check($threw, 'decrypt with the wrong secret succeeded');
});

test('a BYOB blob cannot be replayed as a webhook secret (separate HKDF info)', function () use ($server) {
    $byob = CredentialEncryptor::encrypt(['driver' => 's3'], $server);
    $threw = false;
    try { CredentialEncryptor::decryptSecret($byob, $server); } catch (\FluxFiles\ApiException $e) { $threw = true; }
    check($threw, 'cross-purpose blob accepted');
});

test('legacy plaintext webhook_secret claim: ignored as a key and flagged (module fails closed)', function () use ($server) {
    $c = Claims::fromJwtPayload((object) ['sub' => 'u', 'allow_webhooks' => true, 'webhook_secret' => 'leaky'], $server);
    check($c->webhookSecret === '', 'plaintext claim was used as the signing key');
    check($c->webhookSecretPlaintext === true, 'plaintext claim not flagged');
});

test('no secret set: nothing sealed, not flagged', function () use ($server) {
    $jwt = fluxfiles_token(['user' => 'u1', 'claims' => ['allow_webhooks' => true, 'webhook_url' => 'https://h.example.com/']]);
    $p = peek($jwt);
    check(!isset($p['webhook_secret']) && !isset($p['webhook_secret_enc']), 'unexpected secret claim');
    $c = Claims::fromJwtPayload(JwtCompat::decode($jwt, $server), $server);
    check($c->webhookSecret === '' && !$c->webhookSecretPlaintext, 'unexpected state');
});

echo "\n";
if ($failed > 0) { echo "\033[31m{$failed} failed\033[0m, {$passed} passed\n"; exit(1); }
echo "\033[32mAll tests passed!\033[0m ({$passed})\n";
