<?php

/**
 * License issuance service test. Uses an EPHEMERAL keypair (no real private key
 * needed) and verifies every minted key against the REAL core LicenseManager, so
 * the service is proven to produce keys the shipped product trusts end-to-end.
 *
 * Usage: php services/license-server/tests/test-license-server.php
 */

declare(strict_types=1);

$core = __DIR__ . '/../../../packages/core/vendor/autoload.php';
if (!is_file($core)) { fwrite(STDERR, "skip: core vendor not installed\n"); exit(0); }
require_once $core;
require_once __DIR__ . '/../LicenseSigner.php';
require_once __DIR__ . '/../LicenseStore.php';
require_once __DIR__ . '/../Plans.php';
require_once __DIR__ . '/../LicenseIssuer.php';
require_once __DIR__ . '/../PolarWebhook.php';
require_once __DIR__ . '/../LicenseMailer.php';

use FluxFiles\LicenseServer\PolarWebhook;
use FluxFiles\LicenseServer\LicenseMailer;
use FluxFiles\LicenseServer\LicenseSigner;
use FluxFiles\LicenseServer\LicenseStore;
use FluxFiles\LicenseServer\LicenseIssuer;
use FluxFiles\LicenseManager;

$green="\033[32m";$red="\033[31m";$cyan="\033[36m";$reset="\033[0m";$p=0;$f=0;
function test(string $n, callable $fn): void { global $p,$f,$green,$red,$reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$n}\n"; $p++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$n}: ".$e->getMessage()."\n"; $f++; } }
function assertEqual($e,$a,$m=''){ if($e!==$a) throw new RuntimeException(($m?"$m: ":'').'expected '.json_encode($e).' got '.json_encode($a)); }
function assertTrue($c,$m=''){ if(!$c) throw new RuntimeException($m?:'expected true'); }

// Ephemeral keypair → the signer signs with the secret, LicenseManager verifies with
// the matching public key (kid k1).
$kp = sodium_crypto_sign_keypair();
$secretB64 = base64_encode(sodium_crypto_sign_secretkey($kp));
$pubB64 = base64_encode(sodium_crypto_sign_publickey($kp));
$keys = ['k1' => $pubB64];

function issuer(string $secretB64): LicenseIssuer {
    return new LicenseIssuer(new LicenseSigner($secretB64), new LicenseStore(':memory:'));
}

echo "\n{$cyan}══ License issuance service ══{$reset}\n\n";

test('issue Pro → verifies against the real LicenseManager', function () use ($secretB64,$keys) {
    $res = issuer($secretB64)->issue(['email'=>'buyer@acme.com','plan'=>'pro','gateway'=>'manual','order_id'=>'o1']);
    $lm = new LicenseManager($res['key'], $keys);
    assertEqual('pro', $lm->edition(), 'edition');
    assertEqual(['share','intake'], $lm->modules(), 'Pro modules');
    assertEqual(true, $lm->licensed('share'), 'share unlocked');
    assertEqual(true, $lm->licensed('intake'), 'intake unlocked');
    assertEqual(false, $lm->licensed('ai'), 'ai NOT in Pro');
    assertTrue($lm->daysLeft() > 360 && $lm->daysLeft() <= 365, 'annual expiry');
});

test('Studio + Enterprise + lifetime map correctly', function () use ($secretB64,$keys) {
    $iss = issuer($secretB64);
    $st = new LicenseManager($iss->issue(['email'=>'a@b.co','plan'=>'studio','order_id'=>'s1'])['key'], $keys);
    assertEqual('studio', $st->edition()); assertEqual(true, $st->licensed('webhooks'));
    $en = new LicenseManager($iss->issue(['email'=>'a@b.co','plan'=>'enterprise','order_id'=>'e1'])['key'], $keys);
    assertEqual(true, $en->licensed('c2pa'), 'enterprise has c2pa');
    $lf = new LicenseManager($iss->issue(['email'=>'a@b.co','plan'=>'lifetime','order_id'=>'l1'])['key'], $keys);
    assertEqual(null, $lf->expiresAt(), 'lifetime = no expiry');
    assertEqual('active', $lf->status());
});

test('record stored + retrievable by email', function () use ($secretB64) {
    $store = new LicenseStore(':memory:');
    $iss = new LicenseIssuer(new LicenseSigner($secretB64), $store);
    $iss->issue(['email'=>'jane@x.com','plan'=>'pro','gateway'=>'polar','order_id'=>'LS-1']);
    $rows = $store->findByEmail('jane@x.com');
    assertEqual(1, count($rows), 'one license for jane');
    assertEqual('pro', $rows[0]['plan']);
    assertEqual('share,intake', $rows[0]['modules']);
    assertEqual('polar', $rows[0]['gateway']);
});

