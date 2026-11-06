<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Resolves + gates the commercial (paid) modules. Lives in the MIT core and knows
 * only the canonical class name of each first-party module — the module's code
 * ships separately in a proprietary package. This is where the **three-layer gate**
 * is enforced:
 *
 *   (1) Capability — is the module's code installed?   `class_exists`
 *   (2) License    — does the license entitle it?      `LicenseManager::licensed`
 *   (3) Claim      — does the tenant's token allow it?  `Claims::isAllowed`
 *
 * Free MIT core ships none of the module packages, so every paid endpoint 501s
 * cleanly. Each layer returns a distinct, actionable error:
 *   501 module_not_installed · 402 license_required/license_expired · 403 <claim>_forbidden
 */
final class ModuleRegistry
{
    /**
     * Canonical first-party paid modules: id → fully-qualified class name. The
     * class is autoloadable only when its package (e.g. `fluxfiles/optimize`) is
     * installed; absent → class_exists is false → 501.
     *
     * @var array<string,string>
     */
    private static array $map = [
        // NOTE: 'optimize' was a paid module but is now FREE/core (\FluxFiles\OptimizeModule),
        // invoked directly by the /api/fm/optimize route — no longer gated here.
        'share'      => '\\FluxFiles\\Share\\ShareModule',
        'intake'     => '\\FluxFiles\\Intake\\IntakeModule',
        'versioning' => '\\FluxFiles\\Versioning\\VersioningModule',
        'webhooks'   => '\\FluxFiles\\Webhooks\\WebhooksModule',
        'ai'       => '\\FluxFiles\\Ai\\AiVisionModule',
        'ocr'      => '\\FluxFiles\\Ocr\\OcrModule',
        'virus'    => '\\FluxFiles\\Virus\\VirusScanModule',
        'backup'   => '\\FluxFiles\\Backup\\BackupModule',
        'c2pa'     => '\\FluxFiles\\C2pa\\C2paModule',
        'audit-export' => '\\FluxFiles\\AuditExport\\AuditExportModule',
        'sso'          => '\\FluxFiles\\Sso\\SsoModule',
        'dlp'          => '\\FluxFiles\\Dlp\\DlpModule',
        'legal-hold'   => '\\FluxFiles\\LegalHold\\LegalHoldModule',
    ];

    /** Snapshot of the built-in map, for reset() in tests. @var array<string,string> */
    private static array $builtin = [];

    /** @var (callable():?LicenseCheckInterface)|null */
    private static $licenseResolver = null;

    /**
     * The licence every gate consults. Default: the offline verifier reading
     * `FLUXFILES_LICENSE_KEY` from the environment (`LicenseManager::fromEnv()`), or
     * {@see NoLicense} when that class isn't shipped (the WordPress.org build). Resolved
     * per call, never cached: the env can change between requests in a long-lived host.
     */
    public static function license(): LicenseCheckInterface
    {
        if (self::$licenseResolver !== null) {
            try {
                $resolved = (self::$licenseResolver)();
            } catch (\Throwable $e) {
                // Fail closed: a resolver that breaks must not take the app down (500) and
                // must not hand out a licence. Nothing is entitled until it works again.
                Log::warn('FluxFiles: licence resolver failed, treating the server as unlicensed: ' . $e->getMessage());
                return new NoLicense();
            }
            if ($resolved instanceof LicenseCheckInterface) {
                return $resolved;
            }
            // null (or garbage) from a resolver means "no opinion": fall through to the default.
        }
        return class_exists(LicenseManager::class) ? LicenseManager::fromEnv() : new NoLicense();
    }

    /**
     * Install a licence resolver (an add-on that holds the key elsewhere, e.g. a WP option).
     * Return null from it to fall back to the default. Pass null to remove it (tests).
     *
     * @param (callable():?LicenseCheckInterface)|null $resolver
     */
    public static function setLicenseResolver(?callable $resolver): void
    {
        self::$licenseResolver = $resolver;
    }

    /**
     * Register or override a module id → class mapping. Used by a proprietary
     * package that wants to self-register a non-canonical class, and by tests.
     */
    public static function register(string $id, string $class): void
    {
        if (self::$builtin === []) {
            self::$builtin = self::$map;
        }
        self::$map[$id] = $class;
    }

    /** Restore the built-in map and drop any licence resolver (tests). */
    public static function reset(): void
    {
        self::$licenseResolver = null;
        if (self::$builtin !== []) {
            self::$map = self::$builtin;
            self::$builtin = [];
        }
    }

    /** Every known module id, installed or not (the operator About report). @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::$map);
    }

    /** The module's class name when it is known AND installed, else null (no instance, no gate). */
    public static function classFor(string $id): ?string
    {
        $class = self::$map[$id] ?? null;
        return $class !== null && class_exists($class) ? ltrim($class, '\\') : null;
    }

    /** Is a module known AND its code installed (its package present)? */
    public static function installed(string $id): bool
    {
        $class = self::$map[$id] ?? null;
        return $class !== null && class_exists($class);
    }

    /**
     * Resolve a gated module instance, or throw the matching error:
     *   - 501 module_not_installed   (code absent)
     *   - 402 license_required       (no/invalid license) / license_expired (past grace)
     *   - 403 <claim>_forbidden      (token lacks the module's claim)
     *
     * @return ModuleInterface
     */
    public static function require(string $id, LicenseCheckInterface $license, Claims $claims): ModuleInterface
    {
        $class = self::$map[$id] ?? null;
        if ($class === null || !class_exists($class)) {
            throw new ApiException("The '{$id}' module is not installed on this server", 501, 'module_not_installed', ['module' => $id]);
        }

        if (!$license->licensed($id)) {
            $status = $license->status(); // free | expired | (active/grace won't reach here if not entitled)
            $code = $status === 'expired' ? 'license_expired' : 'license_required';
            throw new ApiException("The '{$id}' module requires a valid license ({$status})", 402, $code, ['module' => $id, 'status' => $status]);
        }

        /** @var class-string<ModuleInterface> $class */
        $claim = (string) $class::claim();
        if ($claim !== '' && !$claims->isAllowed($claim)) {
            throw new ApiException("This token may not use the '{$id}' module", 403, $claim . '_forbidden', ['module' => $id]);
        }

        return new $class();
    }

    /**
     * Layers 1+2 only (installed + licensed) — for routes that run before a
     * request-scoped Claims object exists (the SSO login/callback/exchange routes
     * are pre-auth: there is no JWT yet to build Claims from). Layer 3 for those
     * routes is an explicit server env-flag check done by the caller, not here.
     */
    public static function requireServer(string $id, LicenseCheckInterface $license): ModuleInterface
    {
        $class = self::$map[$id] ?? null;
        if ($class === null || !class_exists($class)) {
            throw new ApiException("The '{$id}' module is not installed on this server", 501, 'module_not_installed', ['module' => $id]);
        }

        if (!$license->licensed($id)) {
            $status = $license->status();
            $code = $status === 'expired' ? 'license_expired' : 'license_required';
            throw new ApiException("The '{$id}' module requires a valid license ({$status})", 402, $code, ['module' => $id, 'status' => $status]);
        }

        /** @var class-string<ModuleInterface> $class */
        return new $class();
    }
}
