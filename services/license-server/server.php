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
 *   FLUXFILES_POLAR_IGNORE_PRODUCTS     optional: comma-separated Polar product ids acknowledged (2xx) and ignored, no licence
 *                                       or alert (e.g. a sponsorship product); the plan map wins if an id is in both
 *   FLUXFILES_SUBSCRIPTION_PORTAL_URL   optional, non-secret: https URL of the Polar customer portal; quoted in the auto-renew
 *                                       paragraph of subscription licence/reminder mails (omitted when unset or not https)
 *   FLUXFILES_REFRESH_MAX_STALE_DAYS    days past expiry+grace after which a key no longer refreshes (default 90)
 *   FLUXFILES_OPS_EMAIL                 optional: also notified when a renewal cannot be matched
 *   FLUXFILES_LICENSE_INSTANCE          optional: instance name echoed by /health (sandbox); see README "Sandbox instance"
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
require_once __DIR__ . '/MachineAuth.php';
require_once __DIR__ . '/AdminApi.php';
require_once __DIR__ . '/Webhooks.php';

use FluxFiles\LicenseServer\LicenseSigner;
use FluxFiles\LicenseServer\LicenseStore;
use FluxFiles\LicenseServer\LicenseIssuer;
use FluxFiles\LicenseServer\PolarWebhook;
use FluxFiles\LicenseServer\ProDownload;
use FluxFiles\LicenseServer\MachineAuth;
use FluxFiles\LicenseServer\AdminApi;
use FluxFiles\LicenseServer\Webhooks;
use FluxFiles\LicenseServer\Client\Mask;
use FluxFiles\LicenseServer\Client\Config;

header('Content-Type: application/json; charset=utf-8');
// Responses can carry licence keys (/claim, /issue, /licenses): never cache them.
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$raw = file_get_contents('php://input') ?: '';

function respond(int $code, array $body): void
{
    if (isset($GLOBALS['store']) && $GLOBALS['store'] instanceof LicenseStore) {
        $path = $GLOBALS['uri'] ?? '';
        $kind = ['/claim'=>'claim','/pro-download'=>'pro_download','/refresh'=>'refresh'][$path] ?? null;
        if ($kind !== null && ($GLOBALS['method'] ?? '') !== 'OPTIONS') {
            $jti = (string) ($GLOBALS['activityJti'] ?? '');
            $result = $code === 429 ? 'rate_limited' : ($code < 300 ? ($body['status'] ?? 'ok') : ($code === 410 ? 'expired' : ($code === 401 ? 'invalid' : 'refused')));
            $GLOBALS['store']->recordActivity($kind, $result, $jti);
        }
    }
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
    (new Webhooks($store, static fn () => null))->cascadeNotice($store, $origin);
}
function env(string $k, string $d = ''): string { $v = getenv($k); return $v === false || $v === '' ? $d : $v; }

/** How long after purchase /claim will still hand back the key (see the endpoint). */
const CLAIM_WINDOW = 3600;

