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
    $iss->issue(['email'=>'jane@x.com','plan'=>'pro','gateway'=>'lemonsqueezy','order_id'=>'LS-1']);
    $rows = $store->findByEmail('jane@x.com');
    assertEqual(1, count($rows), 'one license for jane');
    assertEqual('pro', $rows[0]['plan']);
    assertEqual('share,intake', $rows[0]['modules']);
    assertEqual('lemonsqueezy', $rows[0]['gateway']);
});

test('idempotent on (gateway, order_id): repeat webhook reuses the key', function () use ($secretB64) {
    $store = new LicenseStore(':memory:');
    $iss = new LicenseIssuer(new LicenseSigner($secretB64), $store);
    $a = $iss->issue(['email'=>'k@x.com','plan'=>'pro','gateway'=>'lemonsqueezy','order_id'=>'DUP']);
    $b = $iss->issue(['email'=>'k@x.com','plan'=>'pro','gateway'=>'lemonsqueezy','order_id'=>'DUP']);
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

echo "\n  Total: ".($p+$f)."  {$green}Passed: {$p}{$reset}  {$red}Failed: {$f}{$reset}\n";
exit($f>0?1:0);
