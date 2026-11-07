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
require_once __DIR__ . '/../ProDownload.php';

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

test('Studio + Enterprise + lifetime-use map correctly', function () use ($secretB64,$keys) {
    $iss = issuer($secretB64);
    $st = new LicenseManager($iss->issue(['email'=>'a@b.co','plan'=>'studio','order_id'=>'s1'])['key'], $keys);
    assertEqual('studio', $st->edition()); assertEqual(true, $st->licensed('webhooks'));
    $en = new LicenseManager($iss->issue(['email'=>'a@b.co','plan'=>'enterprise','order_id'=>'e1'])['key'], $keys);
    assertEqual(true, $en->licensed('c2pa'), 'enterprise has c2pa');
    assertEqual(true, $en->licensed('audit-export'), 'enterprise has audit-export');
    assertEqual(true, $en->licensed('sso'), 'enterprise has sso');
    $lf = new LicenseManager($iss->issue(['email'=>'a@b.co','plan'=>'lifetime','order_id'=>'l1'])['key'], $keys);
    assertTrue($lf->expiresAt() !== null, 'lifetime-use has a one-year update term');
    assertEqual('active', $lf->status());
    assertEqual('perpetual', $lf->enforcement(), 'lifetime-use modules never hard-stop');
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
    assertTrue($store->isUpdateEligible((string) $r['record']['jti']), 'fresh key may use the update channel');
    assertEqual(true, $store->setStatus($r['record']['jti'], 'refunded'));
    assertEqual('refunded', $store->findByJti($r['record']['jti'])['status']);
    assertTrue(!$store->isUpdateEligible((string) $r['record']['jti']), 'refund closes the update channel');
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
    putenv('FLUXFILES_MAIL_LOG_FULL=1');   // the log transport shows the key only when asked
    try {
        $out = captureMail(fn () => assertTrue((new LicenseMailer('log'))->sendLicense($rec), 'send reports success'));
    } finally {
        putenv('FLUXFILES_MAIL_LOG_FULL');
    }
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
    assertEqual(0, (int) $rec['grace_days'], 'perpetual update terms have no runtime grace');
});

