<?php

declare(strict_types=1);

namespace FluxFiles;

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- operator configuration read from the environment/server (not user input); core is a WordPress-independent library

/**
 * The `Content-Security-Policy: frame-ancestors …` value for pages FluxFiles serves.
 * One implementation for `/public/index.html` and the public module pages, so the two
 * cannot drift. Precedence:
 *   1. FLUXFILES_FRAME_ANCESTORS: explicit source list, verbatim.
 *   2. FLUXFILES_ALLOWED_ORIGINS: the hosts already trusted for CORS, plus 'self'.
 *   3. neither set: no header (unchanged behaviour).
 * X-Frame-Options is deliberately not sent alongside: it has no multi-origin form.
 */
final class FrameAncestors
{
    /**
     * @param string[]|null $allowedOrigins already-parsed origins; null = read FLUXFILES_ALLOWED_ORIGINS
     * @return string|null the header VALUE (`frame-ancestors …`), or null when none applies
     */
    public static function policy(?array $allowedOrigins = null): ?string
    {
        if ($allowedOrigins === null) {
            $allowedOrigins = array_filter(array_map('trim', explode(',', (string) ($_ENV['FLUXFILES_ALLOWED_ORIGINS'] ?? ''))));
        }
        $value = trim((string) ($_ENV['FLUXFILES_FRAME_ANCESTORS'] ?? ''));
        if ($value === '' && $allowedOrigins !== []) {
            $value = "'self' " . implode(' ', $allowedOrigins);
        }
        if ($value !== '' && preg_match('/^[A-Za-z0-9 :\/\.\*\-\']+$/', $value)) {
            return 'frame-ancestors ' . $value;
        }
        return null;
    }
}
