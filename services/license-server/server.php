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
        respond(200, ['issued' => !$res['reused'], 'jti' => $res['record']['jti']]);
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
