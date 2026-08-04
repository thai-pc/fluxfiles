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

        // Issuing is idempotent on (gateway, order_id), which is what makes Polar's
        // at-least-once delivery safe — a retry returns the same key, never a second one.
        $res = $issuer->issue($order + ['gateway' => 'polar']);

        // Deliver the key. Only on a FIRST issue: a retried delivery would otherwise
        // email the buyer the same key again for one purchase. Mail failure never
        // changes the response — the sale succeeded and the record is stored, so a
        // non-2xx here would make Polar retry and the buyer doubt the charge.
        if (!$res['reused']) {
            (new LicenseMailer())->sendLicense($res['record']);
        }
        respond(200, ['issued' => !$res['reused'], 'jti' => $res['record']['jti']]);
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
    if ($method === 'GET' && $uri === '/claim') {
        $orderId = (string) ($_GET['order_id'] ?? $_GET['checkout_id'] ?? '');
        if (strlen($orderId) < 12) {
            respond(404, ['error' => 'not found']);   // too short to be a real id
        }
        $rec = (new LicenseStore())->findByOrder('polar', $orderId);
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
