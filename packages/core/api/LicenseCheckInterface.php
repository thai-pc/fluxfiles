<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * What the module gate needs from a licence: "is this module entitled?" plus a status
 * and a non-sensitive summary. `ModuleRegistry` and `ComplianceScorecard` depend on
 * this, not on the concrete {@see LicenseManager}, so a host that ships without the
 * verifier (the WordPress.org plugin) gets {@see NoLicense}, and an add-on can supply
 * the real one through `ModuleRegistry::setLicenseResolver()`.
 */
interface LicenseCheckInterface
{
    /** Is $module entitled AND still serviceable? (Layer 2 of the gate.) */
    public function licensed(string $module): bool;

    /** free | active | grace | perpetual | expired */
    public function status(): string;

    /**
     * Non-sensitive summary: edition, status, enforcement, modules, expires,
     * days_left, updates_allowed (the keys of {@see LicenseManager::info()}).
     *
     * @return array<string,mixed>
     */
    public function info(): array;
}
