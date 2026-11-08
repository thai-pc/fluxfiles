<?php

declare(strict_types=1);

/**
 * FluxFiles license issuance server (vendor back-office; NOT part of the stateless
 * core). One front controller:
 *
 *   POST /webhook/polar         — Polar order webhook (the store)
 *   POST /issue                 — admin-authed manual issue {email, plan}
 *   GET  /licenses[?email=]     — admin: list / lookup
 *   POST /revoke                — admin: {jti|subscription_id, status} mark revoked/refunded/active
 *   POST /redeliver             — admin: re-send licences whose mail failed
 *   GET  /claim?checkout_id=    — public: the buyer's key, for an hour after purchase
 *   POST /pro-download          — public (landing origin only): {license_key} -> fresh Pro zip link
 *   POST /refresh               — public: Bearer <current key> -> the newest key of the same subscription/chain
 *   POST /update-status          — update-server auth: {jti} → active/revoked
 *   GET  /health                — liveness
 *
 * Env:
 *   FLUXFILES_LICENSE_PRIVATE_KEY_FILE  path to the 64-byte Ed25519 secret (base64)
 *   FLUXFILES_LICENSE_DB                sqlite path (default ./data/licenses.sqlite)
 *   FLUXFILES_LICENSE_ADMIN_TOKEN       bearer token for /issue,/licenses,/revoke
 *   FLUXFILES_UPDATE_STATUS_TOKEN        bearer token for /update-status (separate from admin)
 *   FLUXFILES_POLAR_WEBHOOK_SECRET      Polar webhook signing secret (whsec_…)
 *   FLUXFILES_POLAR_PLAN_MAP            JSON {"<product_id>":"pro", ...}
 *   FLUXFILES_REFRESH_MAX_STALE_DAYS    days past expiry+grace after which a key no longer refreshes (default 90)
 *   FLUXFILES_OPS_EMAIL                 optional: also notified when a renewal cannot be matched
 *   FLUXFILES_PRO_UPDATE_URL            optional: update-server base URL (https). Enables the FluxFiles Pro
 *                                       download link in /claim, POST /pro-download, and the licence mail's
 *                                       pointer to the landing /download page (all need it AND
 *                                       FLUXFILES_LANDING_ORIGIN; ProDownload.php)
 *
 * Run behind any web server, or for dev:  php -S 127.0.0.1:9000 server.php
 */

require_once __DIR__ . '/LicenseSigner.php';
require_once __DIR__ . '/LicenseStore.php';
require_once __DIR__ . '/Plans.php';
require_once __DIR__ . '/LicenseIssuer.php';
require_once __DIR__ . '/PolarWebhook.php';
require_once __DIR__ . '/LicenseMailer.php';
require_once __DIR__ . '/ProDownload.php';

use FluxFiles\LicenseServer\LicenseMailer;
use FluxFiles\LicenseServer\LicenseSigner;
use FluxFiles\LicenseServer\LicenseStore;
use FluxFiles\LicenseServer\LicenseIssuer;
use FluxFiles\LicenseServer\PolarWebhook;
use FluxFiles\LicenseServer\ProDownload;

header('Content-Type: application/json; charset=utf-8');
// Responses can carry licence keys (/claim, /issue, /licenses): never cache them.
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$raw = file_get_contents('php://input') ?: '';

function respond(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}
/**
 * A refund/revoke cascade changed rows whose own order was not refunded (a later paid
 * renewal). Log each (email masked) and tell the ops address so the lost year can be
 * reissued: POST /issue {plan:<renewal plan>, email:<the lifetime email>}.
 */
function reportCascade(LicenseStore $store, string $origin): void
{
    $rows = $store->lastCascade();
    if ($rows === []) { return; }
    $lines = [];
    foreach ($rows as $r) {
        $e = (string) $r['email'];
        $at = strrpos($e, '@');
        $masked = $at === false ? '***' : substr($e, 0, 1) . '***' . substr($e, $at);
        $lines[] = "{$r['jti']} {$r['plan']} {$masked}";
        error_log("license cascade: {$r['jti']} ({$r['plan']}, {$masked}) cancelled with {$origin}; its own order was not refunded");
    }
    (new LicenseMailer())->sendOpsNotice('FluxFiles: renewals cancelled by a cascade',
        "Cancelling {$origin} also cancelled these renewals, whose own orders were not refunded:\n\n"
        . implode("\n", $lines)
        . "\n\nReissue the lost year if it was paid: POST /issue {\"plan\":\"<pro|studio>-renewal\",\"email\":\"<the lifetime email>\"}.");
}
function env(string $k, string $d = ''): string { $v = getenv($k); return $v === false || $v === '' ? $d : $v; }

