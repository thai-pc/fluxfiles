# FluxFiles extension API (`FLUXFILES_EXT_API = 1`)

The free plugin ships no paid modules, licence, updater or engine settings. An add-on plugin
(the future FluxFiles Pro) plugs in through the hooks below. `FLUXFILES_EXT_API` is bumped only
when a hook changes incompatibly; an add-on should refuse to boot when it is lower than the
level it was written for. `FLUXFILES_CORE_VERSION` (string, stamped at build) is the bundled core
release, or `'unknown'` in a dev checkout. Design: `docs/design/WPORG-SPLIT-DESIGN.md` section 2.3.

| Hook | Type / arguments | Fired or applied | Purpose |
|---|---|---|---|
| `FLUXFILES_EXT_API` | constant, int `1` | defined in `fluxfiles.php` before anything boots | feature-detect the API level |
| `fluxfiles_module_paths` | filter, `string[]` of base dirs (each holds `<module>/src/`), default `[]` | `plugins_loaded` priority 1, first | directories registered with `\FluxFiles\ModuleAutoloader::register()`; only absolute, existing directories are kept (relative paths, missing dirs and non-strings are dropped) and each is `realpath()`ed |
| `fluxfiles_loaded` | action, `FluxFilesPlugin $plugin` | `plugins_loaded` priority 1, right after the module paths are registered | the add-on's boot point |
| `fluxfiles_license` | filter, `?\FluxFiles\LicenseCheckInterface`, default `null` | every `FluxFilesPlugin::license()` call (also installed as core's `ModuleRegistry` licence resolver) | return the licence verifier. Anything that is not a `LicenseCheckInterface`, or a throwing callback, is ignored and the plugin answers `NoLicense` (all paid modules locked). A licence object that is returned but whose own methods throw fails closed: the request answers 500 and is never treated as entitled |
| `fluxfiles_api_boot` | action, `FluxFilesApi $api` | every `FluxFilesApi::__construct`: any REST request in any namespace (not only `fluxfiles/v1`) and attachment deletion. It may fire more than once per request, so callbacks must be idempotent. The instance is not fully initialised yet | seed module-engine `$_ENV`, wire request-scoped state |
| `fluxfiles_rest_routes` | action, `string $ns` (`fluxfiles/v1`), `FluxFilesApi $api` | end of `FluxFilesApi::registerRoutes()` (on `rest_api_init`) | register extra routes in the same namespace. Use `[\FluxFilesApi::class, 'checkAuth']` as the `permission_callback`; the helper methods on `$api` are private for now |
| `fluxfiles_admin_sections` | action, `string $page` (`fluxfiles`), `string $group` (`fluxfiles`) | end of `FluxFilesAdmin::registerSettings()` (on `admin_init`) | `add_settings_section()` / `register_setting()` for the add-on's own settings on Settings > FluxFiles |
| `fluxfiles_edition_presets` | filter, `array<string, array<string, mixed>>` (edition => claim defaults), default `[]` | `FluxFilesPlugin::applyEditionPreset()` while minting a token with an `edition` override | supply the edition bundles. An edition that is not in the map is ignored (never throws); under `WP_DEBUG` it raises `_doing_it_wrong()`. Edition names (the map keys and the requested edition) match in lowercase; keys are normalised for you. Explicit claims always beat a preset |
| `fluxfiles_token_overrides` | filter (pre-existing) | when a token is minted | unchanged. The free plugin mints `pro_hints=false` by default; an override can set `pro_hints => true` |
| `fluxfiles_storage_base` | filter, `string` absolute dir, default the location stored in the `fluxfiles_storage_base` option (a token, `content` or `uploads`, resolved per request) | every `FluxFilesStorage::base()` | relocate the local disk (the `FLUXFILES_STORAGE_DIR` constant wins over it; multisite adds `sites/<id>/` below it). A location set by the constant or this filter is yours: uninstall never deletes files there |

Reference edition map (the one that used to be built in) lives in
`packages/wordpress/tests/fixtures/edition-presets.php`.

Load order: WordPress loads plugins alphabetically, so `fluxfiles-pro/` loads before `fluxfiles/`.
While the add-on's main file is included, `FLUXFILES_EXT_API` and `FLUXFILES_CORE_VERSION` are
therefore still undefined. Do the version guard in `plugins_loaded` priority 0 or inside a
`fluxfiles_loaded` callback, never at file load. Add the `fluxfiles_module_paths` filter at file
load (or at the latest `plugins_loaded` priority 0): it is applied before `fluxfiles_loaded` fires.

Order within a request: `plugins_loaded:1` runs `fluxfiles_module_paths`, registers the
autoloader bases, then `fluxfiles_loaded`; later `rest_api_init` creates `FluxFilesApi`
(`fluxfiles_api_boot`, then the routes, then `fluxfiles_rest_routes`); `admin_init` runs
`fluxfiles_admin_sections`.

A paid route with no add-on answers `501 module_not_installed`; with the module installed but
no licence from `fluxfiles_license`, `402 license_required`; an installed module that predates the
capability interface a route needs answers `501 module_update_required`.

## Uninstall

Deleting the plugin always removes its settings, usage transients and the rate-limit
table. Everything that belongs to the stored files (metadata, directories, trash, audit
log + chain head and legal-hold tables, the `fluxfiles_secret` that keys the audit chain,
and the files) is **kept** unless "Delete all FluxFiles data when the plugin is deleted"
is on. With it on, the files plugin wrote under its own storage folder go too (only our
`.htaccess` / `index.php`, never a folder that contains ABSPATH, wp-content, uploads or the
plugins dir, and nothing at all at a `FLUXFILES_STORAGE_DIR` / `fluxfiles_storage_base`
filter location).
