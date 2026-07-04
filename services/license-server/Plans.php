<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

/**
 * Maps a plan id (your product/variant slug) → the license payload shape
 * (edition, modules, sites, ttl, enforcement). This is the single place that
 * encodes what each tier unlocks — mirror it in your checkout products.
 *
 * Override/extend via a JSON file at FLUXFILES_LICENSE_PLANS (same shape).
 */
final class Plans
{
    /** @var array<string,array<string,mixed>> */
    private const DEFAULT = [
        // Pro = the two hero paid products (Share + Intake), annual, unlimited sites.
        'pro' => [
            'edition' => 'pro',
            'modules' => ['share', 'intake'],
            'sites' => 0, 'ttlDays' => 365, 'enforcement' => 'perpetual',
        ],
        'pro-monthly' => [
            'edition' => 'pro',
            'modules' => ['share', 'intake'],
            'sites' => 0, 'ttlDays' => 31, 'enforcement' => 'subscription',
        ],
        // Studio = Pro + versioning + webhooks + AI/OCR (BYO-key).
        'studio' => [
            'edition' => 'studio',
            'modules' => ['share', 'intake', 'versioning', 'webhooks', 'ai', 'ocr'],
            'sites' => 0, 'ttlDays' => 365, 'enforcement' => 'perpetual',
        ],
        // Enterprise = everything incl. the compliance bundle.
        'enterprise' => [
            'edition' => 'enterprise',
            'modules' => ['share', 'intake', 'versioning', 'webhooks', 'ai', 'ocr', 'virus', 'backup', 'c2pa'],
            'sites' => 0, 'ttlDays' => 365, 'enforcement' => 'perpetual',
        ],
        'lifetime' => [
            'edition' => 'studio',
            'modules' => ['share', 'intake', 'versioning', 'webhooks', 'ai', 'ocr'],
            'sites' => 0, 'ttlDays' => null, 'enforcement' => 'perpetual', // no expiry
        ],
    ];

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        $file = getenv('FLUXFILES_LICENSE_PLANS') ?: '';
        if ($file !== '' && is_file($file)) {
            $custom = json_decode((string) file_get_contents($file), true);
            if (is_array($custom)) {
                return $custom + self::DEFAULT;
            }
        }
        return self::DEFAULT;
    }

    /** @return array<string,mixed>|null */
    public static function get(string $plan): ?array
    {
        return self::all()[$plan] ?? null;
    }
}