test('idempotent on (gateway, order_id): repeat webhook reuses the key', function () use ($secretB64) {
    $store = new LicenseStore(':memory:');
    $iss = new LicenseIssuer(new LicenseSigner($secretB64), $store);
    $a = $iss->issue(['email'=>'k@x.com','plan'=>'pro','gateway'=>'polar','order_id'=>'DUP']);
    $b = $iss->issue(['email'=>'k@x.com','plan'=>'pro','gateway'=>'polar','order_id'=>'DUP']);
    assertEqual(false, $a['reused'], 'first is fresh');
    assertEqual(true, $b['reused'], 'second reuses');
    assertEqual($a['key'], $b['key'], 'same key returned');
    assertEqual(1, count($store->all()), 'only one row stored');
});

test('revoke sets status (record of truth for the update channel)', function () use ($secretB64) {
    $store = new LicenseStore(':memory:');
    $iss = new LicenseIssuer(new LicenseSigner($secretB64), $store);
    $r = $iss->issue(['email'=>'r@x.com','plan'=>'pro','order_id'=>'r1']);
    assertEqual(true, $store->setStatus($r['record']['jti'], 'refunded'));
    assertEqual('refunded', $store->findByJti($r['record']['jti'])['status']);
});

test('bad email / unknown plan → rejected', function () use ($secretB64) {
    $iss = issuer($secretB64);
    try { $iss->issue(['email'=>'not-an-email','plan'=>'pro']); throw new RuntimeException('should reject email'); }
    catch (\InvalidArgumentException $e) { assertTrue(str_contains($e->getMessage(),'email')); }
    try { $iss->issue(['email'=>'x@y.com','plan'=>'nope']); throw new RuntimeException('should reject plan'); }
    catch (\InvalidArgumentException $e) { assertTrue(str_contains($e->getMessage(),'plan')); }
});


// ── Licence delivery ─────────────────────────────────────────────────────────
// Without this the key reached nobody: the webhook returned it in a response body
// that every gateway discards, so each order needed manual fulfilment.

/** Capture what the 'log' transport writes, so the rendered mail is inspectable. */
function captureMail(callable $fn): string {
    $tmp = tempnam(sys_get_temp_dir(), 'ffmail');
    $prev = ini_get('error_log');
    ini_set('error_log', $tmp);
    try { $fn(); } finally { ini_set('error_log', $prev === false ? '' : $prev); }
    $out = (string) @file_get_contents($tmp);
    @unlink($tmp);
    return $out;
}

test('mail: the key and the activation line reach the body', function () use ($secretB64) {
    $rec = issuer($secretB64)->issue(['email'=>'buyer@acme.com','plan'=>'pro','gateway'=>'polar','order_id'=>'MAIL-1'])['record'];
    $out = captureMail(fn () => assertTrue((new LicenseMailer('log'))->sendLicense($rec), 'send reports success'));
    assertTrue(str_contains($out, (string) $rec['license_key']), 'the key itself is in the mail');
    assertTrue(str_contains($out, 'FLUXFILES_LICENSE_KEY='), 'tells them how to activate it');
    assertTrue(str_contains($out, 'buyer@acme.com'), 'addressed to the buyer');
});

test('mail: a bad recipient fails soft, never throws', function () use ($secretB64) {
    // The sale already succeeded; a mail problem must not become a 500 that makes the
    // gateway retry and the buyer doubt the charge.
    $rec = issuer($secretB64)->issue(['email'=>'ok@acme.com','plan'=>'pro','gateway'=>'polar','order_id'=>'MAIL-2'])['record'];
    $rec['email'] = 'not-an-email';
    $sent = true;
    $out = captureMail(function () use ($rec, &$sent) { $sent = (new LicenseMailer('log'))->sendLicense($rec); });
    assertEqual(false, $sent, 'reports failure rather than throwing');
    assertTrue(str_contains($out, 'no valid recipient'), 'and says why, for the operator');
});

test('mail: an unconfigured server does not pretend to have sent real mail', function () use ($secretB64) {
    // Default transport is 'log' precisely so a half-configured deploy is obvious.
    $rec = issuer($secretB64)->issue(['email'=>'x@acme.com','plan'=>'pro','gateway'=>'polar','order_id'=>'MAIL-3'])['record'];
    $out = captureMail(fn () => (new LicenseMailer())->sendLicense($rec));
    assertTrue(str_contains($out, '[log transport]'), 'log transport is announced, not silent');
});

// ── Polar webhook (Standard Webhooks) ────────────────────────────────────────
// The signature scheme is the piece most likely to fail silently: wrong in one
// direction drops every purchase, wrong in the other mints licences for anyone.
$WHSEC = 'whsec_' . base64_encode(random_bytes(24));

/** Sign a body the way Polar does, so the test drives the real format. */
function polarHeaders(string $raw, string $secret, ?int $ts = null, ?string $id = null): array {
    $ts = $ts ?? time();
    $id = $id ?? 'msg_' . bin2hex(random_bytes(6));
    $sig = base64_encode(hash_hmac('sha256', $id . '.' . $ts . '.' . $raw, $secret, true));
    return ['webhook-id' => $id, 'webhook-timestamp' => (string) $ts, 'webhook-signature' => 'v1,' . $sig];
}