try {
    if ($uri === '/health') {
        // FLUXFILES_LICENSE_INSTANCE (e.g. "sandbox") tells instances apart; unset keeps
        // the exact {"ok":true} body that deploy self-checks compare against.
        $instance = trim((string) getenv('FLUXFILES_LICENSE_INSTANCE'));
        respond(200, $instance === '' ? ['ok' => true] : ['ok' => true, 'instance' => $instance]);
    }

    if (!MachineAuth::poolAllows($uri)) { respond(404, ['error' => 'not_found']); }
    $protected = str_starts_with($uri, '/admin/') || in_array($uri, ['/issue','/licenses','/revoke','/redeliver','/update-status'], true);
    $role = $protected ? MachineAuth::role($_SERVER) : '';
    if ($protected && !MachineAuth::allowed($role, $uri)) {
        respond($role === 'ops' ? 403 : 401, ['error' => $role === 'ops' ? 'forbidden_scope' : 'unauthorized']);
    }
    if ($protected && MachineAuth::viewerDenied($role,$method,$uri,$_SERVER)) { respond(403,['error'=>'forbidden_scope']); }
    // The status and read-only routes boot without even reading the signing-key env.
    $signer = static function (): LicenseSigner { static $instance; return $instance ??= new LicenseSigner(); };
    $store = new LicenseStore();
    if ($protected) { $store->setActor(MachineAuth::actor($role, $_SERVER)); }
    $issuer = static fn (): LicenseIssuer => new LicenseIssuer($signer(), $store);
    $api = new AdminApi($store, $role, $issuer);
    if (str_starts_with($uri, '/admin/')) {
        if (strlen($raw) > ($uri === '/admin/ops/ingest' ? 262144 : 65536)) { respond(400, ['error' => 'invalid_input']); }
        $input = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($input)) { respond(400, ['error' => 'invalid_input']); }
        [$status, $out] = $api->handle($method, $uri, $input, $_GET);
        if ($uri === '/admin/export/orders.csv' && $status === 200 && isset($out['csv'])) {
            header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename=fluxfiles-orders.csv');
            echo Mask::safe($out['csv']); exit;
        }
        if (!str_ends_with($uri, '/reveal')) { $out = Mask::row($out); }
        respond($status, $out);
    }

    // ── Polar webhook (Standard Webhooks) ────────────────────────────────────
    if ($method === 'POST' && $uri === '/webhook/polar') {
        if (strlen($raw) > 1048576) { respond(400, ['error' => 'invalid_input']); }
        [$code, $out] = (new Webhooks($store, $issuer))->polar($raw, $_SERVER);
        respond($code, $out);
    }
    if ($method === 'POST' && $uri === '/webhook/resend') {
        if (strlen($raw) > 65536) { respond(400, ['error' => 'invalid_input']); }
        [$code, $out] = (new Webhooks($store, $issuer))->resend($raw, $_SERVER);
        respond($code, $out);
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
        $activityJti = (string) $rec['jti'];
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
            $store->recordDownload('fluxfiles-pro', 'pro_link_claim', (string) ($dl['version'] ?? ''));
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
        $activityJti = (string) $rec['jti'];
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
        $store->recordDownload('fluxfiles-pro', 'pro_link_download', (string) ($dl['version'] ?? ''));
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
        $key = preg_match('/^Bearer\s+(\S+)\s*$/i', $auth, $bm) === 1 ? $bm[1] : '';
        $public = Config::get('FLUXFILES_LICENSE_K1_PUB');
        $payload = $key !== '' ? ($public !== '' ? LicenseSigner::verifyPublic($key, $public) : $signer()->verify($key)) : null;
        if ($payload === null) {
            respond(401, ['error' => 'invalid key']);
        }
        $b = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($b)) {
            respond(400, ['error' => 'invalid request']);
        }
        $client = preg_match('/^[a-z0-9.\/-]{1,64}$/', (string) ($b['client'] ?? '')) === 1 ? (string) $b['client'] : '-';

        $jti = (string) $payload['jti'];
        $activityJti = $jti;
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
        $b = json_decode($raw, true) ?: [];
        $jti = (string) ($b['jti'] ?? '');
        if (!preg_match('/^[a-f0-9]{24}$/', $jti)) { respond(400, ['error' => 'invalid jti']); }
        $active = $store->isUpdateEligible($jti);
        echo json_encode(['active' => $active]);
        if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
        $store->recordInstallPing($jti, $b, $active);
        exit;
    }

    // ── Admin: manual issue ──────────────────────────────────────────────────
    if ($method === 'POST' && $uri === '/issue') {
        $b = json_decode($raw, true) ?: [];
        $delivery = $b['deliver'] ?? 'none';
        if (!in_array($delivery, ['none','manual','mail'], true) || !is_string($b['note'] ?? '') || mb_strlen($b['note'] ?? '') > 500) { respond(400, ['error' => 'invalid_input']); }
        Mask::safe((string) ($b['note'] ?? ''));
        try {
            $res = $issuer()->issue([
                'email' => (string) ($b['email'] ?? ''), 'plan' => (string) ($b['plan'] ?? ''),
                'customer' => (string) ($b['customer'] ?? ''), 'gateway' => 'manual',
                'is_test' => $b['is_test'] ?? false, 'note' => $b['note'] ?? null,
                'order_id' => (string) ($b['order_id'] ?? ('manual-' . bin2hex(random_bytes(6)))),
            ]);
        } catch (\InvalidArgumentException $e) {
            respond(400, ['error' => $e->getMessage()]);   // bad email / unknown plan, for the admin
        }
        $mailed = $delivery === 'manual' || ($delivery === 'mail' && $api->mailer($res['record']['jti'])->sendLicense($res['record']));
        if ($mailed) { $store->markMailed($res['record']['jti']); }
        $store->audit('licence.issue', $res['record']['jti']);
        $res['record'] = $store->findByJti($res['record']['jti']);
        respond(200, $role === 'ui' ? ['record' => Mask::row($res['record']), 'mailed' => $mailed] : ['license_key' => $res['key'], 'record' => $res['record'], 'mailed' => $mailed]);
    }

    // ── Admin: list / lookup ─────────────────────────────────────────────────
    if ($method === 'GET' && $uri === '/licenses') {
        $email = (string) ($_GET['email'] ?? '');
        $rows = $email !== '' ? $store->findByEmail($email) : $store->all();
        $store->audit('key.bulk_read');
        respond(200, ['licenses' => $role === 'ui' ? array_map([Mask::class, 'row'], $rows) : $rows]);
    }

    // ── Admin: re-send undelivered licences ──────────────────────────────────
    //
    // The recovery path for a mail outage. The webhook answers 200 even when delivery
    // fails (a 5xx would disable the endpoint after ten consecutive failures, and an
    // outage fails every order), so the backlog lands here instead. Safe to run at any
    // time: it only touches licences with no mailed_at, and marks each as it goes.
    if ($method === 'POST' && $uri === '/redeliver') {
        $mailer = $api->mailer();
        $b = json_decode($raw, true) ?: [];
        $limit = max(1, min($role === 'ui' ? 10 : 100, (int) ($b['limit'] ?? 100)));
        $sent = 0; $failed = 0; $deliveryDeadline = microtime(true) + 20;
        foreach ($store->undelivered($limit) as $rec) {
            $remainingSeconds = (int)floor($deliveryDeadline-microtime(true)); if ($remainingSeconds < 1) { break; }
            $mailer = $api->mailer((string) $rec['jti']); $mailer->setTimeout(min(15,$remainingSeconds));
            if ($mailer->sendLicense($rec)) {
                $store->markMailed((string) $rec['jti']);
                $sent++;
            } else {
                $failed++;   // stays in the backlog for the next run
            }
        }
        $remaining = (int) $store->pdo()->query("SELECT COUNT(*) FROM licenses WHERE mailed_at IS NULL AND status='active' AND gateway IS NOT 'manual'")->fetchColumn();
        $store->audit('mail.redeliver');
        respond(200, ['sent' => $sent, 'failed' => $failed, 'remaining' => $remaining]);
    }

    // ── Admin: revoke / refund ───────────────────────────────────────────────
    if ($method === 'POST' && $uri === '/revoke') {
        $b = json_decode($raw, true) ?: [];
        $status = (string) ($b['status'] ?? 'revoked');
        // `superseded` is the store's own bookkeeping and stays update-eligible, so
        // setting it (or anything unknown) by hand would silently un-revoke a key.
        if (!in_array($status, ['revoked', 'refunded', 'active'], true)) {
            respond(400, ['error' => 'status must be revoked, refunded or active']);
        }
        // {"subscription_id": ...} revokes every key of one subscription, so none of them can refresh.
        $sid = (string) ($b['subscription_id'] ?? '');
        if ($sid !== '') {
            if (preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $sid) !== 1) { respond(400, ['error' => 'invalid subscription_id']); }
            $before = $store->pdo()->prepare('SELECT jti,plan,status FROM licenses WHERE subscription_id=?');
            $before->execute([$sid]); $previous = $before->fetchAll();
            $n = $store->transaction(function () use ($store, $sid, $status): int {
                $n = $store->setStatusForSubscription($sid, $status);
                $store->audit('subscription.revoke', $sid, 'ok', null, ['status' => $status]);
                return $n;
            });
            error_log("license revoke: subscription {$sid} -> {$status} ({$n} row(s))");
            $changed = [];
            foreach ($previous as $row) { if (($store->findByJti($row['jti'])['status'] ?? null) !== $row['status']) { $changed[] = ['jti' => $row['jti'], 'plan' => $row['plan']]; } }
            $out = ['updated' => $n > 0, 'rows' => $n, 'cascaded' => $changed];
            $head = $status === 'active' ? $store->newestForSubscription($sid) : null;
            if ($head !== null && (string) $head['status'] === 'active' && empty($head['mailed_at'])) {
                // Orders paid while it was revoked were recorded but never mailed.
                $out['note'] = 'the newest key was never mailed: run POST /redeliver to send it';
                $api->mailer()->sendOpsNotice('FluxFiles: subscription restored, newest key not mailed',
                    "Subscription {$sid} was restored. Its newest licence ({$head['jti']}) was recorded while revoked and never mailed: run POST /redeliver to send it.");
            }
            respond($n > 0 ? 200 : 404, $out);
        }
        $ok = $store->transaction(function () use ($store, $b, $status): bool {
            $ok = $store->setStatusCascade((string) ($b['jti'] ?? ''), $status);
            $store->audit($status === 'active' ? 'licence.restore' : 'licence.revoke', (string) ($b['jti'] ?? ''), $ok ? 'ok' : 'not_found');
            return $ok;
        });
        reportCascade($store, "{$status} licence " . (string) ($b['jti'] ?? ''));
        respond($ok ? 200 : 404, ['updated' => $ok, 'cascaded' => array_map(static fn (array $r): array => ['jti' => $r['jti'], 'plan' => $r['plan']], $store->lastCascade())]);
    }

    respond(404, ['error' => 'not found']);
} catch (\Throwable $e) {
    // Log the detail for the operator; the caller (possibly unauthenticated) gets
    // nothing about the signer, the database or the input that broke.
    error_log('license server: '.get_class($e).' · '.mb_substr(Mask::clean(str_replace(["\r","\n"], ' ', $e->getMessage())),0,400));
    respond(500, ['error' => 'internal error']);
}
