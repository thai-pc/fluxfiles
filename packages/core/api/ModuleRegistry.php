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
        'share'    => '\\FluxFiles\\Share\\ShareModule',
        'intake'   => '\\FluxFiles\\Intake\\IntakeModule',
        'ai'       => '\\FluxFiles\\Ai\\AiVisionModule',
        'ocr'      => '\\FluxFiles\\Ocr\\OcrModule',
        'virus'    => '\\FluxFiles\\Virus\\VirusScanModule',
        'backup'   => '\\FluxFiles\\Backup\\BackupModule',
        'c2pa'     => '\\FluxFiles\\C2pa\\C2paModule',
    ];

    /** Snapshot of the built-in map, for reset() in tests. @var array<string,string> */
    private static array $builtin = [];

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

    /** Restore the built-in map (tests). */
    public static function reset(): void
    {
        if (self::$builtin !== []) {
            self::$map = self::$builtin;
            self::$builtin = [];
        }
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
    public static function require(string $id, LicenseManager $license, Claims $claims): ModuleInterface
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
}
