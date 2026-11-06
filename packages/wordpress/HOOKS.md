# FluxFiles extension API (`FLUXFILES_EXT_API = 1`)

The free plugin ships no paid modules, licence, updater or engine settings. An add-on plugin
(the future FluxFiles Pro) plugs in through the hooks below. `FLUXFILES_EXT_API` is bumped only
when a hook changes incompatibly; an add-on should refuse to boot when it is lower than the
level it was written for. `FLUXFILES_CORE_VERSION` (string, stamped at build) is the bundled core
release, or `'unknown'` in a dev checkout. Design: `docs/design/WPORG-SPLIT-DESIGN.md` section 2.3.

| Hook | Type / arguments | Fired or applied | Purpose |
|---|---|---|---|
| `FLUXFILES_EXT_API` | constant, int `1` | defined in `fluxfiles.php` before anything boots | feature-detect the API level |
| `fluxfiles_module_paths` | filter, `string[]` of base dirs (each holds `<module>/src/`), default `[]` | `plugins_loaded` priority 1, first | directories registered with `\FluxFiles\ModuleAutoloader::register()`; missing dirs and non-strings are dropped |
| `fluxfiles_loaded` | action, `FluxFilesPlugin $plugin` | `plugins_loaded` priority 1, right after the module paths are registered | the add-on's boot point |
| `fluxfiles_license` | filter, `?\FluxFiles\LicenseCheckInterface`, default `null` | every `FluxFilesPlugin::license()` call (also installed as core's `ModuleRegistry` licence resolver) | return the licence verifier. Anything that is not a `LicenseCheckInterface`, or a throwing callback, is ignored and the plugin answers `NoLicense` (all paid modules locked) |
| `fluxfiles_api_boot` | action, `FluxFilesApi $api` | `FluxFilesApi::__construct`, i.e. once per request before any REST handler runs | seed module-engine `$_ENV`, wire request-scoped state |
| `fluxfiles_rest_routes` | action, `string $ns` (`fluxfiles/v1`), `FluxFilesApi $api` | end of `FluxFilesApi::registerRoutes()` (on `rest_api_init`) | register extra routes in the same namespace |
| `fluxfiles_admin_sections` | action, `string $page` (`fluxfiles`), `string $group` (`fluxfiles`) | end of `FluxFilesAdmin::registerSettings()` (on `admin_init`) | `add_settings_section()` / `register_setting()` for the add-on's own settings on Settings > FluxFiles |
| `fluxfiles_edition_presets` | filter, `array<string, array<string, mixed>>` (edition => claim defaults), default `[]` | `FluxFilesPlugin::applyEditionPreset()` while minting a token with an `edition` override | supply the edition bundles. An edition that is not in the map is ignored (never throws); under `WP_DEBUG` it raises `_doing_it_wrong()`. Explicit claims always beat a preset |
| `fluxfiles_token_overrides` | filter (pre-existing) | when a token is minted | unchanged. The free plugin mints `pro_hints=false` by default; an override can set `pro_hints => true` |

Reference edition map (the one that used to be built in) lives in
`packages/wordpress/tests/fixtures/edition-presets.php`.

Order within a request: `plugins_loaded:1` runs `fluxfiles_module_paths`, registers the
autoloader bases, then `fluxfiles_loaded`; later `rest_api_init` creates `FluxFilesApi`
(`fluxfiles_api_boot`, then the routes, then `fluxfiles_rest_routes`); `admin_init` runs
`fluxfiles_admin_sections`.

A paid route with no add-on answers `501 module_not_installed`; with the module installed but
no licence from `fluxfiles_license`, `402 license_required`; an installed module that predates the
capability interface a route needs answers `501 module_update_required`.