/** How long after purchase /claim will still hand back the key (see the endpoint). */
const CLAIM_WINDOW = 3600;
function adminOk(): bool
{
    $token = env('FLUXFILES_LICENSE_ADMIN_TOKEN');
    if ($token === '') { return false; }
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    return hash_equals('Bearer ' . $token, $hdr);
}
function updateStatusOk(): bool
{
    $token = env('FLUXFILES_UPDATE_STATUS_TOKEN');
    if ($token === '') { return false; }
    return hash_equals('Bearer ' . $token, (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
}

try {
    if ($uri === '/health') {
        respond(200, ['ok' => true]);
    }

    $signer = new LicenseSigner();
    $issuer = new LicenseIssuer($signer, new LicenseStore());

    // ── Polar webhook (Standard Webhooks) ────────────────────────────────────
    if ($method === 'POST' && $uri === '/webhook/polar') {
        [$ok, $why] = PolarWebhook::verify(
            $raw,
            env('FLUXFILES_POLAR_WEBHOOK_SECRET'),
            PolarWebhook::headersFromServer($_SERVER)
        );
        if (!$ok) {
            error_log('polar webhook rejected: ' . $why);
            respond(401, ['error' => 'bad signature']);   // never echo $why to the caller
        }

        $body = json_decode($raw, true) ?: [];

        // A full refund retires the licence for the update channel (the key keeps
        // working offline — that is inherent to offline licensing — but stops
        // receiving module updates). Idempotent: a redelivery finds it already done.
        $refunded = PolarWebhook::refundedOrderId($body);
        if ($refunded !== null) {
            $store = new LicenseStore();
            $rec = $store->findByOrder('polar', $refunded);
            if ($rec !== null && in_array((string) $rec['status'], ['active', 'superseded'], true)) {
                // Cascades: a refunded base takes its renewals with it; a refunded
                // renewal gives its base back as `active` (renewable, reminded).
                $store->setStatusCascade((string) $rec['jti'], 'refunded');
                reportCascade($store, 'refunded licence ' . $rec['jti']);
                error_log("polar webhook: order {$refunded} refunded — licence {$rec['jti']} marked refunded");
            }
            respond(200, ['refunded' => $rec !== null]);
        }

        $plans = json_decode(env('FLUXFILES_POLAR_PLAN_MAP', '{}'), true) ?: [];
        $order = PolarWebhook::extract($body, $plans);

        // Not an event we issue on. ACK it: a non-2xx makes Polar retry forever an
        // event we will never act on.
        if ($order === null) {
            respond(200, ['ignored' => (string) ($body['type'] ?? '')]);
        }

        // A product nobody mapped. Say WHICH one, because the fix is a one-line change
        // to FLUXFILES_POLAR_PLAN_MAP and the operator otherwise only sees "Unknown
        // plan:" with an empty name. Answering 5xx is deliberate: Polar retries, so
        // fixing the map inside the retry window delivers the licence automatically
        // instead of leaving a paying customer to open a support ticket.
        // Unlike a mail outage, this fails only orders of ONE product, so other
        // products' 200s keep resetting Polar's consecutive-failure counter and the
        // endpoint survives. Retrying is also the actual fix here: add the id to the
        // map and the next attempt issues.
        if (($order['plan'] ?? '') === '' || \FluxFiles\LicenseServer\Plans::get($order['plan']) === null) {
            $productId = (string) ($body['data']['product_id'] ?? '');
            error_log(sprintf(
                'polar webhook: product %s is not in FLUXFILES_POLAR_PLAN_MAP (order %s, %s) — add it and redeliver',
                $productId !== '' ? $productId : '(none)',
                (string) ($order['order_id'] ?? '?'),
                (string) ($order['email'] ?? '?')
            ));
            respond(503, ['error' => 'plan not configured for this product', 'product_id' => $productId]);
        }

        // Issuing is idempotent on (gateway, order_id), which is what makes Polar's
        // at-least-once delivery safe — a retry returns the same key, never a second one.
        try {
            $res = $issuer->issue($order + ['gateway' => 'polar']);
        } catch (\FluxFiles\LicenseServer\RenewalWithoutLicenceException $e) {
            // Paid for an updates renewal but no lifetime licence matches (different
            // email or Polar customer at checkout?). Nothing is granted, and a 5xx would
            // only make Polar retry the same outcome until it disables the endpoint — so
            // ACK, tell the buyer (and the ops address) and let the operator resolve it:
            // POST /issue {plan:"pro-renewal"|"studio-renewal", email:<the LIFETIME email>}
            // or refund in Polar.
            error_log('polar webhook: RENEWAL WITHOUT LICENCE — ' . $e->getMessage() . ' (order ' . $order['order_id'] . ') — refund or issue manually');
            (new LicenseMailer())->sendRenewalUnmatched((string) $order['email'], (string) $order['plan'], (string) $order['order_id']);
            respond(200, ['ignored' => 'renewal_without_licence', 'order_id' => $order['order_id']]);
        }

        // Deliver the key, keyed on whether it has actually been delivered — NOT on
        // whether this is a first delivery. Those differ in the case that matters: if
        // the mail fails after the licence is stored, every later retry is `reused` and
        // would skip sending, leaving a paying customer with no key and nothing in any
        // log to say so. Tracking mailed_at makes Polar's retry the recovery mechanism.
        $jti = (string) $res['record']['jti'];
        if (empty($res['record']['mailed_at'])) {
            $mailRec = $res['record'];   // the mail points at the landing /download page: no update-server call here
            if ((new LicenseMailer())->sendLicense($mailRec)) {
                (new LicenseStore())->markMailed($jti);
            } else {
                // Answer 200 even though delivery failed, and this is the opposite of
                // what it looks like it should be.
                //
                // Polar disables an endpoint after 10 CONSECUTIVE non-2xx replies. A
                // mail outage fails every order at once, so replying 5xx would burn
                // through those ten in ten sales and take the webhook down for every
                // future customer — turning "one buyer wasn't emailed" into "no buyer
                // is ever processed again". The licence is stored, so nothing is lost:
                // `mailed_at` stays null and POST /redeliver sends the backlog once
                // mail is working.
                error_log("polar webhook: licence {$jti} issued but NOT delivered — run POST /redeliver once mail is fixed");
            }
        }
        respond(200, ['issued' => !$res['reused'], 'jti' => $jti]);
    }

    // ── Public: claim the key from the checkout success page ─────────────────
    //
    // Polar redirects the buyer to `successUrl?checkout_id={CHECKOUT_ID}`; the page
    // calls this to show the key immediately instead of making them wait for email.
    //
    // This is the ONE unauthenticated endpoint that returns a secret, so it is
    // deliberately narrow:
    //   - it never issues, only looks up — the webhook is the only path that mints;
    //   - a checkout id is a high-entropy value the buyer already holds, and one id
    //     maps to exactly one order;
    //   - it 404s for anything not found, so it cannot be used to probe which order
    //     ids exist;
    //   - it is time-boxed: after CLAIM_WINDOW the key is email-only. A success URL
    //     can end up in browser history, a screen share, or a referrer log, and the
    //     window keeps that from being a permanent handle on the key.
    if (($method === 'GET' || $method === 'OPTIONS') && $uri === '/claim') {
        // The success page is a static site on another origin, so it needs CORS — but
        // this endpoint hands back a secret, so the allowed origin is configured
        // explicitly rather than '*'. Unset = no cross-origin access at all.
        $allowed = env('FLUXFILES_LANDING_ORIGIN');
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($allowed !== '' && $origin !== '' && hash_equals($allowed, $origin)) {
            header('Access-Control-Allow-Origin: ' . $allowed);
            header('Vary: Origin');
        }
        if ($method === 'OPTIONS') {
            header('Access-Control-Allow-Methods: GET');
            respond(204, []);
        }
        // Polar redirects the buyer with {CHECKOUT_ID}, which is NOT the order id the
        // webhook stored — look up by whichever the caller has.
        $checkoutId = (string) ($_GET['checkout_id'] ?? '');
        $orderId = (string) ($_GET['order_id'] ?? '');
        if (strlen($checkoutId) < 12 && strlen($orderId) < 12) {
            respond(404, ['error' => 'not found']);   // too short to be a real id
        }
        $store = new LicenseStore();
        $rec = $checkoutId !== '' ? $store->findByCheckout($checkoutId) : $store->findByOrder('polar', $orderId);
        if ($rec === null || ($rec['status'] ?? '') !== 'active') {
            respond(404, ['error' => 'not found']);
        }
        // A renewal key is NEVER served here: its edition/modules come from a licence the
        // checkout email merely CLAIMS to own, so the key must reach only that licence's
        // mailbox. Anything we cannot prove is a base purchase counts as a renewal.
        $recPlan = (string) ($rec['plan'] ?? '');
        if (!empty($rec['renews_jti']) || isset((\FluxFiles\LicenseServer\Plans::get($recPlan) ?? [])['renews']) || str_ends_with($recPlan, '-renewal')) {
            respond(404, ['error' => 'not found', 'hint' => 'renewal keys are emailed to the address of the licence they extend']);
        }
        $age = time() - (int) ($rec['created_at'] ?? 0);   // stored as a unix int
        if ($age > CLAIM_WINDOW) {
            respond(410, ['error' => 'claim window expired', 'hint' => 'the key was emailed to you']);
        }
        $out = [
            'license_key' => (string) $rec['license_key'],
            'edition' => (string) $rec['edition'],
            'expires' => $rec['expires'] ?? null,
        ];
        // Only plans that include a bundled module (share/intake) get the WordPress Pro
        // zip link: a signed, expiring URL on the update host, never the licence itself.
        // Short timeout + per-licence cache, and any failure just omits the link: a licence
        // worker must never wait long on the update server (see /pro-download for the retry path).
        $dl = ProDownload::linkFor($rec, $store, ProDownload::TIMEOUT_CLAIM);
        if ($dl !== null) {
            $out['pro_download'] = $dl;
        }
        respond(200, $out);
    }

    // ── Public: re-download the FluxFiles Pro WordPress zip ──────────────────
    //
    // The landing /download page POSTs the licence key in the BODY (never a URL) and gets a
    // fresh, short-lived secure_link. Answers are generic on purpose: unknown key, revoked,
    // a plan without the Pro modules and an update-server hiccup all read "not available",
    // so this cannot be used to probe which keys exist. Throttled per IP and per licence.
    // Runs on the PUBLIC pool; the update server's callback lands on the internal pool.
    if (($method === 'POST' || $method === 'OPTIONS') && $uri === '/pro-download') {
        $allowed = env('FLUXFILES_LANDING_ORIGIN');
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($allowed === '' || $origin === '' || !hash_equals($allowed, $origin)) {
            respond(403, ['error' => 'forbidden']);        // browsers of the landing site only
        }
        header('Access-Control-Allow-Origin: ' . $allowed);
        header('Vary: Origin');
        if ($method === 'OPTIONS') {
            header('Access-Control-Allow-Methods: POST');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Max-Age: 600');
            respond(204, []);
        }
        $store = new LicenseStore();
        if (!$store->rateHit('dl-ip:' . ProDownload::ipBucket((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 600, 10)) {
            header('Retry-After: 600');
            respond(429, ['error' => 'too many attempts']);
        }
        $b = json_decode($raw, true);
        $key = is_array($b) ? preg_replace('/\s+/', '', (string) ($b['license_key'] ?? '')) : '';
        $rec = is_string($key) && $key !== '' ? $store->findByKey($key) : null;
        $fail = static fn () => respond(404, ['error' => 'not available', 'hint' => 'check the key, or reply to your licence e-mail']);
        if ($rec === null) {
            $fail();
        }
        // Count the licence bucket for EVERY known key before judging it, so a revoked, a
        // superseded and a live key behave the same from outside.
        if (!$store->rateHit('dl-jti:' . $rec['jti'], 600, 5)) {
            header('Retry-After: 600');
            respond(429, ['error' => 'too many attempts']);
        }
        // Same test the update server applies (active OR superseded: an early renewal
        // supersedes the base key, which must keep working), and a plan with Pro modules.
        if (!$store->isUpdateEligible((string) $rec['jti']) || !ProDownload::eligible($rec)) {
            $fail();
        }
        $dl = ProDownload::linkFor($rec, $store, ProDownload::TIMEOUT_CLAIM);   // a public worker never waits long on the update server
        if ($dl === null) {
            $fail();
        }
        respond(200, ['pro_download' => $dl]);
    }

    // ── Public: refresh a licence key ────────────────────────────────────────
    //
    // The holder of a key presents it as a Bearer credential (header ONLY: never the URL
    // or the body, which logs and proxies keep) and gets the newest key its subscription /
    // renewal chain has produced. This never mints anything and makes NO outbound call, so
    // it cannot starve the pool. Order matters: the signature is checked first with no DB
    // touch (anyone with core's public key can compute that, so 401 leaks nothing), and
    // only then does a request cost a rate_hits write. Every other refusal (unknown,
    // mismatched, revoked, refunded, support-only, stale, ambiguous) is ONE identical 404,
    // so the caller learns only "this key will not be refreshed". No CORS: servers, not
    // browsers. Keys are never logged; the log lines carry the jti and an outcome.
    if ($method === 'POST' && $uri === '/refresh') {
        if (strlen($raw) > 1024) {
            respond(400, ['error' => 'request too large']);
        }
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $key = preg_match('/^Bearer\s+(\S+)\s*$/', $auth, $bm) === 1 ? $bm[1] : '';
        $payload = $key !== '' ? $signer->verify($key) : null;
        if ($payload === null) {
            respond(401, ['error' => 'invalid key']);
        }
        $b = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($b)) {
            respond(400, ['error' => 'invalid request']);
        }
        $client = preg_match('/^[a-z0-9.\/-]{1,64}$/', (string) ($b['client'] ?? '')) === 1 ? (string) $b['client'] : '-';

        $store = new LicenseStore();
        $jti = (string) $payload['jti'];
        // Per IP (IPv6 as its /64) as well as per licence. The licence bucket is the tight one, so
        // an agency with many sites behind one NAT is bounded by the looser IP bucket only.
        if (!$store->rateHit('refresh-ip:' . ProDownload::ipBucket((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 3600, 120)
            || !$store->rateHit('refresh-jti:' . $jti, 3600, 10)) {
            header('Retry-After: 3600');
            respond(429, ['error' => 'too many attempts']);
        }
        $notAvailable = static fn () => respond(404, ['error' => 'not available', 'hint' => 'paste the key from your licence e-mail']);
        $row = $store->findByJti($jti);
        if ($row === null || !hash_equals((string) $row['license_key'], $key)) {
            error_log("license refresh: jti={$jti} outcome=unknown client={$client}");
            $notAvailable();
        }
        $staleDays = env('FLUXFILES_REFRESH_MAX_STALE_DAYS', '90');
        $res = $store->newestRefreshable($row, null, ctype_digit($staleDays) ? (int) $staleDays : 90);
        error_log("license refresh: jti={$jti} outcome={$res['outcome']}/{$res['reason']} client={$client}");
        if ($res['reason'] === 'ambiguous') {
            error_log("license refresh: AMBIGUOUS legacy linkage for jti={$jti}: backfill-subscription-ids.php should resolve it");
        }
        if ($res['outcome'] === 'refused') {
            $notAvailable();
        }
        if ($res['outcome'] === 'current') {
            respond(200, ['status' => 'current']);
        }
        $new = $res['row'];
        respond(200, [
            'status' => 'refreshed',
            'license_key' => (string) $new['license_key'],
            'jti' => (string) $new['jti'],
            'issued' => (int) $new['issued'],
            'expires' => $new['expires'] === null ? null : (int) $new['expires'],
        ]);
    }

    // ── Internal: update server asks whether a signed licence is still active ─
    // Runtime remains offline; this is solely the online update channel's revocation
    // check. Keep its credential narrower than the licence-server admin token.
    if ($method === 'POST' && $uri === '/update-status') {
        if (!updateStatusOk()) { respond(401, ['error' => 'unauthorized']); }
        $b = json_decode($raw, true) ?: [];
        $jti = (string) ($b['jti'] ?? '');
        if (!preg_match('/^[a-f0-9]{24}$/', $jti)) { respond(400, ['error' => 'invalid jti']); }
        respond(200, ['active' => (new LicenseStore())->isUpdateEligible($jti)]);
    }

    // ── Admin: manual issue ──────────────────────────────────────────────────
    if ($method === 'POST' && $uri === '/issue') {
        if (!adminOk()) { respond(401, ['error' => 'unauthorized']); }
        $b = json_decode($raw, true) ?: [];
        try {
            $res = $issuer->issue([
                'email' => (string) ($b['email'] ?? ''), 'plan' => (string) ($b['plan'] ?? ''),
                'customer' => (string) ($b['customer'] ?? ''), 'gateway' => 'manual',
                'order_id' => (string) ($b['order_id'] ?? ('manual-' . bin2hex(random_bytes(6)))),
            ]);
        } catch (\InvalidArgumentException $e) {
            respond(400, ['error' => $e->getMessage()]);   // bad email / unknown plan, for the admin
        }
        respond(200, ['license_key' => $res['key'], 'record' => $res['record']]);
    }

    // ── Admin: list / lookup ─────────────────────────────────────────────────
    if ($method === 'GET' && $uri === '/licenses') {
        if (!adminOk()) { respond(401, ['error' => 'unauthorized']); }
        $store = new LicenseStore();
        $email = (string) ($_GET['email'] ?? '');
        respond(200, ['licenses' => $email !== '' ? $store->findByEmail($email) : $store->all()]);
    }

    // ── Admin: re-send undelivered licences ──────────────────────────────────
    //
    // The recovery path for a mail outage. The webhook answers 200 even when delivery
    // fails (a 5xx would disable the endpoint after ten consecutive failures, and an
    // outage fails every order), so the backlog lands here instead. Safe to run at any
    // time: it only touches licences with no mailed_at, and marks each as it goes.
    if ($method === 'POST' && $uri === '/redeliver') {
        if (!adminOk()) { respond(401, ['error' => 'unauthorized']); }
        $store = new LicenseStore();
        $mailer = new LicenseMailer();
        $sent = 0; $failed = 0;
        foreach ($store->undelivered() as $rec) {
            if ($mailer->sendLicense($rec)) {
                $store->markMailed((string) $rec['jti']);
                $sent++;
            } else {
                $failed++;   // stays in the backlog for the next run
            }
        }
        respond(200, ['sent' => $sent, 'failed' => $failed]);
    }

    // ── Admin: revoke / refund ───────────────────────────────────────────────
    if ($method === 'POST' && $uri === '/revoke') {
        if (!adminOk()) { respond(401, ['error' => 'unauthorized']); }
        $b = json_decode($raw, true) ?: [];
        $status = (string) ($b['status'] ?? 'revoked');
        // `superseded` is the store's own bookkeeping and stays update-eligible, so
        // setting it (or anything unknown) by hand would silently un-revoke a key.
        if (!in_array($status, ['revoked', 'refunded', 'active'], true)) {
            respond(400, ['error' => 'status must be revoked, refunded or active']);
        }
        $store = new LicenseStore();
        // {"subscription_id": ...} revokes every key of one subscription, so none of them can refresh.
        $sid = (string) ($b['subscription_id'] ?? '');
        if ($sid !== '') {
            if (preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $sid) !== 1) { respond(400, ['error' => 'invalid subscription_id']); }
            $n = $store->setStatusForSubscription($sid, $status);
            error_log("license revoke: subscription {$sid} -> {$status} ({$n} row(s))");
            respond($n > 0 ? 200 : 404, ['updated' => $n > 0, 'rows' => $n]);
        }
        $ok = $store->setStatusCascade((string) ($b['jti'] ?? ''), $status);
        reportCascade($store, "{$status} licence " . (string) ($b['jti'] ?? ''));
        respond($ok ? 200 : 404, ['updated' => $ok]);
    }

    respond(404, ['error' => 'not found']);
} catch (\Throwable $e) {
    // Log the detail for the operator; the caller (possibly unauthenticated) gets
    // nothing about the signer, the database or the input that broke.
    error_log('license server: ' . $e);
    respond(500, ['error' => 'internal error']);
}