test('polar: a correctly signed payload verifies', function () use ($WHSEC) {
    $raw = '{"type":"order.paid"}';
    [$ok] = PolarWebhook::verify($raw, $WHSEC, polarHeaders($raw, $WHSEC));
    assertTrue($ok, 'valid signature accepted');
});

test('polar: a tampered body is rejected', function () use ($WHSEC) {
    $raw = '{"type":"order.paid"}';
    $h = polarHeaders($raw, $WHSEC);
    [$ok] = PolarWebhook::verify($raw . ' ', $WHSEC, $h);
    assertTrue(!$ok, 'body change breaks the signature');
});

test('polar: the signature covers the id and timestamp, not just the body', function () use ($WHSEC) {
    $raw = '{"type":"order.paid"}';
    $h = polarHeaders($raw, $WHSEC);
    $h['webhook-id'] = 'msg_other';
    [$ok] = PolarWebhook::verify($raw, $WHSEC, $h);
    assertTrue(!$ok, 'replaying under a different id fails');
});

test('polar: an old timestamp is refused (replay window)', function () use ($WHSEC) {
    $raw = '{"type":"order.paid"}';
    $old = time() - (PolarWebhook::TOLERANCE_SECONDS + 60);
    [$ok, $why] = PolarWebhook::verify($raw, $WHSEC, polarHeaders($raw, $WHSEC, $old));
    assertTrue(!$ok, 'stale delivery refused');
    assertEqual('timestamp outside tolerance', $why);
});

test('polar: the wrong secret is refused', function () use ($WHSEC) {
    $raw = '{"type":"order.paid"}';
    [$ok] = PolarWebhook::verify($raw, $WHSEC, polarHeaders($raw, 'whsec_' . base64_encode(random_bytes(24))));
    assertTrue(!$ok, 'foreign secret refused');
});

test('polar: an unconfigured secret refuses everything', function () use ($WHSEC) {
    $raw = '{"type":"order.paid"}';
    [$ok, $why] = PolarWebhook::verify($raw, '', polarHeaders($raw, $WHSEC));
    assertTrue(!$ok, 'no secret must not mean no checking');
    assertEqual('no secret configured', $why);
});

test('polar: both whsec_ interpretations are accepted (implementations disagree)', function () {
    // Standard Webhooks base64-decodes the part after whsec_; Polar has been observed
    // HMAC-ing the whole string. Both derive from the same operator secret.
    $inner = random_bytes(24);
    $secret = 'whsec_' . base64_encode($inner);
    $raw = '{"type":"order.paid"}';
    foreach ([$secret, $inner] as $keyUsed) {
        [$ok] = PolarWebhook::verify($raw, $secret, polarHeaders($raw, $keyUsed));
        assertTrue($ok, 'accepted whichever key the sender used');
    }
});

test('polar: one valid signature among several (secret rotation) is enough', function () use ($WHSEC) {
    $raw = '{"type":"order.paid"}';
    $h = polarHeaders($raw, $WHSEC);
    $h['webhook-signature'] = 'v1,' . base64_encode(random_bytes(32)) . ' ' . $h['webhook-signature'];
    [$ok] = PolarWebhook::verify($raw, $WHSEC, $h);
    assertTrue($ok, 'rotation-era deliveries still verify');
});

test('polar: extract pulls email/plan/order from order.paid', function () {
    $body = ['type' => 'order.paid', 'data' => [
        'id' => 'ord_123', 'status' => 'paid', 'product_id' => 'prod_pro',
        'customer' => ['email' => 'buyer@acme.com', 'name' => 'Ada'],
    ]];
    $o = PolarWebhook::extract($body, ['prod_pro' => 'pro']);
    assertEqual('buyer@acme.com', $o['email']);
    assertEqual('pro', $o['plan']);
    assertEqual('ord_123', $o['order_id']);
    assertEqual('Ada', $o['customer']);
});

test('polar: order.created is NOT issued on (payment can still fail)', function () {
    $body = ['type' => 'order.created', 'data' => ['id' => 'ord_1', 'status' => 'pending']];
    assertEqual(null, PolarWebhook::extract($body, []));
});

test('polar: an unpaid order.paid body is still refused', function () {
    $body = ['type' => 'order.paid', 'data' => ['id' => 'ord_1', 'status' => 'pending']];
    assertEqual(null, PolarWebhook::extract($body, []));
});

test('polar: unrelated events are ignored, not issued', function () {
    foreach (['subscription.created', 'benefit_grant.created', 'order.refunded'] as $t) {
        assertEqual(null, PolarWebhook::extract(['type' => $t, 'data' => []], []), $t);
    }
});
echo "\n  Total: ".($p+$f)."  {$green}Passed: {$p}{$reset}  {$red}Failed: {$f}{$reset}\n";
exit($f>0?1:0);
