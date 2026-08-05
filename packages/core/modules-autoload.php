<?php

declare(strict_types=1);

/**
 * Autoload the PAID MODULES.
 *
 * Composer cannot do this for us. The modules are private packages, so
 * `composer require fluxfiles/share` has nothing to resolve against; they arrive as a
 * signed zip that `fluxfiles update <module>` unpacks into `vendor/fluxfiles/<module>/`.
 * A directory dropped into vendor/ is invisible to Composer's generated maps — it only
 * knows what is in composer.json — so without this a customer could pay, install
 * correctly, and still be told `501 module_not_installed`, with nothing to suggest why.
 *
 * This lives in its OWN file, separate from `autoload.php`, because the two have to
 * reach different callers. `autoload.php` is the standalone entrypoint helper: only
 * `api/index.php`, `embed.php` and `bin/fluxfiles` require it. Everything that consumes
 * core as a library — the Laravel proxy, and the WordPress plugin, which loads
 * `vendor/autoload.php` — never touches it. Registering the module autoloader there
 * meant the fix shipped for core-standalone only, and the two hero SKUs stayed dead on
 * the channel `ROADMAP.md` calls the hero channel. Composer's `autoload.files` pulls
 * this file in for every consumer; `autoload.php` requires it for the standalone path.
 * `require_once` on both sides means it registers exactly once either way.
 *
 * It deliberately does NOT require the Composer autoloader — that is `autoload.php`'s
 * job, and doing it here would recurse (Composer includes `autoload.files` from inside
 * `vendor/autoload.php` itself).
 */

(static function (): void {
    // Registering twice would make every miss cost two filesystem probes.
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    /**
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
            __DIR__ . '/vendor/fluxfiles/' . $module . '/src/',   // standalone / monorepo / WP plugin bundle
            __DIR__ . '/../../fluxfiles/' . $module . '/src/',    // installed as a dependency → host vendor/
        ] as $base) {
            $file = $base . $relative;
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    });
})();
