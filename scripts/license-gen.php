#!/usr/bin/env php
<?php

/**
 * FluxFiles — LICENSE GENERATOR (vendor-only, NOT shipped to customers).
 *
 * Signs an Ed25519 license token that `LicenseManager` (in the MIT core) verifies
 * offline. Run it on YOUR machine; the private key never leaves it / never ships.
 *
 * Lives in scripts/ (export-ignore → excluded from the Composer/npm dist the
 * customer downloads), but stays in the repo so it's testable + maintained.
 *
 * First-time setup — make a signing keypair:
 *   php scripts/license-gen.php --genkey
 *   → prints PUBLIC (embed in LicenseManager::PUBLIC_KEYS) + SECRET (keep offline)
 *
 * Mint a license:
 *   FLUXFILES_LICENSE_PRIVATE_KEY=<base64-secret> \
 *   php scripts/license-gen.php \
 *     --customer="Acme Co" --edition=pro --modules=optimize,share \
 *     --enforcement=perpetual --expires=+365d --kid=k1
 *   → prints the license token (the FLUXFILES_LICENSE_KEY you give the customer)
 *
 * Flags:
 *   --customer=NAME        free-text label (not verified, for your records)
 *   --edition=free|pro|agency|enterprise   (default pro)
 *   --modules=a,b          comma list of licensed module ids
 *   --enforcement=perpetual|subscription   (default perpetual)
 *   --expires=+365d|+30d|none|<unixts>     (none = lifetime/no expiry; default +365d)
 *   --grace=14d            grace window after expiry (default 14d; subscription only)
 *   --kid=k1               signing key id (must match LicenseManager + the secret)
 *   --jti=HEX24            pin the licence id (default: random) — record it, the
 *                          update server asks the licence server about this id
 *   --key=PATH             read base64 secret from file (else env FLUXFILES_LICENSE_PRIVATE_KEY)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "ext-sodium is required\n");
    exit(1);
}

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}

$b64url = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

// ── --genkey: make a fresh signing keypair ──────────────────────────────────
if (isset($opts['genkey'])) {
    $kp  = sodium_crypto_sign_keypair();
    $pub = base64_encode(sodium_crypto_sign_publickey($kp));
    $sec = base64_encode(sodium_crypto_sign_secretkey($kp));
    fwrite(STDOUT, "PUBLIC (embed in LicenseManager::PUBLIC_KEYS['k1']):\n  {$pub}\n\n");
    fwrite(STDOUT, "SECRET (KEEP OFFLINE — never commit; pass via --key / FLUXFILES_LICENSE_PRIVATE_KEY):\n  {$sec}\n");
    exit(0);
}

// ── load the private key ────────────────────────────────────────────────────
$secB64 = '';
if (isset($opts['key']) && is_string($opts['key'])) {
    $raw = @file_get_contents($opts['key']);
    if ($raw === false) {
        fwrite(STDERR, "Cannot read key file: {$opts['key']}\n");
        exit(1);
    }
    $secB64 = trim($raw);
} else {
    $secB64 = trim((string) (getenv('FLUXFILES_LICENSE_PRIVATE_KEY') ?: ''));
}
if ($secB64 === '') {
    fwrite(STDERR, "No signing key. Use --genkey first, then --key=PATH or FLUXFILES_LICENSE_PRIVATE_KEY.\n");
    exit(1);
}
$secret = base64_decode($secB64, true);
if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "Invalid secret key (must be base64 of a 64-byte Ed25519 secret key).\n");
    exit(1);
}

// ── build the payload ───────────────────────────────────────────────────────
$edition = is_string($opts['edition'] ?? null) ? $opts['edition'] : 'pro';
$modules = [];
if (!empty($opts['modules']) && is_string($opts['modules'])) {
    $modules = array_values(array_filter(array_map('trim', explode(',', $opts['modules']))));
}
$enforcement = (($opts['enforcement'] ?? 'perpetual') === 'subscription') ? 'subscription' : 'perpetual';
$kid = is_string($opts['kid'] ?? null) ? $opts['kid'] : 'k1';

// expires: +Nd | none | <unixts>
$expVal = (string) ($opts['expires'] ?? '+365d');
$expires = null;
if ($expVal !== 'none' && $expVal !== '') {
    if (preg_match('/^\+(\d+)d$/', $expVal, $m)) {
        $expires = time() + ((int) $m[1]) * 86400;
    } elseif (ctype_digit($expVal)) {
        $expires = (int) $expVal;
    } else {
        fwrite(STDERR, "Bad --expires (use +365d, none, or a unix timestamp).\n");
        exit(1);
    }
}

$graceVal = (string) ($opts['grace'] ?? '14d');
$grace = preg_match('/^(\d+)d$/', $graceVal, $gm) ? ((int) $gm[1]) * 86400 : 14 * 86400;

// A caller may pin the id (re-minting a replacement key for an existing customer
// record, so the update channel keeps recognising them); otherwise mint a fresh one.
$jti = is_string($opts['jti'] ?? null) ? strtolower(trim($opts['jti'])) : bin2hex(random_bytes(12));
if (!preg_match('/^[a-f0-9]{24}$/', $jti)) {
    fwrite(STDERR, "Bad --jti (expected 24 lowercase hex characters).\n");
    exit(1);
}

$payload = [
    'customer'    => is_string($opts['customer'] ?? null) ? $opts['customer'] : '',
    'edition'     => $edition,
    'modules'     => $modules,
    'enforcement' => $enforcement,
    'issued'      => time(),
    // Unique licence id. NOT optional: the update server refuses to serve a build
    // for a key that carries none (it has nothing to ask the licence server about
    // for the refund/revoke check), so a key minted without one verifies fine and
    // is then permanently stuck on 503 at the update channel. Must match
    // LicenseManager::id()'s 24-hex shape, and how LicenseSigner mints it.
    'jti'         => $jti,
];
if ($expires !== null) {
    $payload['expires'] = $expires;
    $payload['grace'] = $grace;
}
// ── sign ────────────────────────────────────────────────────────────────────
$header = $b64url((string) json_encode(['alg' => 'Ed25519', 'kid' => $kid]));
$body   = $b64url((string) json_encode($payload));
$sig    = $b64url(sodium_crypto_sign_detached($header . '.' . $body, $secret));

// The token on stdout, so the docs' `php scripts/license-gen.php … > key.txt` still
// works; the id on stderr, because you have to record it somewhere to revoke later.
fwrite(STDERR, "jti: {$jti}\n");
fwrite(STDOUT, $header . '.' . $body . '.' . $sig . "\n");
