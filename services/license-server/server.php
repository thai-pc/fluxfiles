<?php

declare(strict_types=1);

/**
 * FluxFiles license issuance server (vendor back-office; NOT part of the stateless
 * core). One front controller:
 *
 *   POST /webhook/lemonsqueezy  — Lemon Squeezy order/subscription webhook (main store)
 *   POST /webhook/freemius      — Freemius webhook (WordPress channel)
 *   POST /issue                 — admin-authed manual issue {email, plan}
 *   GET  /licenses[?email=]     — admin: list / lookup
 *   POST /revoke                — admin: {jti, status} mark revoked/refunded
 *   GET  /health                — liveness
 *
 * Env:
 *   FLUXFILES_LICENSE_PRIVATE_KEY_FILE  path to the 64-byte Ed25519 secret (base64)
 *   FLUXFILES_LICENSE_DB                sqlite path (default ./data/licenses.sqlite)
 *   FLUXFILES_LICENSE_ADMIN_TOKEN       bearer token for /issue,/licenses,/revoke
 *   FLUXFILES_LS_WEBHOOK_SECRET         Lemon Squeezy webhook signing secret
 *   FLUXFILES_LS_PLAN_MAP               JSON {"<variant_id>":"pro", ...}
 *   FLUXFILES_FREEMIUS_SECRET           Freemius webhook secret
 *   FLUXFILES_FREEMIUS_PLAN_MAP         JSON {"<plan_id>":"pro", ...}
 *
 * Run behind any web server, or for dev:  php -S 127.0.0.1:9000 server.php
 */

require_once __DIR__ . '/LicenseSigner.php';
require_once __DIR__ . '/LicenseStore.php';
require_once __DIR__ . '/Plans.php';
require_once __DIR__ . '/LicenseIssuer.php';

use FluxFiles\LicenseServer\LicenseSigner;
use FluxFiles\LicenseServer\LicenseStore;
use FluxFiles\LicenseServer\LicenseIssuer;

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

    // ── Lemon Squeezy webhook ────────────────────────────────────────────────
    if ($method === 'POST' && $uri === '/webhook/lemonsqueezy') {
        $secret = env('FLUXFILES_LS_WEBHOOK_SECRET');
        $sig = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
        if ($secret === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), (string) $sig)) {
            respond(401, ['error' => 'bad signature']);
        }
        $body = json_decode($raw, true);
        $event = $body['meta']['event_name'] ?? '';
        if (!in_array($event, ['order_created', 'subscription_created', 'subscription_payment_success'], true)) {
            respond(200, ['ignored' => $event]); // ack other events
        }
        $attr = $body['data']['attributes'] ?? [];
        $email = (string) ($attr['user_email'] ?? '');
        $orderId = (string) ($body['data']['id'] ?? '');
        $variantId = (string) ($attr['first_order_item']['variant_id'] ?? $attr['variant_id'] ?? '');
        $map = json_decode(env('FLUXFILES_LS_PLAN_MAP', '{}'), true) ?: [];
        $plan = $map[$variantId]
            ?? strtolower((string) ($attr['first_order_item']['variant_name'] ?? $attr['variant_name'] ?? ''));
        $res = $issuer->issue([
            'email' => $email, 'plan' => $plan, 'customer' => (string) ($attr['user_name'] ?? $email),
            'gateway' => 'lemonsqueezy', 'order_id' => $orderId,
        ]);
        // LS can store the key on the order via its License API / an email automation;
        // returning it here lets a fulfillment step pick it up.
        respond(200, ['issued' => !$res['reused'], 'license_key' => $res['key'], 'jti' => $res['record']['jti']]);
    }

    // ── Freemius webhook (WordPress) ─────────────────────────────────────────
    if ($method === 'POST' && $uri === '/webhook/freemius') {
        $secret = env('FLUXFILES_FREEMIUS_SECRET');
        $sig = $_SERVER['HTTP_X_SIGNATURE'] ?? $_SERVER['HTTP_FS_SIGNATURE'] ?? '';
        if ($secret === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), (string) $sig)) {
            respond(401, ['error' => 'bad signature']);
        }
        $body = json_decode($raw, true);
        $type = $body['type'] ?? ($body['event'] ?? '');
        if (!in_array($type, ['license.created', 'subscription.created', 'payment.created'], true)) {
            respond(200, ['ignored' => $type]);
        }
        $obj = $body['objects'] ?? $body['data'] ?? [];
        $email = (string) ($obj['user']['email'] ?? $obj['email'] ?? '');
        $planId = (string) ($obj['plan']['id'] ?? $obj['plan_id'] ?? '');
        $orderId = (string) ($obj['license']['id'] ?? $obj['id'] ?? '');
        $map = json_decode(env('FLUXFILES_FREEMIUS_PLAN_MAP', '{}'), true) ?: [];
        $plan = $map[$planId] ?? strtolower((string) ($obj['plan']['name'] ?? 'pro'));
        $res = $issuer->issue([
            'email' => $email, 'plan' => $plan, 'gateway' => 'freemius', 'order_id' => $orderId,
        ]);
        respond(200, ['issued' => !$res['reused'], 'license_key' => $res['key'], 'jti' => $res['record']['jti']]);
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
