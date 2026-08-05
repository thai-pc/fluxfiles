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
    /**
     * Autoload the PAID MODULES.
     *
     * Composer cannot do this for us. The modules are private packages, so
     * `composer require fluxfiles/share` has nothing to resolve against; they arrive
     * as a signed zip that `fluxfiles update <module>` unpacks into
     * `vendor/fluxfiles/<module>/`. A directory dropped into vendor/ is invisible to
     * Composer's generated maps — it only knows what is in composer.json — so before
     * this, a customer could pay, install correctly, and still be told
     * `501 module_not_installed`, with nothing to suggest why.
     *
     * Resolution is lazy and cheap: the closure only touches the filesystem when a
     * `FluxFiles\<Something>\` class is actually requested, which happens only when a
     * paid gate runs. `class_exists()` on a free-core install stays a no-op that ends
     * in a single is_file() miss.
     */
    spl_autoload_register(static function (string $class): void {
        if (strncmp($class, 'FluxFiles\\', 10) !== 0) {
            return;
        }
        $rest = substr($class, 10);
        $slash = strpos($rest, '\\');
        if ($slash === false) {
            return;   // FluxFiles\Foo is core's own namespace, already mapped
        }
        // FluxFiles\Share\ShareModule → module dir "share", relative path ShareModule
        $module = strtolower(substr($rest, 0, $slash));
        if (!preg_match('/^[a-z0-9]+$/', $module)) {
            return;   // never let a crafted class name walk the filesystem
        }
        $relative = str_replace('\\', '/', substr($rest, $slash + 1)) . '.php';

        // Only the layouts a real install produces. A monorepo sibling
        // (packages/share next to packages/core) is deliberately NOT searched: it
        // exists only in this repository, and treating it as installed would make the
        // free-core path untestable on the one machine that has the private packages —
        // every "module absent → 501" test would see the module. The suites that DO
        // want a module loaded require its source explicitly, which is the honest way
        // to say "this run has it".
        foreach ([
            __DIR__ . '/vendor/fluxfiles/' . $module . '/src/',   // package's own vendor
            __DIR__ . '/../../fluxfiles/' . $module . '/src/',    // host vendor/
        ] as $base) {
            $file = $base . $relative;
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    });
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
