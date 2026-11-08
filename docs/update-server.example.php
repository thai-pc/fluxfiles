<?php

/**
 * FluxFiles — reference UPDATE SERVER (self-hosted, ~1 file).
 *
 * The whole "license → auto-download/update" channel without any third-party
 * platform fee. Pair it with:
 *   - WordPress: the FluxFiles Pro plugin's in-house updater (ProUpdater) calls
 *     /update/fluxfiles-pro with the license in the Authorization header and verifies the
 *     signed manifest + sha256 → updates show up in wp-admin with a one-click "Update now".
 *   - Laravel / standalone: `php vendor/bin/fluxfiles update <module>` (UpdateClient).
 *
 * Flow:
 *   GET /update/{module}?current=X.Y.Z[&core_version=A.B.C] + Authorization: Bearer <license>
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

use FluxFiles\CoreVersion;
use FluxFiles\LicenseManager;

/**
 * Pick the release to offer a client.
 *
 * A catalogue entry is the latest release plus, optionally, `releases` (older entries,
 * newest first — `scripts/pack-modules.php` maintains it). Each carries `requires_core`,
 * the module's composer constraint on fluxfiles/fluxfiles. A module built for a newer
 * core fatals on load against an older one (missing interface), so we must not offer it.
 *
 *   - Client sent `core_version` (core >= 1.0.6): serve the newest release whose
 *     `requires_core` that version satisfies; null when none does.
 *   - Client sent none (core <= 1.0.5 cannot, nor can any 0.2.x core): it has not
 *     proven its version, so it is treated as core 1.0.3. That is a floor, not a claim:
 *     a 0.2.x client is also treated as 1.0.3 and may be offered a build it
 *     cannot host. A release whose floor is
 *     above `^1.0.3` (e.g. share 1.4.0 needing `^1.0.4`) is NEVER offered to it.
 *   - A release with no `requires_core` is compatible with everyone.
 *   - `$latest` (a first install of a plugin bundle, see $BUNDLES): there is no installed core
 *     to compare against yet, so the newest release is served and the signed manifest's
 *     `requires_core` is what the client's own boot guard / updater checks.
 *
 * @param array<string,mixed> $entry
 * @return array<string,mixed>|null
 */
function fluxfiles_pick_release(array $entry, ?string $coreVersion, bool $latest = false): ?array
{
    $assumed = $coreVersion !== null && CoreVersion::normalize($coreVersion) !== null
        ? $coreVersion
        : '1.0.3';
    $candidates = array_merge([array_diff_key($entry, ['releases' => 1])], array_values($entry['releases'] ?? []));
    $best = null;
    foreach ($candidates as $c) {
        if (!is_array($c) || ($c['version'] ?? '') === '' || ($c['sha256'] ?? '') === '' || ($c['zip'] ?? '') === '') {
            continue;
        }
        $need = (string) ($c['requires_core'] ?? '');
        if (!$latest && $need !== '' && !CoreVersion::satisfies($assumed, $need)) {
            continue;
        }
        if ($best === null || version_compare(ltrim((string) $c['version'], 'vV'), ltrim((string) $best['version'], 'vV'), '>')) {
            $best = $c;
        }
    }
    return $best;
}

/**
 * Plugin bundle id => the module ids it ships. The ONE place this lives on the server side;
 * services/license-server ProDownload::BUNDLE_MODULES must agree (a test asserts it).
 * Phase 2 of the Pro add-on adds 'versioning','webhooks','ai','ocr'.
 *
 * @return array<string,list<string>>
 */
function fluxfiles_bundles(): array
{
    return ['fluxfiles-pro' => ['share', 'intake']];
}

