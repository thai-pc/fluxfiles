<?php

declare(strict_types=1);

namespace FluxFiles;

/** The free edition: nothing is entitled. Same `info()` keys as LicenseManager, empty values. */
final class NoLicense implements LicenseCheckInterface
{
    public function licensed(string $module): bool
    {
        return false;
    }

    public function status(): string
    {
        return 'free';
    }

    public function info(): array
    {
        return [
            'edition'         => 'free',
            'status'          => 'free',
            'enforcement'     => 'perpetual',
            'modules'         => [],
            'expires'         => null,
            'days_left'       => null,
            'updates_allowed' => false,
        ];
    }
}
