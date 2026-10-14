<?php

/**
 * Mint a demo JWT for the landing-screenshot run.
 *
 * Usage: FF_SHOT_SECRET=<secret> php mint.php '<json claims>'
 *
 * The secret is the throwaway one capture.mjs generates per run (never the real
 * FLUXFILES_SECRET); it arrives via env so it never shows up in `ps`. `iat`/`exp`
 * are filled in here; everything else is the raw claim map, exactly as the
 * browser suite's mintTokenWithClaims() builds it.
 */

declare(strict_types=1);

require __DIR__ . '/../../../packages/core/vendor/autoload.php';

$secret = (string) getenv('FF_SHOT_SECRET');
if (strlen($secret) < 32) {
    fwrite(STDERR, "FF_SHOT_SECRET must be set (>= 32 bytes)\n");
    exit(1);
}
$claims = json_decode((string) ($argv[1] ?? '{}'), true);
if (!is_array($claims)) {
    fwrite(STDERR, "claims must be a JSON object\n");
    exit(1);
}

$now = time();
echo FluxFiles\JwtCompat::encode(array_merge(['iat' => $now, 'exp' => $now + 6 * 3600], $claims), $secret);
