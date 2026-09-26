<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

/**
 * Maps a plan id (your product/variant slug) → the license payload shape
 * (edition, modules, ttl, enforcement). This is the single place that
 * encodes what each tier unlocks — mirror it in your checkout products.
 *
 * Override/extend via a JSON file at FLUXFILES_LICENSE_PLANS (same shape).
 */
final class Plans
{
    /** @var array<string,array<string,mixed>> */
    private const DEFAULT = [
        // Pro = the two hero paid products (Share + Intake), annual, unlimited sites.
        // This is a perpetual software licence: at the one-year date only the
        // update/support entitlement ends. There is intentionally no runtime grace
        // or shut-off date for a self-hosted customer.
        'pro' => [
            'edition' => 'pro',
            'modules' => ['share', 'intake'],
            'ttlDays' => 365, 'graceDays' => 0, 'enforcement' => 'perpetual',
        ],
        // A recurring convenience plan. Seven days is a payment-recovery window,
        // not a data-retention policy: expiry never deletes a customer's files or
        // module records.
        'pro-monthly' => [
            'edition' => 'pro',
            'modules' => ['share', 'intake'],
            'ttlDays' => 31, 'graceDays' => 7, 'enforcement' => 'subscription',
        ],
        // Studio = Pro + versioning + webhooks + AI/OCR (BYO-key).
        'studio' => [
            'edition' => 'studio',
            'modules' => ['share', 'intake', 'versioning', 'webhooks', 'ai', 'ocr'],
            'ttlDays' => 365, 'graceDays' => 0, 'enforcement' => 'perpetual',
        ],
        'studio-monthly' => [
            'edition' => 'studio',
            'modules' => ['share', 'intake', 'versioning', 'webhooks', 'ai', 'ocr'],
            'ttlDays' => 31, 'graceDays' => 7, 'enforcement' => 'subscription',
        ],
        // Enterprise = everything incl. the compliance bundle.
        'enterprise' => [
            'edition' => 'enterprise',
            'modules' => ['share', 'intake', 'versioning', 'webhooks', 'ai', 'ocr', 'virus', 'backup', 'c2pa', 'audit-export', 'sso', 'dlp', 'legal-hold'],
            'ttlDays' => 365, 'graceDays' => 0, 'enforcement' => 'perpetual',
        ],
        // Marketing name retained for checkout compatibility. It means lifetime
        // *use*, plus 12 months of updates/support — not lifetime updates. Existing
        // no-expiry keys stay honoured; this only governs newly issued keys.
        'lifetime' => [
            'edition' => 'studio',
            'modules' => ['share', 'intake', 'versioning', 'webhooks', 'ai', 'ocr'],
            'ttlDays' => 365, 'graceDays' => 0, 'enforcement' => 'perpetual',
        ],
        // Support = a pure service commitment (no code, no module) — see
        // docs/ROADMAP.md Phase 4 #10. modules=[] means LicenseManager::licensed()
        // never unlocks anything; the record exists only so the operator knows who
        // is on a priority queue. Subscription enforcement because the commitment
        // stops the moment payment stops, unlike perpetual software licenses.
        'support' => [
            'edition' => 'support',
            'modules' => [],
            'ttlDays' => 365, 'graceDays' => 7, 'enforcement' => 'subscription',
        ],
        'support-monthly' => [
            'edition' => 'support',
            'modules' => [],
            'ttlDays' => 31, 'graceDays' => 7, 'enforcement' => 'subscription',
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
