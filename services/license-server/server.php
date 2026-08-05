<?php

declare(strict_types=1);

/**
 * FluxFiles license issuance server (vendor back-office; NOT part of the stateless
 * core). One front controller:
 *
 *   POST /webhook/polar         — Polar order webhook (the store)
 *   POST /issue                 — admin-authed manual issue {email, plan}
 *   GET  /licenses[?email=]     — admin: list / lookup
 *   POST /revoke                — admin: {jti, status} mark revoked/refunded
 *   GET  /health                — liveness
 *
 * Env:
 *   FLUXFILES_LICENSE_PRIVATE_KEY_FILE  path to the 64-byte Ed25519 secret (base64)
 *   FLUXFILES_LICENSE_DB                sqlite path (default ./data/licenses.sqlite)
 *   FLUXFILES_LICENSE_ADMIN_TOKEN       bearer token for /issue,/licenses,/revoke
 *   FLUXFILES_POLAR_WEBHOOK_SECRET      Polar webhook signing secret (whsec_…)
 *   FLUXFILES_POLAR_PLAN_MAP            JSON {"<product_id>":"pro", ...}
 *
 * Run behind any web server, or for dev:  php -S 127.0.0.1:9000 server.php
 */

require_once __DIR__ . '/LicenseSigner.php';
require_once __DIR__ . '/LicenseStore.php';
require_once __DIR__ . '/Plans.php';
require_once __DIR__ . '/LicenseIssuer.php';
require_once __DIR__ . '/PolarWebhook.php';
require_once __DIR__ . '/LicenseMailer.php';

use FluxFiles\LicenseServer\LicenseMailer;
use FluxFiles\LicenseServer\LicenseSigner;
use FluxFiles\LicenseServer\LicenseStore;
use FluxFiles\LicenseServer\LicenseIssuer;
use FluxFiles\LicenseServer\PolarWebhook;

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$raw = file_get_contents('php://input') ?: '';

function respond(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
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

try {
    if ($uri === '/health') {
        respond(200, ['ok' => true]);
    }

    $issuer = new LicenseIssuer(new LicenseSigner(), new LicenseStore());

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
        $res = $issuer->issue($order + ['gateway' => 'polar']);

        // Deliver the key, keyed on whether it has actually been delivered — NOT on
        // whether this is a first delivery. Those differ in the case that matters: if
        // the mail fails after the licence is stored, every later retry is `reused` and
        // would skip sending, leaving a paying customer with no key and nothing in any
        // log to say so. Tracking mailed_at makes Polar's retry the recovery mechanism.
        $jti = (string) $res['record']['jti'];
        if (empty($res['record']['mailed_at'])) {
            if ((new LicenseMailer())->sendLicense($res['record'])) {
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
        $age = time() - (int) ($rec['created_at'] ?? 0);   // stored as a unix int
        if ($age > CLAIM_WINDOW) {
            respond(410, ['error' => 'claim window expired', 'hint' => 'the key was emailed to you']);
        }
        respond(200, [
            'license_key' => (string) $rec['license_key'],
            'edition' => (string) $rec['edition'],
            'expires' => $rec['expires'] ?? null,
        ]);
    }

    // ── Admin: manual issue ──────────────────────────────────────────────────
    if ($method === 'POST' && $uri === '/issue') {
        if (!adminOk()) { respond(401, ['error' => 'unauthorized']); }
        $b = json_decode($raw, true) ?: [];
        $res = $issuer->issue([
            'email' => (string) ($b['email'] ?? ''), 'plan' => (string) ($b['plan'] ?? ''),
            'customer' => (string) ($b['customer'] ?? ''), 'gateway' => 'manual',
            'order_id' => (string) ($b['order_id'] ?? ('manual-' . bin2hex(random_bytes(6)))),
            'sites' => (int) ($b['sites'] ?? 0), 'domains' => (array) ($b['domains'] ?? []),
        ]);
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
        $ok = (new LicenseStore())->setStatus((string) ($b['jti'] ?? ''), (string) ($b['status'] ?? 'revoked'));
        respond($ok ? 200 : 404, ['updated' => $ok]);
    }

    respond(404, ['error' => 'not found']);
} catch (\Throwable $e) {
    respond(500, ['error' => $e->getMessage()]);
}