/**
 * Is this licence entitled to `$module`?
 *
 * A plugin BUNDLE (one zip that carries several modules, e.g. the FluxFiles Pro WordPress
 * add-on) is not a licence module: no plan grants 'fluxfiles-pro', so `licensed()` is false
 * for every real key. A bundle is entitled when the licence covers ANY bundled module;
 * the updatesAllowed() window and the /update-status revocation check stay the caller's
 * and apply to a bundle exactly as they do to a module. A plan with `modules: []`
 * (support) covers nothing, so it is never entitled.
 *
 * @param array<string,list<string>> $bundles bundle id => the module ids it ships
 */
function fluxfiles_entitled(string $module, LicenseManager $lm, array $bundles): bool
{
    if (!isset($bundles[$module])) {
        return $lm->licensed($module);
    }
    foreach ($bundles[$module] as $bundled) {
        if ($lm->licensed($bundled)) {
            return true;
        }
    }
    return false;
}

if (defined('FLUXFILES_UPDATE_SERVER_FUNCTIONS_ONLY')) {
    return; // tests load the helper above without running the request
}

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

// Modules served WITHOUT a licence check. The WordPress plugin is MIT and ships as
// a ZIP rather than through wordpress.org, so this channel is its only way to learn
// that a new version — including a security release — exists. Gating that behind a
// paid key would withhold security fixes from a free product, and could not work
// anyway: no plan in services/license-server/Plans.php grants 'wordpress', so
// LicenseManager::licensed('wordpress') is false for every key that exists.
//
// Integrity is unaffected: the manifest below is still signed with the RELEASE key
// and UpdateClient still re-hashes the download. What is skipped is *entitlement*,
// not verification.
$FREE_MODULES = ['wordpress'];

// Plugin bundles: entitled when the licence covers ANY bundled module (same
// updatesAllowed() and /update-status revocation checks as a module). See
// fluxfiles_entitled(). Phase 2 of the Pro add-on adds 'versioning','webhooks','ai','ocr'.
$BUNDLES = fluxfiles_bundles();

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

// 1. Verify the license (offline, reusing the core's verifier) — unless the module
//    is free, in which case there is nothing to entitle and we go straight to the
//    signed manifest.
if (!in_array($module, $FREE_MODULES, true)) {
    $lm = new LicenseManager($license);
    if (!fluxfiles_entitled($module, $lm, $BUNDLES)) {
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
}   // end paid-module gate

// 3. Pick a release this client's core can host, then build + sign the manifest.
//    `core_version` is advisory (the client reports it); it only narrows what we offer.
$coreVersion = is_string($_GET['core_version'] ?? null) ? CoreVersion::normalize($_GET['core_version']) : null;
// `install=1` (bundles only): a first install has no core to report, so offer the newest.
$latest = isset($BUNDLES[$module]) && ($_GET['install'] ?? '') === '1';
$entry = fluxfiles_pick_release($CATALOGUE[$module], $coreVersion, $latest);
if ($entry === null) {
    http_response_code(409);
    exit('no release of this module is compatible with core ' . ($coreVersion ?? '(unreported, assumed 1.0.3)') . ' - update core first');
}
$payload = [
    'module'  => $module,
    'version' => $entry['version'],
    'url'     => $CDN_BASE . '/' . $entry['zip'],
    'sha256'  => $entry['sha256'],
    'expires' => time() + 3600, // short replay window; the client re-checks each run
];
// The core this release needs. UpdateClient re-checks it before installing (the manifest is
// signed, so it can't be stripped in transit).
if (!empty($entry['requires_core'])) {
    $payload['requires_core'] = (string) $entry['requires_core'];
}
// Optional PHP floor for this release (the WordPress catalogue entry carries one), so
// the plugin's updater can hold the update back on an older PHP.
if (!empty($entry['requires_php'])) {
    $payload['requires_php'] = (string) $entry['requires_php'];
}

$b64url = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$header = $b64url((string) json_encode(['alg' => 'Ed25519', 'kid' => $RELEASE_KID]));
$body   = $b64url((string) json_encode($payload));
$sig    = $b64url(sodium_crypto_sign_detached($header . '.' . $body, $RELEASE_PRIVATE_KEY));

echo $header . '.' . $body . '.' . $sig;
