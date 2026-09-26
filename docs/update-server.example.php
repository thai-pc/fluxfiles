<?php

/**
 * FluxFiles — reference UPDATE SERVER (self-hosted, ~1 file).
 *
 * The whole "license → auto-download/update" channel without any third-party
 * platform fee. Pair it with:
 *   - WordPress: yahnis-elsts/plugin-update-checker (send the license in an
 *     Authorization header) → updates show up in wp-admin with a one-click "Update now".
 *   - Laravel / standalone: `php vendor/bin/fluxfiles update <module>` (UpdateClient).
 *
 * Flow:
 *   GET /update/{module}?current=X.Y.Z + Authorization: Bearer <license>
 *     → verify the license with the SAME Ed25519 LicenseManager the core ships,
 *       then ask the licence server whether it remains active (refund/revoke check)
 *     → return a SIGNED manifest token {module,version,url,sha256,expires} signed
 *       with the RELEASE private key (whose public key is embedded in UpdateClient)
 *
 * Keep TWO private keys offline (never in any repo):
 *   - LICENSE signing key  → mints customer license keys (LicenseManager pubkey).
 *   - RELEASE signing key   → signs these manifests (UpdateClient pubkey 'r1').
 *
 * This file is documentation, not shipped runtime. Adapt to your host (plain PHP,
 * a Cloudflare Worker, etc.). Serve the zips from anywhere (CDN / private GitHub
 * release / object storage) — integrity is anchored by the signed sha256.
 */

declare(strict_types=1);

require __DIR__ . '/../packages/core/vendor/autoload.php';

use FluxFiles\LicenseManager;

// ── config (env / secrets manager) ──────────────────────────────────────────
$RELEASE_PRIVATE_KEY = base64_decode((string) getenv('FLUXFILES_RELEASE_PRIVATE_KEY'), true); // 64 bytes
$RELEASE_KID         = 'r1';
$CDN_BASE            = rtrim((string) (getenv('FLUXFILES_CDN_BASE') ?: 'https://cdn.example.com/modules'), '/');
$LICENSE_STATUS_URL  = rtrim((string) getenv('FLUXFILES_LICENSE_STATUS_URL'), '/');
$LICENSE_STATUS_TOKEN = (string) getenv('FLUXFILES_UPDATE_STATUS_TOKEN');

// Your release catalogue: latest version + checksum per module, in the shape
//   ['<module>' => ['version' => '1.0.0', 'zip' => 'share-1.0.0.zip', 'sha256' => '…']]
//
// `php scripts/pack-modules.php` generates exactly this, from each module repo's own
// git tag, and writes it to build/modules/catalogue.json alongside the zips. Serve the
// SAME bytes it hashed: UpdateClient re-hashes the download and refuses a mismatch, so
// a rebuilt-but-not-rehashed zip breaks every install.
$CATALOGUE_FILE = getenv('FLUXFILES_CATALOGUE') ?: __DIR__ . '/../build/modules/catalogue.json';
$CATALOGUE = is_file($CATALOGUE_FILE)
    ? (json_decode((string) file_get_contents($CATALOGUE_FILE), true) ?: [])
    : [];

// ── request ─────────────────────────────────────────────────────────────────
$module  = preg_replace('/[^a-z0-9-]/', '', (string) ($_GET['module'] ?? basename($_SERVER['PATH_INFO'] ?? '')));
// The licence arrives as a bearer credential in the header, never as a query
// parameter — a URL ends up in access logs, proxy logs and the Referer of the next
// hop. Apache+CGI/FastCGI strips `Authorization` unless it is passed through
// (`SetEnvIf Authorization ... ` / `CGIPassAuth On`), and then re-exposes it under
// REDIRECT_; accept that spelling too, or every client silently reads as unlicensed.
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$license = preg_match('/^Bearer\s+(.+)$/i', $auth, $m) ? trim($m[1]) : '';

header('Content-Type: text/plain; charset=utf-8');

if ($module === '' || !isset($CATALOGUE[$module])) {
    http_response_code(404);
    exit('unknown module');
}

// 1. Verify the license (offline, reusing the core's verifier).
$lm = new LicenseManager($license);
if (!$lm->licensed($module)) {
    http_response_code(402);
    exit('license does not entitle this module');
}
// 2. Updates only while the support/update window is open (perpetual installs keep
//    running their current build; they just can't pull new ones until they renew).
if (!$lm->updatesAllowed()) {
    http_response_code(402);
    exit('update window expired — renew to pull new builds');
}
// Revocation is intentionally checked only here, never at runtime: installed
// self-hosted modules remain usable offline, but a refunded/revoked key cannot
// fetch another build. The licence server authenticates this private endpoint
// with a distinct machine credential, not the broad admin token.
if ($LICENSE_STATUS_URL === '' || $LICENSE_STATUS_TOKEN === '' || $lm->id() === null) {
    http_response_code(503);
    exit('license status service is not configured');
}
$statusRequest = curl_init($LICENSE_STATUS_URL . '/update-status');
curl_setopt_array($statusRequest, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['jti' => $lm->id()]),
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $LICENSE_STATUS_TOKEN, 'Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);
$statusBody = curl_exec($statusRequest);
$statusCode = (int) curl_getinfo($statusRequest, CURLINFO_HTTP_CODE);
curl_close($statusRequest);
$status = is_string($statusBody) ? json_decode($statusBody, true) : null;
if ($statusCode !== 200 || !is_array($status) || ($status['active'] ?? false) !== true) {
    http_response_code($statusCode === 200 ? 402 : 503);
    exit($statusCode === 200 ? 'license revoked or inactive' : 'license status service unavailable');
}

// 3. Build + sign the manifest.
$entry = $CATALOGUE[$module];
$payload = [
    'module'  => $module,
    'version' => $entry['version'],
    'url'     => $CDN_BASE . '/' . $entry['zip'],
    'sha256'  => $entry['sha256'],
    'expires' => time() + 3600, // short replay window; the client re-checks each run
];

$b64url = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$header = $b64url((string) json_encode(['alg' => 'Ed25519', 'kid' => $RELEASE_KID]));
$body   = $b64url((string) json_encode($payload));
$sig    = $b64url(sodium_crypto_sign_detached($header . '.' . $body, $RELEASE_PRIVATE_KEY));

echo $header . '.' . $body . '.' . $sig;
