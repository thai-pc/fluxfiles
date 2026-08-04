<?php

/**
 * FluxFiles — reference UPDATE SERVER (self-hosted, ~1 file).
 *
 * The whole "license → auto-download/update" channel without any third-party
 * platform fee. Pair it with:
 *   - WordPress: yahnis-elsts/plugin-update-checker (append the license key to the
 *     request) → updates show up in wp-admin with a one-click "Update now".
 *   - Laravel / standalone: `php vendor/bin/fluxfiles update <module>` (UpdateClient).
 *
 * Flow:
 *   GET /update/{module}?license=KEY&current=X.Y.Z
 *     → verify the license with the SAME Ed25519 LicenseManager the core ships
 *       (offline, no DB), check it entitles {module} and updates are still allowed
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
$license = (string) ($_GET['license'] ?? '');

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
