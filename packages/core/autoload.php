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

$fluxfilesFound = false;
foreach ($fluxfilesAutoloadCandidates as $fluxfilesAutoload) {
    if (is_file($fluxfilesAutoload)) {
        require_once $fluxfilesAutoload;
        $fluxfilesFound = true;
        break;
    }
}

if ($fluxfilesFound) {
    // Paid-module autoloading lives in its own file so that library consumers get it
    // too: Composer's `autoload.files` pulls it in for anyone who requires
    // vendor/autoload.php (the Laravel proxy, the WordPress plugin), which never
    // reaches this file. `require_once` on both paths keeps it a single registration.
    require_once __DIR__ . '/modules-autoload.php';
    return;
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
