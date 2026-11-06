<?php

declare(strict_types=1);

namespace FluxFiles\Laravel;

/**
 * Feeds `php artisan about` (Illuminate\Foundation\Console\AboutCommand) with the same
 * operator report as `fluxfiles about`, built by core's AboutInfo. AboutInfo never
 * contains keys, credentials or endpoints (disks appear by driver only).
 *
 * A core older than AboutInfo degrades to a one-line hint, so this adapter does not
 * raise its core floor.
 */
final class About
{
    /** @return array<string,string> label => value */
    public static function data(): array
    {
        if (!class_exists(\FluxFiles\AboutInfo::class)) {
            return ['Report' => 'update fluxfiles/fluxfiles for the full report'];
        }
        try {
            $about = \FluxFiles\AboutInfo::collect([
                'env' => array_merge($_ENV, [
                    'FLUXFILES_STORAGE_BACKEND' => (string) config('fluxfiles.storage_backend', 'json'),
                ]),
                'disks' => (array) config('fluxfiles.disks', []),
            ]);
        } catch (\Throwable $e) {
            return ['Report' => 'unavailable'];
        }
        $out = [];
        foreach (\FluxFiles\AboutInfo::rows($about) as $row) {
            $out[$row['label']] = $row['value'];
        }
        return $out;
    }

    /** Register with `artisan about` when this Laravel has it (older versions lack AboutCommand). */
    public static function register(): void
    {
        if (class_exists(\Illuminate\Foundation\Console\AboutCommand::class)) {
            \Illuminate\Foundation\Console\AboutCommand::add('FluxFiles', static fn () => self::data());
        }
    }
}
