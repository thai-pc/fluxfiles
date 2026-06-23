<?php

declare(strict_types=1);

/**
 * Locate and require the Composer autoloader, whichever layout we're running in:
 *
 *   - standalone / monorepo / release zip → this package has its own `vendor/`.
 *   - installed as a dependency (`composer require fluxfiles/fluxfiles`) → the deps
 *     are flattened into the *host's* `vendor/`, so this package has no `vendor/`
 *     of its own and the autoloader sits two levels up.
 *
 * Without this, the standalone entrypoints (api/index.php, embed.php, bin/fluxfiles)
 * hard-required `__DIR__/../vendor/autoload.php` and fatally failed when the package
 * was consumed via Composer. Included with `require __DIR__ . '/autoload.php'`.
 */

$fluxfilesAutoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',   // standalone / monorepo / release zip
    __DIR__ . '/../../autoload.php',    // installed as a dependency → host vendor/autoload.php
];

foreach ($fluxfilesAutoloadCandidates as $fluxfilesAutoload) {
    if (is_file($fluxfilesAutoload)) {
        require_once $fluxfilesAutoload;
        return;
    }
}

fwrite(
    STDERR,
    "FluxFiles: Composer autoloader not found.\n" .
    "Run `composer install` in the package, or `composer require fluxfiles/fluxfiles` in your project.\n"
);
if (PHP_SAPI !== 'cli') {
    http_response_code(500);
}
exit(1);
