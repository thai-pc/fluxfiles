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
    assertEqual(true, $en->licensed('audit-export'), 'enterprise has audit-export');
    assertEqual(true, $en->licensed('sso'), 'enterprise has sso');
    $lf = new LicenseManager($iss->issue(['email'=>'a@b.co','plan'=>'lifetime','order_id'=>'l1'])['key'], $keys);
    assertEqual(null, $lf->expiresAt(), 'lifetime = no expiry');
    assertEqual('active', $lf->status());
});

test('Support plan unlocks no module (modules=[] gates nothing)', function () use ($secretB64,$keys) {
    $res = issuer($secretB64)->issue(['email'=>'s@x.com','plan'=>'support','gateway'=>'manual','order_id'=>'sup1']);
    $lm = new LicenseManager($res['key'], $keys);
    assertEqual('support', $lm->edition(), 'edition');
    assertEqual([], $lm->modules(), 'Support has no modules');
    foreach (['share','intake','versioning','webhooks','ai','ocr','virus','backup','c2pa'] as $m) {
        assertEqual(false, $lm->licensed($m), "{$m} NOT unlocked by Support");
    }
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


// ── Delivery state ───────────────────────────────────────────────────────────
// A licence that is issued but never delivered is the failure this tracks: without
// mailed_at, a mail outage leaves a paying customer with no key and every later
// webhook retry skips sending because the licence already exists.

test('store: a new licence starts undelivered', function () use ($secretB64) {
    $rec = issuer($secretB64)->issue(['email'=>'d@x.com','plan'=>'pro','gateway'=>'polar','order_id'=>'DEL-1'])['record'];
    assertTrue(empty($rec['mailed_at']), 'mailed_at is not set at issue time');
});

test('store: markMailed records delivery, and only for that licence', function () use ($secretB64) {
    // Hold ONE store: issuer() builds a fresh ':memory:' database per call, so a
    // separately constructed store would be a different database entirely.
    $store = new LicenseStore(':memory:');
    $iss = new LicenseIssuer(new LicenseSigner($secretB64), $store);
    $a = $iss->issue(['email'=>'d@x.com','plan'=>'pro','gateway'=>'polar','order_id'=>'DEL-2'])['record'];
    $b = $iss->issue(['email'=>'d@x.com','plan'=>'pro','gateway'=>'polar','order_id'=>'DEL-3'])['record'];
    assertTrue($store->markMailed((string) $a['jti'], 1700000000), 'reports the update');
    assertEqual(1700000000, (int) $store->findByJti((string) $a['jti'])['mailed_at']);
    assertTrue(empty($store->findByJti((string) $b['jti'])['mailed_at']), 'the other licence is untouched');
    assertEqual(false, $store->markMailed('no-such-jti'), 'a missing licence reports false');
});

test('store: mailed_at is added to a database created before it existed', function () {
    // Deploys predate this column. If the migration did not backfill it, the webhook
    // would read a missing key as "undelivered" and email on every single retry.
    $path = sys_get_temp_dir() . '/ff-legacy-' . uniqid() . '.sqlite';
    $legacy = new PDO('sqlite:' . $path);
    $legacy->exec('CREATE TABLE licenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT, jti TEXT UNIQUE NOT NULL, email TEXT NOT NULL,
        customer TEXT, plan TEXT, edition TEXT, modules TEXT, sites INTEGER DEFAULT 0,
        enforcement TEXT, issued INTEGER, expires INTEGER, license_key TEXT NOT NULL,
        gateway TEXT, order_id TEXT, status TEXT DEFAULT "active", created_at INTEGER)');
    $legacy->exec("INSERT INTO licenses (jti,email,license_key,created_at) VALUES ('old-1','old@x.com','k',1)");
    unset($legacy);

    $store = new LicenseStore($path);      // constructing it runs the migration
    $row = $store->findByJti('old-1');
    assertTrue($row !== null, 'the pre-existing row survives');
    assertTrue(array_key_exists('mailed_at', $row), 'the column was added');
    assertTrue(empty($row['mailed_at']), 'and is null for rows that predate it');
    assertTrue($store->markMailed('old-1'), 'delivery can now be recorded');
    @unlink($path);
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

test('mail: resend transport without an API key fails soft, never throws', function () use ($secretB64) {
    $rec = issuer($secretB64)->issue(['email'=>'y@acme.com','plan'=>'pro','gateway'=>'polar','order_id'=>'MAIL-4'])['record'];
    $prevKey = getenv('FLUXFILES_RESEND_API_KEY');
    putenv('FLUXFILES_RESEND_API_KEY'); // ensure unset
    $sent = true;
    try {
        $sent = (new LicenseMailer('resend'))->sendLicense($rec);
    } finally {
        putenv($prevKey === false ? 'FLUXFILES_RESEND_API_KEY' : "FLUXFILES_RESEND_API_KEY={$prevKey}");
    }
    assertEqual(false, $sent, 'missing API key reports failure rather than throwing');
});

test('mail: a Support-only record gets no activation line', function () use ($secretB64) {
    // modules=[] means no software to activate — the Pro-style FLUXFILES_LICENSE_KEY=
    // instruction would be wrong here, so the mailer must take the other branch.
    $rec = issuer($secretB64)->issue(['email'=>'sup@acme.com','plan'=>'support','gateway'=>'polar','order_id'=>'MAIL-SUP'])['record'];
    $out = captureMail(fn () => assertTrue((new LicenseMailer('log'))->sendLicense($rec), 'send reports success'));
    assertTrue(str_contains($out, 'Priority Support'), 'subject reflects the support subscription');
    assertTrue(!str_contains($out, 'FLUXFILES_LICENSE_KEY='), 'no activation instruction for a support-only purchase');
    assertTrue(str_contains($out, 'sup@acme.com'), 'addressed to the buyer');
});

// ── Renewal/expiry reminders (LICENSE-EXPIRY-NOTIFICATIONS-DESIGN.md §6) ─────
// needingReminder()/markReminderSent() are the idempotency core of the cron job:
// get these wrong and an operator either never hears about a lapsing licence, or
// gets the same email every single day forever.

test('needingReminder(): each configured threshold gets its own bucket, and just above the largest is excluded', function () {
    $store = new LicenseStore(':memory:');
    $now = 1_700_000_000;
    $mk = function (string $jti, int $daysLeft) use ($store, $now) {
        $store->record([
            'jti' => $jti, 'email' => "{$jti}@x.com", 'license_key' => 'k',
            'expires' => $now + $daysLeft * 86400,
        ]);
    };
    $mk('r30', 30); $mk('r14', 14); $mk('r7', 7); $mk('r1', 1); $mk('r31', 31);

    $due = $store->needingReminder([30, 14, 7, 1], $now);
    $byJti = [];
    foreach ($due as $row) { $byJti[$row['jti']] = $row['_reminder_bucket']; }

    assertEqual('30', $byJti['r30'] ?? null, 'exactly at the 30-day threshold -> bucket 30');
    assertEqual('14', $byJti['r14'] ?? null, 'exactly at the 14-day threshold -> bucket 14');
    assertEqual('7', $byJti['r7'] ?? null, 'exactly at the 7-day threshold -> bucket 7');
    assertEqual('1', $byJti['r1'] ?? null, 'exactly at the 1-day threshold -> bucket 1');
    assertTrue(!isset($byJti['r31']), '31 days left (just above the largest configured threshold) is excluded');
});

test('needingReminder(): is idempotent — marking a bucket sent removes the row from the next call at the same now', function () {
    $store = new LicenseStore(':memory:');
    $now = 1_700_000_000;
    $store->record(['jti' => 'idem1', 'email' => 'a@x.com', 'license_key' => 'k', 'expires' => $now + 30 * 86400]);

    $due1 = $store->needingReminder([30, 14, 7, 1], $now);
    assertEqual(1, count($due1), 'due before marking');
    assertTrue($store->markReminderSent('idem1', $due1[0]['_reminder_bucket']));

    $due2 = $store->needingReminder([30, 14, 7, 1], $now);
    assertEqual(0, count($due2), 'no longer due at the same now once marked (would otherwise re-email every run)');
});

test('needingReminder(): NOT marking leaves the row eligible again on the next call (retry-safety contract)', function () {
    // This is the property that makes send-renewal-reminders.php safe to rerun after a
    // failed send: needingReminder() itself must have no side effect.
    $store = new LicenseStore(':memory:');
    $now = 1_700_000_000;
    $store->record(['jti' => 'retry1', 'email' => 'a@x.com', 'license_key' => 'k', 'expires' => $now + 7 * 86400]);

    $due1 = $store->needingReminder([30, 14, 7, 1], $now);
    assertEqual(1, count($due1));
    // Simulate a failed mail send: markReminderSent() is deliberately never called.
    $due2 = $store->needingReminder([30, 14, 7, 1], $now);
    assertEqual(1, count($due2), 'needingReminder() alone does not consume the row');
    assertEqual('7', $due2[0]['_reminder_bucket']);
});

test('needingReminder(): escalation — a worse (smaller) bucket un-suppresses a row already reminded at a better one', function () {
    $store = new LicenseStore(':memory:');
    $now = 1_700_000_000;
    $expires = $now + 30 * 86400;
    $store->record(['jti' => 'esc1', 'email' => 'a@x.com', 'license_key' => 'k', 'expires' => $expires]);

    $due1 = $store->needingReminder([30, 14, 7, 1], $now);
    assertEqual('30', $due1[0]['_reminder_bucket']);
    $store->markReminderSent('esc1', '30');
    assertEqual(0, count($store->needingReminder([30, 14, 7, 1], $now)), 'still suppressed at the same bucket');

    // 16 days later: 14 days left now, crossing the 14 threshold — worse than the
    // stored '30', so the row must un-suppress despite already having a reminder_stage.
    $later = $now + 16 * 86400;
    $due2 = $store->needingReminder([30, 14, 7, 1], $later);
    assertEqual(1, count($due2), 'escalated (worse) bucket is returned again');
    assertEqual('esc1', $due2[0]['jti']);
    assertEqual('14', $due2[0]['_reminder_bucket']);
});

test('needingReminder(): expires IS NULL (lifetime) rows are never returned, regardless of reminder_stage', function () {
    $store = new LicenseStore(':memory:');
    $now = 1_700_000_000;
    $store->record(['jti' => 'life1', 'email' => 'a@x.com', 'license_key' => 'k', 'expires' => null]);
    assertEqual(0, count($store->needingReminder([30, 14, 7, 1], $now)), 'lifetime licence has nothing to renew');
    assertEqual(0, count($store->needingReminder([30, 14, 7, 1], $now + 10 * 365 * 86400)), 'still nothing, no matter how far "now" moves');
});

test('needingReminder(): a non-active (e.g. revoked) row is never returned even past a threshold', function () {
    $store = new LicenseStore(':memory:');
    $now = 1_700_000_000;
    $store->record(['jti' => 'rev1', 'email' => 'a@x.com', 'license_key' => 'k', 'expires' => $now + 5 * 86400, 'status' => 'revoked']);
    assertEqual(0, count($store->needingReminder([30, 14, 7, 1], $now)), 'never re-solicit a revoked/refunded order');
});

test('needingReminder(): grace and expired touch points each fire exactly once', function () {
    $store = new LicenseStore(':memory:');
    $now = 1_700_000_000;
    $expires = $now - 1 * 86400; // expired yesterday
    $store->record(['jti' => 'grace1', 'email' => 'a@x.com', 'license_key' => 'k', 'expires' => $expires, 'grace_days' => 14]);

    $due1 = $store->needingReminder([30, 14, 7, 1], $now); // still within the 14-day grace window
    assertEqual(1, count($due1));
    assertEqual('grace', $due1[0]['_reminder_bucket']);
    $store->markReminderSent('grace1', 'grace');
    assertEqual(0, count($store->needingReminder([30, 14, 7, 1], $now)), 'grace touch point does not re-fire');

    $pastGrace = $expires + 14 * 86400 + 3600; // just past the grace window
    $due2 = $store->needingReminder([30, 14, 7, 1], $pastGrace);
    assertEqual(1, count($due2));
    assertEqual('expired', $due2[0]['_reminder_bucket']);
    $store->markReminderSent('grace1', 'expired');
    assertEqual(0, count($store->needingReminder([30, 14, 7, 1], $pastGrace)), 'expired touch point does not re-fire either');
});

test('issue(): stored grace_days matches what LicenseSigner::mint() actually embedded in the signed key', function () use ($secretB64) {
    $rec = issuer($secretB64)->issue(['email' => 'g@x.com', 'plan' => 'pro', 'gateway' => 'manual', 'order_id' => 'GRACE-1'])['record'];
    [, $p64] = explode('.', (string) $rec['license_key']);
    $payload = json_decode((string) base64_decode(strtr($p64, '-_', '+/'), true), true);
    assertTrue(isset($payload['grace']), 'the signed payload carries a grace window (seconds)');
    $expectedGraceDays = (int) round($payload['grace'] / 86400);
    assertEqual($expectedGraceDays, (int) $rec['grace_days'], 'the store persists exactly what the signer embedded, not a hardcoded 14');
    assertEqual(14, (int) $rec['grace_days'], 'current default is 14 — no Plans entry overrides graceDays yet');
});

test('mail: sendReminder() subscription vs perpetual expiry copy genuinely differs, not just the subject', function () use ($secretB64) {
    $iss = issuer($secretB64);
    $subRec = $iss->issue(['email' => 'sub@x.com', 'plan' => 'pro-monthly', 'gateway' => 'manual', 'order_id' => 'ENF-1'])['record'];
    $perpRec = $iss->issue(['email' => 'perp@x.com', 'plan' => 'pro', 'gateway' => 'manual', 'order_id' => 'ENF-2'])['record'];
    assertEqual('subscription', $subRec['enforcement']);
    assertEqual('perpetual', $perpRec['enforcement']);

    $outSub = captureMail(fn () => (new LicenseMailer('log'))->sendReminder($subRec, 'expired'));
    $outPerp = captureMail(fn () => (new LicenseMailer('log'))->sendReminder($perpRec, 'expired'));

    assertTrue(str_contains($outSub, 'have stopped working for your users'), 'subscription: conveys the harder "features stopped" consequence');
    assertTrue(!str_contains($outSub, 'the software keeps working'), 'subscription copy must not reassure it still works');
    assertTrue(str_contains($outPerp, 'the software keeps working'), 'perpetual: conveys the softer "updates only" consequence');
    assertTrue(!str_contains($outPerp, 'have stopped working for your users'), 'perpetual copy must not claim features stopped');
});

test('mail: sendReminder() branches subject/body by support-only vs module licence', function () use ($secretB64) {
    $iss = issuer($secretB64);
    $supRec = $iss->issue(['email' => 'sup2@x.com', 'plan' => 'support', 'gateway' => 'manual', 'order_id' => 'REM-SUP'])['record'];
    $proRec = $iss->issue(['email' => 'pro2@x.com', 'plan' => 'pro', 'gateway' => 'manual', 'order_id' => 'REM-PRO'])['record'];

    $outSup = captureMail(fn () => assertTrue((new LicenseMailer('log'))->sendReminder($supRec, '7'), 'support reminder sends'));
    assertTrue(str_contains($outSup, 'Priority Support subscription renews soon'), 'support-only subject used');
    assertTrue(!str_contains($outSup, 'FLUXFILES_LICENSE_KEY='), 'no activation line for a support-only reminder');

    $outPro = captureMail(fn () => assertTrue((new LicenseMailer('log'))->sendReminder($proRec, '7'), 'module reminder sends'));
    assertTrue(str_contains($outPro, 'Your FluxFiles licence renews soon'), 'module-licence subject used');
    assertTrue(str_contains($outPro, 'renews/expires on'), 'renewal body used for a module licence');
});

test('mail: sendReminder() with a bad recipient fails soft, never throws', function () use ($secretB64) {
    $rec = issuer($secretB64)->issue(['email' => 'ok2@acme.com', 'plan' => 'pro', 'gateway' => 'manual', 'order_id' => 'REM-BAD'])['record'];
    $rec['email'] = 'not-an-email';
    $sent = true;
    $out = captureMail(function () use ($rec, &$sent) { $sent = (new LicenseMailer('log'))->sendReminder($rec, '7'); });
    assertEqual(false, $sent, 'reports failure rather than throwing');
    assertTrue(str_contains($out, 'no valid recipient'), 'and says why, for the operator');
});

test('mail: the renewal body states the ACTUAL days remaining, not the raw bucket that triggered it', function () use ($secretB64) {
    $now = time();
    $expires = $now + 25 * 86400 + 43200; // ~25.5 days out; wide buffer against test-runtime drift
    $rec = issuer($secretB64)->issue(['email' => 'drift@x.com', 'plan' => 'pro', 'gateway' => 'manual', 'order_id' => 'REM-DRIFT'])['record'];
    $rec['expires'] = $expires; // simulate a cron catch-up: bucket '30' fired late, real days-left is 25
    $out = captureMail(fn () => (new LicenseMailer('log'))->sendReminder($rec, '30'));
    assertTrue(str_contains($out, '(25 day(s) left)'), 'body reflects the real days-left, not the stale "30" bucket that fired it');
    assertTrue(!str_contains($out, '(30 day(s) left)'), 'must not just echo the bucket string as if it were the day count');
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
