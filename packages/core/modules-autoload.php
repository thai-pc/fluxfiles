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

// Required by path, not through Composer: this file runs from inside vendor/autoload.php's
// own `autoload.files` pass (and from autoload.php), so it must not depend on the class map.
require_once __DIR__ . '/api/ModuleAutoloader.php';

// Only the layouts a real install produces (see ModuleAutoloader). Registering again, from
// here or from a host that adds its own bases, never installs a second autoloader.
\FluxFiles\ModuleAutoloader::register([
    __DIR__ . '/vendor/fluxfiles/',    // standalone / monorepo / WP plugin bundle
    __DIR__ . '/../../fluxfiles/',     // installed as a dependency → host vendor/
]);