test('issue(): monthly plans embed a seven-day payment-recovery grace', function () use ($secretB64) {
    $rec = issuer($secretB64)->issue(['email' => 'monthly@x.com', 'plan' => 'pro-monthly', 'gateway' => 'manual', 'order_id' => 'GRACE-MONTHLY'])['record'];
    [, $p64] = explode('.', (string) $rec['license_key']);
    $payload = json_decode((string) base64_decode(strtr($p64, '-_', '+/'), true), true);
    assertEqual(7 * 86400, $payload['grace'] ?? null, 'signed monthly grace is exactly seven days');
    assertEqual(7, (int) $rec['grace_days'], 'store mirrors the signed monthly policy');
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

// ── Renewal duplicate-row suppression ────────────────────────────────────────
// A recurring (Polar) subscription fires a NEW webhook with a NEW order_id on every
// renewal, so record()'s (gateway, order_id) idempotency mints a fresh row each
// cycle rather than updating one. Without supersession the OLD row is left `active`
// forever with a stale `expires`, so needingReminder() would surface it (an
// "expired" nag) right alongside the brand new row (a "renews soon" nag) every
// cycle — a self-contradicting pair. See B2 in the post-release review.

test('issue(): a renewal for the same customer+plan supersedes the previous active row', function () use ($secretB64) {
    $store = new LicenseStore(':memory:');
    $iss = new LicenseIssuer(new LicenseSigner($secretB64), $store);
    $a = $iss->issue(['email' => 'ren@x.com', 'customer' => 'ren@x.com', 'plan' => 'pro-monthly', 'gateway' => 'polar', 'order_id' => 'REN-1'])['record'];
    assertEqual('active', $store->findByJti((string) $a['jti'])['status'], 'first row starts active');

    $b = $iss->issue(['email' => 'ren@x.com', 'customer' => 'ren@x.com', 'plan' => 'pro-monthly', 'gateway' => 'polar', 'order_id' => 'REN-2'])['record'];
    assertEqual('superseded', $store->findByJti((string) $a['jti'])['status'], 'old row retired by the renewal');
    assertEqual('active', $store->findByJti((string) $b['jti'])['status'], 'new row stays active');
    assertTrue($store->isUpdateEligible((string) $a['jti']), 'superseding stops reminders, not the old key update term');
    assertTrue($store->isUpdateEligible((string) $b['jti']), 'new key is update eligible');
});

test('issue(): supersession is scoped to the same customer+plan, not just customer', function () use ($secretB64) {
    $store = new LicenseStore(':memory:');
    $iss = new LicenseIssuer(new LicenseSigner($secretB64), $store);
    $proRec = $iss->issue(['email' => 'multi@x.com', 'customer' => 'multi@x.com', 'plan' => 'pro', 'gateway' => 'polar', 'order_id' => 'MULTI-1'])['record'];
    $supportRec = $iss->issue(['email' => 'multi@x.com', 'customer' => 'multi@x.com', 'plan' => 'support-monthly', 'gateway' => 'polar', 'order_id' => 'MULTI-2'])['record'];
    assertEqual('active', $store->findByJti((string) $proRec['jti'])['status'], 'a different plan for the same customer is untouched');
    assertEqual('active', $store->findByJti((string) $supportRec['jti'])['status']);
});

test('issue(): a repeat webhook for the SAME order_id stays idempotent and does not supersede itself', function () use ($secretB64) {
    $store = new LicenseStore(':memory:');
    $iss = new LicenseIssuer(new LicenseSigner($secretB64), $store);
    $a = $iss->issue(['email' => 'dup@x.com', 'customer' => 'dup@x.com', 'plan' => 'pro-monthly', 'gateway' => 'polar', 'order_id' => 'SAME-1'])['record'];
    $b = $iss->issue(['email' => 'dup@x.com', 'customer' => 'dup@x.com', 'plan' => 'pro-monthly', 'gateway' => 'polar', 'order_id' => 'SAME-1'])['record'];
    assertEqual($a['jti'], $b['jti'], 'same order_id reuses the same row');
    assertEqual('active', $store->findByJti((string) $a['jti'])['status'], 'the reused row is never superseded by its own repeat');
});

test('needingReminder(): the old row a renewal supersedes never contributes a reminder, contradictory or otherwise', function () use ($secretB64) {
    $store = new LicenseStore(':memory:');
    $iss = new LicenseIssuer(new LicenseSigner($secretB64), $store);
    $now = time();
    $old = $iss->issue(['email' => 'nag@x.com', 'customer' => 'nag@x.com', 'plan' => 'pro-monthly', 'gateway' => 'polar', 'order_id' => 'NAG-1'])['record'];
    // The renewal — same customer+plan, new order_id — supersedes $old immediately.
    $new = $iss->issue(['email' => 'nag@x.com', 'customer' => 'nag@x.com', 'plan' => 'pro-monthly', 'gateway' => 'polar', 'order_id' => 'NAG-2'])['record'];

    // needingReminder() filters on status = "active" (see the SQL above), so a
    // superseded row is structurally excluded — this pins that behavior end-to-end
    // through the real issue() path rather than only unit-testing the store method.
    $due = $store->needingReminder([30, 14, 7, 1], $now);
    $jtis = array_column($due, 'jti');
    assertTrue(!in_array((string) $old['jti'], $jtis, true), 'superseded old row never surfaces a reminder');
    // Neither row is actually due yet (both freshly issued, far from expiry) — this
    // just confirms the exclusion isn't accidentally hiding the NEW row too.
    assertEqual(0, count($due), 'nothing due right after issuance for either row');
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
test('mail: the log transport redacts the working key by default', function () use ($secretB64) {
    $rec = issuer($secretB64)->issue(['email'=>'log@acme.com','plan'=>'pro','gateway'=>'polar','order_id'=>'MAIL-LOG'])['record'];
    $out = captureMail(fn () => (new LicenseMailer('log'))->sendLicense($rec));
    $key = (string) $rec['license_key'];
    assertTrue(!str_contains($out, $key), 'full key not in the log');
    assertTrue(str_contains($out, substr($key, 0, 12) . '…[redacted]'), 'a recognisable prefix is');
});

test('polar: an unmapped product never issues, even when its NAME is a real plan', function () {
    foreach (['Enterprise', 'Lifetime', 'pro'] as $name) {
        $body = ['type' => 'order.paid', 'data' => ['id' => 'ord_x', 'status' => 'paid',
            'product_id' => 'prod_UNMAPPED', 'product' => ['id' => 'prod_UNMAPPED', 'name' => $name],
            'customer' => ['email' => 'b@acme.com']]];
        $o = PolarWebhook::extract($body, ['prod_pro' => 'pro']);
        assertEqual('', $o['plan'], "product named {$name} → no plan (503 path)");
    }
});

test('polar: order.paid without an order id is ignored (idempotency needs it)', function () {
    $body = ['type' => 'order.paid', 'data' => ['status' => 'paid', 'product_id' => 'prod_pro',
        'customer' => ['email' => 'b@acme.com']]];
    assertEqual(null, PolarWebhook::extract($body, ['prod_pro' => 'pro']));
});

test('polar: a full refund is recognised; a partial refund or another event is not', function () {
    assertEqual('ord_9', PolarWebhook::refundedOrderId(['type' => 'order.refunded', 'data' => ['id' => 'ord_9', 'status' => 'refunded']]));
    assertEqual(null, PolarWebhook::refundedOrderId(['type' => 'order.refunded', 'data' => ['id' => 'ord_9', 'status' => 'partially_refunded']]));
    assertEqual(null, PolarWebhook::refundedOrderId(['type' => 'order.paid', 'data' => ['id' => 'ord_9', 'status' => 'refunded']]));
});

test('issue(): two different buyers with the same display name never supersede each other', function () use ($secretB64) {
    $store = new LicenseStore(':memory:');
    $iss = new LicenseIssuer(new LicenseSigner($secretB64), $store);
    $victim = $iss->issue(['email' => 'v@victim.com', 'customer' => 'John Smith', 'plan' => 'pro', 'gateway' => 'polar', 'order_id' => 'JS-1'])['record'];
    $iss->issue(['email' => 'other@stranger.com', 'customer' => 'John Smith', 'plan' => 'pro', 'gateway' => 'polar', 'order_id' => 'JS-2']);
    assertEqual('active', $store->findByJti((string) $victim['jti'])['status'], 'the first buyer keeps an active licence');
});

test('issue(): the same buyer renewing (email in any case) still supersedes', function () use ($secretB64) {
    $store = new LicenseStore(':memory:');
    $iss = new LicenseIssuer(new LicenseSigner($secretB64), $store);
    $a = $iss->issue(['email' => 'Ren@X.com', 'customer' => 'Ren', 'plan' => 'pro-monthly', 'gateway' => 'polar', 'order_id' => 'RC-1'])['record'];
    $iss->issue(['email' => 'ren@x.com', 'customer' => 'Ren R.', 'plan' => 'pro-monthly', 'gateway' => 'polar', 'order_id' => 'RC-2']);
    assertEqual('superseded', $store->findByJti((string) $a['jti'])['status'], 'renewal retires the old row');
});

test('issue(): manual issues are never superseded (an operator may hand out two keys)', function () use ($secretB64) {
    $store = new LicenseStore(':memory:');
    $iss = new LicenseIssuer(new LicenseSigner($secretB64), $store);
    $m = $iss->issue(['email' => 'm@x.com', 'plan' => 'pro', 'gateway' => 'manual', 'order_id' => 'manual-1'])['record'];
    $iss->issue(['email' => 'm@x.com', 'plan' => 'pro', 'gateway' => 'polar', 'order_id' => 'MP-1']);
    $iss->issue(['email' => 'm@x.com', 'plan' => 'pro', 'gateway' => 'manual', 'order_id' => 'manual-2']);
    assertEqual('active', $store->findByJti((string) $m['jti'])['status'], 'manual row untouched');
});

test('store: a second licence for the same (gateway, order) cannot be stored', function () use ($secretB64) {
    $store = new LicenseStore(':memory:');
    $iss = new LicenseIssuer(new LicenseSigner($secretB64), $store);
    $first = $iss->issue(['email' => 'race@x.com', 'plan' => 'pro', 'gateway' => 'polar', 'order_id' => 'RACE-1'])['record'];
    // What a concurrent delivery that passed the "already issued?" check would insert:
    $row = $store->record($first + ['jti' => bin2hex(random_bytes(12)), 'license_key' => 'other'] );
    assertEqual($first['jti'], $row['jti'], 'the stored licence is returned, not a second one');
    assertEqual(1, count($store->findByEmail('race@x.com')), 'one row for the order');
});

// ── Lifetime + updates renewals (limited deal; inert until the Polar products exist) ──

function renewIssuer(string $secretB64, ?LicenseStore $store = null): LicenseIssuer {
    return new LicenseIssuer(new LicenseSigner($secretB64), $store ?? new LicenseStore(':memory:'));
}

test('pro-lifetime: Pro modules only, perpetual, 12 months of updates', function () use ($secretB64, $keys) {
    $lm = new LicenseManager(renewIssuer($secretB64)->issue(['email'=>'pl@x.com','plan'=>'pro-lifetime','gateway'=>'polar','order_id'=>'PL-1'])['key'], $keys);
    assertEqual('pro', $lm->edition());
    assertEqual(['share','intake'], $lm->modules());
    assertEqual('perpetual', $lm->enforcement());
    assertEqual(false, $lm->licensed('ai'), 'no Studio modules');
    assertTrue($lm->daysLeft() > 360 && $lm->daysLeft() <= 365, 'one year of updates');
});

test('renewal: re-mints the held edition/modules, stacks 12 months on the remaining term, supersedes the old key', function () use ($secretB64, $keys) {
    $store = new LicenseStore(':memory:');
    $iss = renewIssuer($secretB64, $store);
    $base = $iss->issue(['email'=>'Pl@X.com','plan'=>'pro-lifetime','gateway'=>'polar','order_id'=>'PL-2']);
    $ren = $iss->issue(['email'=>'pl@x.com','plan'=>'pro-renewal','gateway'=>'polar','order_id'=>'PR-2']);
    $lm = new LicenseManager($ren['key'], $keys);
    assertEqual('pro', $lm->edition());
    assertEqual(['share','intake'], $lm->modules(), 'same modules as the base, not more');
    assertEqual('perpetual', $lm->enforcement());
    assertTrue($lm->daysLeft() > 725 && $lm->daysLeft() <= 730, 'renewing early stacks on the remaining 365 days');
    assertEqual('superseded', $store->findByJti((string) $base['record']['jti'])['status'], 'old key retired');
    assertEqual('pro-renewal', $ren['record']['plan']);
    // A second renewal chains off the first.
    $ren2 = $iss->issue(['email'=>'pl@x.com','plan'=>'pro-renewal','gateway'=>'polar','order_id'=>'PR-3']);
    assertTrue((new LicenseManager($ren2['key'], $keys))->daysLeft() > 1090, 'chain keeps stacking');
    assertEqual('superseded', $store->findByJti((string) $ren['record']['jti'])['status']);
});

test('renewal: an already-expired licence restarts from now, not from the old date', function () use ($secretB64, $keys) {
    $store = new LicenseStore(':memory:');
    $iss = renewIssuer($secretB64, $store);
    $base = $iss->issue(['email'=>'old@x.com','plan'=>'lifetime','gateway'=>'polar','order_id'=>'L-OLD'])['record'];
    $past = time() - 400 * 86400;
    $pdo = (new ReflectionProperty($store, 'db')); $pdo->setAccessible(true);
    $pdo->getValue($store)->prepare('UPDATE licenses SET expires = ? WHERE jti = ?')->execute([$past, $base['jti']]);
    $lm = new LicenseManager($iss->issue(['email'=>'old@x.com','plan'=>'studio-renewal','gateway'=>'polar','order_id'=>'SR-OLD'])['key'], $keys);
    assertEqual('studio', $lm->edition());
    assertEqual(true, $lm->licensed('versioning'));
    assertTrue($lm->daysLeft() > 360 && $lm->daysLeft() <= 365, 'a full 12 months from today');
});

test('renewal without a qualifying licence is refused (never granted as the base plan)', function () use ($secretB64) {
    $store = new LicenseStore(':memory:');
    $iss = renewIssuer($secretB64, $store);
    $thrown = 0;
    $try = function (string $email, string $plan, string $order) use ($iss, &$thrown) {
        try { $iss->issue(['email'=>$email,'plan'=>$plan,'gateway'=>'polar','order_id'=>$order]); }
        catch (\FluxFiles\LicenseServer\RenewalWithoutLicenceException $e) { $thrown++; }
    };
    $try('nobody@x.com', 'pro-renewal', 'N-1');                       // no licence at all
    $iss->issue(['email'=>'annual@x.com','plan'=>'pro','gateway'=>'polar','order_id'=>'A-1']);
    $try('annual@x.com', 'pro-renewal', 'N-2');                       // annual Pro is not a lifetime licence
    $iss->issue(['email'=>'pl2@x.com','plan'=>'pro-lifetime','gateway'=>'polar','order_id'=>'P-1']);
    $try('pl2@x.com', 'studio-renewal', 'N-3');                       // Pro lifetime cannot buy the Studio renewal
    $r = $iss->issue(['email'=>'rf@x.com','plan'=>'pro-lifetime','gateway'=>'polar','order_id'=>'P-2']);
    $store->setStatus((string) $r['record']['jti'], 'refunded');
    $try('rf@x.com', 'pro-renewal', 'N-4');                           // refunded base
    assertEqual(4, $thrown, 'all four refused');
    assertEqual(null, $store->findByOrder('polar', 'N-1'), 'nothing was stored');
});

test('renewal: a legacy no-expiry lifetime key is not replaced by a 12-month term', function () use ($secretB64) {
    $store = new LicenseStore(':memory:');
    $store->record(['jti'=>bin2hex(random_bytes(12)),'email'=>'legacy@x.com','plan'=>'lifetime','edition'=>'studio','modules'=>['share'],
        'license_key'=>'k','gateway'=>'polar','order_id'=>'LEG-1','expires'=>null]);
    $threw = false;
    try { renewIssuer($secretB64, $store)->issue(['email'=>'legacy@x.com','plan'=>'studio-renewal','gateway'=>'polar','order_id'=>'LEG-R']); }
    catch (\FluxFiles\LicenseServer\RenewalWithoutLicenceException $e) { $threw = true; }
    assertTrue($threw, 'refused');
});

test('renewal: redelivering the same order is idempotent', function () use ($secretB64) {
    $iss = renewIssuer($secretB64);
    $iss->issue(['email'=>'idem@x.com','plan'=>'pro-lifetime','gateway'=>'polar','order_id'=>'I-1']);
    $a = $iss->issue(['email'=>'idem@x.com','plan'=>'pro-renewal','gateway'=>'polar','order_id'=>'I-2']);
    $b = $iss->issue(['email'=>'idem@x.com','plan'=>'pro-renewal','gateway'=>'polar','order_id'=>'I-2']);
    assertEqual(true, $b['reused']); assertEqual($a['key'], $b['key']);
});

test('polar: lifetime/renewal products map via the plan map like any other', function () {
    $map = ['prod_pl'=>'pro-lifetime','prod_sr'=>'studio-renewal'];
    foreach ($map as $pid => $plan) {
        $o = PolarWebhook::extract(['type'=>'order.paid','data'=>['id'=>'ord_'.$pid,'status'=>'paid','product_id'=>$pid,'customer'=>['email'=>'b@x.com']]], $map);
        assertEqual($plan, $o['plan']);
        assertTrue(\FluxFiles\LicenseServer\Plans::get($plan) !== null);
    }
});

test('mail: lifetime says use-forever + 12 months of updates; renewal says extended and swap the key', function () use ($secretB64) {
    $iss = renewIssuer($secretB64);
    $life = $iss->issue(['email'=>'m@x.com','plan'=>'pro-lifetime','gateway'=>'polar','order_id'=>'M-1'])['record'];
    $ren = $iss->issue(['email'=>'m@x.com','plan'=>'pro-renewal','gateway'=>'polar','order_id'=>'M-2'])['record'];
    $render = new ReflectionMethod(LicenseMailer::class, 'renderBody'); $render->setAccessible(true);
    $m = new LicenseMailer('log');
    $a = $render->invoke($m, $life);
    assertTrue(str_contains($a, 'Pro Lifetime') && str_contains($a, 'forever') && str_contains($a, '12 months'), 'lifetime wording');
    assertTrue(!str_contains($a, 'lifetime updates'), 'must not promise lifetime updates');
    $b = $render->invoke($m, $ren);
    assertTrue(str_contains($b, 'renewing') && str_contains($b, 'Replace the FLUXFILES_LICENSE_KEY'), 'renewal wording');
    assertTrue(str_contains($b, gmdate('Y-m-d', (int) $ren['expires'])), 'new date shown');
});

test('polar-setup: default catalogue is the 6 current products; lifetime/renewals only behind their flags', function () {
    $src = (string) file_get_contents(__DIR__ . '/../polar-setup.php');
    assertTrue(str_contains($src, "in_array('--with-lifetime', \$argv, true)"), 'flag exists');
    assertTrue(str_contains($src, "in_array('--with-renewals', \$argv, true)"), 'renewals flag exists');
    assertTrue(str_contains($src, 'CATALOGUE + ($withLifetime ? LIFETIME_CATALOGUE : []) + ($withRenewals ? RENEWAL_CATALOGUE : [])'), 'opt-in merge');
    preg_match('/const CATALOGUE = \[(.*?)\n\];/s', $src, $c); preg_match('/const LIFETIME_CATALOGUE = \[(.*?)\n\];/s', $src, $l); preg_match('/const RENEWAL_CATALOGUE = \[(.*?)\n\];/s', $src, $r);
    preg_match_all("/^    '([a-z-]+)' => \[/m", $c[1], $cm); preg_match_all("/^    '([a-z-]+)' => \[/m", $l[1], $lm); preg_match_all("/^    '([a-z-]+)' => \[/m", $r[1], $rm);
    assertEqual(['pro','pro-monthly','studio','studio-monthly','support','support-monthly'], $cm[1], 'default products unchanged');
    assertEqual(['lifetime','pro-lifetime'], $lm[1]);
    assertEqual(['pro-renewal','studio-renewal'], $rm[1]);
    assertEqual(4, substr_count($l[1] . $r[1], "'recurring' => null"), 'all one-time');
    foreach ([39900, 17900] as $amt) { assertTrue(str_contains($l[1], "'amount' => $amt"), "price $amt"); }
    foreach ([2900, 7900] as $amt) { assertTrue(str_contains($r[1], "'amount' => $amt"), "price $amt"); }
    foreach (array_merge($lm[1], $rm[1]) as $pid) { assertTrue(\FluxFiles\LicenseServer\Plans::get($pid) !== null, "$pid has a plan"); }
});

function rdb(LicenseStore $s): PDO { $p = new ReflectionProperty($s, 'db'); $p->setAccessible(true); return $p->getValue($s); }

test('renewal is bound to Polar customer_id when both sides know it', function () use ($secretB64) {
    $store = new LicenseStore(':memory:'); $iss = renewIssuer($secretB64, $store);
    $iss->issue(['email'=>'v@corp.com','plan'=>'lifetime','gateway'=>'polar','order_id'=>'CB-1','customer_id'=>'cus_victim']);
    $threw = false;
    try { $iss->issue(['email'=>'v@corp.com','plan'=>'studio-renewal','gateway'=>'polar','order_id'=>'CB-2','customer_id'=>'cus_attacker']); }
    catch (\FluxFiles\LicenseServer\RenewalWithoutLicenceException $e) { $threw = true; }
    assertTrue($threw, 'different customer id refused although the email matches');
    $ok = $iss->issue(['email'=>'V@corp.com','plan'=>'studio-renewal','gateway'=>'polar','order_id'=>'CB-3','customer_id'=>'cus_victim']);
    assertEqual('cus_victim', $ok['record']['customer_id']);
    // Base without a customer id (legacy/manual): falls back to the email.
    $iss->issue(['email'=>'old@corp.com','plan'=>'pro-lifetime','gateway'=>'manual','order_id'=>'CB-4']);
    $r = $iss->issue(['email'=>'old@corp.com','plan'=>'pro-renewal','gateway'=>'polar','order_id'=>'CB-5','customer_id'=>'cus_x']);
    assertEqual('pro-renewal', $r['record']['plan']);
});

test('PolarWebhook::extract carries Polar customer_id', function () {
    $o = PolarWebhook::extract(['type'=>'order.paid','data'=>['id'=>'o1','status'=>'paid','product_id'=>'p','customer_id'=>'cus_1','customer'=>['email'=>'a@b.co']]], ['p'=>'pro']);
    assertEqual('cus_1', $o['customer_id']);
    $o = PolarWebhook::extract(['type'=>'order.paid','data'=>['id'=>'o2','status'=>'paid','product_id'=>'p','customer'=>['id'=>'cus_2','email'=>'a@b.co']]], ['p'=>'pro']);
    assertEqual('cus_2', $o['customer_id']);
});

test('refund/revoke of a base cascades to every renewal descendant', function () use ($secretB64) {
    $store = new LicenseStore(':memory:'); $iss = renewIssuer($secretB64, $store);
    $b = $iss->issue(['email'=>'c@x.com','plan'=>'pro-lifetime','gateway'=>'polar','order_id'=>'CA-1'])['record'];
    $r1 = $iss->issue(['email'=>'c@x.com','plan'=>'pro-renewal','gateway'=>'polar','order_id'=>'CA-2'])['record'];
    $r2 = $iss->issue(['email'=>'c@x.com','plan'=>'pro-renewal','gateway'=>'polar','order_id'=>'CA-3'])['record'];
    assertEqual($b['jti'], $r1['renews_jti']); assertEqual($r1['jti'], $r2['renews_jti']);
    $store->setStatusCascade((string) $b['jti'], 'refunded');
    foreach ([$b, $r1, $r2] as $x) {
        assertEqual('refunded', $store->findByJti((string) $x['jti'])['status']);
        assertEqual(false, $store->isUpdateEligible((string) $x['jti']), 'no update eligibility left');
    }
});

test('refunding a renewal reactivates the base it superseded; it can renew again', function () use ($secretB64) {
    $store = new LicenseStore(':memory:'); $iss = renewIssuer($secretB64, $store);
    $b = $iss->issue(['email'=>'rr@x.com','plan'=>'lifetime','gateway'=>'polar','order_id'=>'RR-1'])['record'];
    $r = $iss->issue(['email'=>'rr@x.com','plan'=>'studio-renewal','gateway'=>'polar','order_id'=>'RR-2'])['record'];
    assertEqual('superseded', $store->findByJti((string) $b['jti'])['status']);
    $store->setStatusCascade((string) $r['jti'], 'refunded');
    assertEqual('refunded', $store->findByJti((string) $r['jti'])['status']);
    assertEqual('active', $store->findByJti((string) $b['jti'])['status'], 'parent restored');
    $again = $iss->issue(['email'=>'rr@x.com','plan'=>'studio-renewal','gateway'=>'polar','order_id'=>'RR-3']);
    assertEqual($b['jti'], $again['record']['renews_jti']);
});

test('a renewal supersedes ONLY the row it extends; a manual base stops reminding', function () use ($secretB64) {
    $store = new LicenseStore(':memory:'); $iss = renewIssuer($secretB64, $store);
    $a = $iss->issue(['email'=>'two@x.com','plan'=>'lifetime','gateway'=>'manual','order_id'=>'TW-1'])['record'];
    $b = $iss->issue(['email'=>'two@x.com','plan'=>'lifetime','gateway'=>'polar','order_id'=>'TW-2'])['record'];
    $r = $iss->issue(['email'=>'two@x.com','plan'=>'studio-renewal','gateway'=>'polar','order_id'=>'TW-3'])['record'];
    $st = [$store->findByJti((string) $a['jti'])['status'], $store->findByJti((string) $b['jti'])['status']];
    assertEqual(1, count(array_keys($st, 'active')), 'exactly one lifetime is still active (the one NOT extended)');
    $m = $iss->issue(['email'=>'man@x.com','plan'=>'pro-lifetime','gateway'=>'manual','order_id'=>'MA-1'])['record'];
    $iss->issue(['email'=>'man@x.com','plan'=>'pro-renewal','gateway'=>'polar','order_id'=>'MA-2']);
    assertEqual('superseded', $store->findByJti((string) $m['jti'])['status']);
    foreach ($store->needingReminder([30], (int) $m['expires'] - 10 * 86400) as $x) { assertTrue($x['jti'] !== $m['jti'], 'no reminder for the renewed manual base'); }
});

test('a legacy no-expiry key does not block renewing a newer dated lifetime', function () use ($secretB64) {
    $store = new LicenseStore(':memory:'); $iss = renewIssuer($secretB64, $store);
    $store->record(['jti'=>bin2hex(random_bytes(12)),'email'=>'m1@x.com','plan'=>'lifetime','edition'=>'studio','modules'=>['share'],'license_key'=>'k','gateway'=>'manual','order_id'=>'LEG','expires'=>null]);
    $l2 = $iss->issue(['email'=>'m1@x.com','plan'=>'lifetime','gateway'=>'polar','order_id'=>'L2'])['record'];
    $r = $iss->issue(['email'=>'m1@x.com','plan'=>'studio-renewal','gateway'=>'polar','order_id'=>'R']);
    assertEqual($l2['jti'], $r['record']['renews_jti']);
});

test('mail: renewal text is truthful (key above, old key updates end on its date); unmatched renewal mails the buyer', function () use ($secretB64) {
    $iss = renewIssuer($secretB64);
    $iss->issue(['email'=>'t@x.com','plan'=>'pro-lifetime','gateway'=>'polar','order_id'=>'T-1']);
    $ren = $iss->issue(['email'=>'t@x.com','plan'=>'pro-renewal','gateway'=>'polar','order_id'=>'T-2'])['record'];
    $render = new ReflectionMethod(LicenseMailer::class, 'renderBody'); $render->setAccessible(true);
    $b = $render->invoke(new LicenseMailer('log'), $ren);
    assertTrue(str_contains($b, 'the key above') && str_contains($b, 'only this key carries the extended update window') && str_contains($b, 'updates stop on its original date'), 'truthful');
    assertTrue(!str_contains($b, 'keeps working until then') && !str_contains($b, 'key below'), 'old false claims gone');
    putenv('FLUXFILES_OPS_EMAIL=ops@x.com');
    $out = captureMail(fn () => (new LicenseMailer('log'))->sendRenewalUnmatched('nobody@x.com', 'pro-renewal', 'ORD-9'));
    putenv('FLUXFILES_OPS_EMAIL');
    assertTrue(str_contains($out, 'nobody@x.com') && str_contains($out, 'ops@x.com'), 'buyer and ops notified');
});

test('mail: reminders for one-time plans point at the renewal and the same-email rule', function () use ($secretB64) {
    $rec = renewIssuer($secretB64)->issue(['email'=>'rm@x.com','plan'=>'pro-lifetime','gateway'=>'polar','order_id'=>'RM-1'])['record'];
    $m = new ReflectionMethod(LicenseMailer::class, 'renderRenewalBody'); $m->setAccessible(true);
    $t = $m->invoke(new LicenseMailer('log'), $rec, '30');
    assertTrue(str_contains($t, 'SAME email') && str_contains($t, 'modules keep running') && !str_contains($t, 'renews/expires'), 'one-time wording');
});

test('C1: refunding a middle renewal reports the later renewal it cancelled', function () use ($secretB64) {
    $store = new LicenseStore(':memory:'); $iss = renewIssuer($secretB64, $store);
    $iss->issue(['email'=>'c1@x.com','plan'=>'pro-lifetime','gateway'=>'polar','order_id'=>'C1-B']);
    $r1 = $iss->issue(['email'=>'c1@x.com','plan'=>'pro-renewal','gateway'=>'polar','order_id'=>'C1-R1'])['record'];
    $r2 = $iss->issue(['email'=>'c1@x.com','plan'=>'pro-renewal','gateway'=>'polar','order_id'=>'C1-R2'])['record'];
    $store->setStatusCascade((string) $r1['jti'], 'refunded');
    $c = $store->lastCascade();
    assertEqual(1, count($c)); assertEqual($r2['jti'], $c[0]['jti']); assertEqual('pro-renewal', $c[0]['plan']);
    $store->setStatusCascade((string) $r2['jti'], 'refunded');
    assertEqual([], $store->lastCascade(), 'a plain refund reports nothing');
});

test('C2/C3: un-revoke restores a consistent chain, without reviving cancelled renewals', function () use ($secretB64) {
    $store = new LicenseStore(':memory:'); $iss = renewIssuer($secretB64, $store);
    $b = $iss->issue(['email'=>'c2@x.com','plan'=>'pro-lifetime','gateway'=>'polar','order_id'=>'C2-B'])['record'];
    $r = $iss->issue(['email'=>'c2@x.com','plan'=>'pro-renewal','gateway'=>'polar','order_id'=>'C2-R'])['record'];
    // C3: revoke + un-revoke the renewal -> the base goes back to superseded (one active head).
    $store->setStatusCascade((string) $r['jti'], 'revoked');
    assertEqual('active', $store->findByJti((string) $b['jti'])['status'], 'revoking a renewal restores the base');
    $store->setStatusCascade((string) $r['jti'], 'active');
    assertEqual('active', $store->findByJti((string) $r['jti'])['status']);
    assertEqual('superseded', $store->findByJti((string) $b['jti'])['status'], 'base re-superseded');
    // C2: revoke the base (cascades), un-revoke it: the cancelled renewal stays revoked, base is active.
    $store->setStatusCascade((string) $b['jti'], 'revoked');
    $store->setStatusCascade((string) $b['jti'], 'active');
    assertEqual('active', $store->findByJti((string) $b['jti'])['status']);
    assertEqual('revoked', $store->findByJti((string) $r['jti'])['status'], 'renewal is un-revoked by hand');
    // Un-revoking the base while its renewal is live re-supersedes the base.
    $store->setStatusCascade((string) $r['jti'], 'active');
    $store->setStatusCascade((string) $b['jti'], 'active');
    assertEqual('superseded', $store->findByJti((string) $b['jti'])['status']);
});

test('polar-setup: name collisions prefer the live product over an archived twin', function () {
    $src = (string) file_get_contents(__DIR__ . '/../polar-setup.php');
    preg_match('/function indexProductsByName\(.*?\n}\n/s', $src, $m);
    assertTrue(isset($m[0]), 'function found');
    eval($m[0]);
    $items = fn (array $order) => array_map(fn ($x) => ['name'=>'N','id'=>$x[0],'is_archived'=>$x[1]], $order);
    assertEqual('live', indexProductsByName($items([['arch', true], ['live', false]]))['N']['id']);
    assertEqual('live', indexProductsByName($items([['live', false], ['arch', true]]))['N']['id']);
    assertEqual(true, indexProductsByName($items([['arch', true]]))['N']['archived']);
});

test('mail: the renewal text does not repeat the modules-keep-running sentence', function () use ($secretB64) {
    $iss = renewIssuer($secretB64);
    $iss->issue(['email'=>'d@x.com','plan'=>'pro-lifetime','gateway'=>'polar','order_id'=>'D-1']);
    $ren = $iss->issue(['email'=>'d@x.com','plan'=>'pro-renewal','gateway'=>'polar','order_id'=>'D-2'])['record'];
    $render = new ReflectionMethod(LicenseMailer::class, 'renderBody'); $render->setAccessible(true);
    assertEqual(1, substr_count($render->invoke(new LicenseMailer('log'), $ren), 'running'), 'said once');
});

// ── FluxFiles Pro (WordPress) first-install download ────────────────────────
use FluxFiles\LicenseServer\ProDownload;

$fakeManifest = static function (array $over = []): string {
    $u = static fn (string $x): string => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
    $pl = $over + ['module' => 'fluxfiles-pro', 'version' => '1.0.0', 'url' => 'https://upd.example/dl/m/fluxfiles-pro-1.0.0.zip?md5=x&expires=9', 'sha256' => str_repeat('a', 64)];
    return $u('{"alg":"Ed25519","kid":"r1"}') . '.' . $u((string) json_encode($pl)) . '.' . $u('sig');
};

test('pro download: only plans with share/intake are eligible', function () use ($secretB64) {
    $iss = issuer($secretB64);
    foreach (['pro', 'pro-monthly', 'studio', 'lifetime', 'pro-lifetime', 'enterprise'] as $i => $plan) {
        assertTrue(ProDownload::eligible($iss->issue(['email'=>'a@b.co','plan'=>$plan,'order_id'=>"pd$i"])['record']), "$plan eligible");
    }
    assertEqual(false, ProDownload::eligible($iss->issue(['email'=>'a@b.co','plan'=>'support','order_id'=>'pds'])['record']), 'support has no modules');
});

test('pro download: licence goes in the Authorization header only, link comes back, TTL clamped', function () use ($secretB64, $fakeManifest) {
    $rec = issuer($secretB64)->issue(['email'=>'a@b.co','plan'=>'pro','order_id'=>'pd-h'])['record'];
    $seen = null;
    $out = ProDownload::fetch($rec, 10, function (string $url, array $h) use (&$seen, $fakeManifest) { $seen = [$url, $h]; return [200, $fakeManifest()]; }, 'https://upd.example');
    assertTrue($out !== null && str_contains($out['url'], 'fluxfiles-pro-1.0.0.zip'), 'url returned');
    assertEqual('1.0.0', $out['version']);
    assertEqual(300, $out['expires_in'], 'ttl clamped up to 5 min');
    assertTrue(!str_contains($seen[0], (string) $rec['license_key']), 'key not in URL');
    assertTrue(str_contains($seen[0], '/update/fluxfiles-pro?install=1&link_ttl=300'), 'request shape');
    assertEqual('Authorization: Bearer ' . $rec['license_key'], $seen[1][0]);
});

test('pro download: fails soft (null) on unset base, support plan, non-200, wrong module, bad/insecure url', function () use ($secretB64, $fakeManifest) {
    $iss = issuer($secretB64);
    $rec = $iss->issue(['email'=>'a@b.co','plan'=>'pro','order_id'=>'pd-f'])['record'];
    $ok = fn () => [200, $fakeManifest()];
    assertEqual(null, ProDownload::fetch($rec, 3600, $ok, ''), 'unset base');
    assertEqual(null, ProDownload::fetch($rec, 3600, $ok, 'http://upd.example'), 'plain http to a remote host refused');
    $sup = $iss->issue(['email'=>'a@b.co','plan'=>'support','order_id'=>'pd-s'])['record'];
    assertEqual(null, ProDownload::fetch($sup, 3600, $ok, 'https://upd.example'), 'support plan');
    assertEqual(null, ProDownload::fetch($rec, 3600, fn () => [402, 'nope'], 'https://upd.example'), '402');
    assertEqual(null, ProDownload::fetch($rec, 3600, fn () => [200, $fakeManifest(['module' => 'share'])], 'https://upd.example'), 'wrong module');
    assertEqual(null, ProDownload::fetch($rec, 3600, fn () => [200, $fakeManifest(['url' => 'javascript:alert(1)'])], 'https://upd.example'), 'bad scheme');
    assertEqual(null, ProDownload::fetch($rec, 3600, fn () => [200, $fakeManifest(['url' => 'http://evil.example/x.zip'])], 'https://upd.example'), 'http url');
    assertEqual(null, ProDownload::fetch($rec, 3600, function () { throw new RuntimeException('boom'); }, 'https://upd.example'), 'throwing transport');
});

test('mail: the Pro download link appears only when a link was fetched', function () use ($secretB64) {
    $rec = issuer($secretB64)->issue(['email'=>'a@b.co','plan'=>'pro','order_id'=>'pd-m'])['record'];
    $render = new ReflectionMethod(LicenseMailer::class, 'renderBody'); $render->setAccessible(true);
    $without = $render->invoke(new LicenseMailer('log'), $rec);
    assertTrue(!str_contains($without, 'WordPress:'), 'no block by default');
    $rec['pro_download'] = ['url' => 'https://upd.example/dl/m/x.zip?md5=a&expires=1', 'version' => '1.0.0', 'expires_in' => 604800];
    $with = $render->invoke(new LicenseMailer('log'), $rec);
    assertTrue(str_contains($with, 'https://upd.example/dl/m/x.zip?md5=a&expires=1') && str_contains($with, '7 days'), 'link + validity');
    assertTrue(str_contains($with, 'Keep this email'), 'rest of the mail intact');
});

echo "\n  Total: ".($p+$f)."  {$green}Passed: {$p}{$reset}  {$red}Failed: {$f}{$reset}\n";
exit($f>0?1:0);
