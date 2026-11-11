# WordPress.org split: `fluxfiles` (free, WP.org) + `fluxfiles-pro` (paid add-on)

> **Phase 3 status (core-v1.1.0):** done. The Laravel and WordPress adapters were moved to
> module dispatch first (`PublicRoutes::dispatch()` for the JSON routes, `PublicRoutes::page($id, $apiBase)`
> for the pages; `/page` is NOT routed through `dispatch()` for adapters, which would hard-code `/api/fm`). Core then
> dropped the legacy code: `PublicLinks.php` is now `api/helpers.php` (`ff_str_param`,
> `ff_public_meta_repo`, `ff_sso_rate_limit`), `runRetention`/`runFixityCheck`/`FixityChecker`/
> `AuditChain::verify`/`trustedEntries` are gone, and an installed pre-interface module answers
> 501 `module_update_required` via `ModuleRegistry::requireCapability()`. `public/share.html` and
> `public/intake.html` are redirect stubs using a RELATIVE target (`../api/fm/<id>/page` + query +
> hash) so subdirectory installs work. Core's `composer.json` carries `conflict` entries for
> `fluxfiles/laravel <1.0.3`, `share <1.4.0`, `intake <1.5.0`, `legal-hold <1.2.0`,
> `audit-export <1.1.0`.

> **Owner decision (2026-11-06): nobody uses the paid WordPress modules yet, so there is no
> migration to protect.** This supersedes §5.2, §5.3 and phase 4 of §10 below:
> - **No bridge release `wordpress-v1.0.5`.** No `legacyModules()` hold, no `handover` flag, no
>   "your modules were removed" notices, no `bridge` block or `$ANY_LICENSE_MODULES` branch in the
>   update server. An old-channel install (if any exists) is simply offered 1.1.0 directly.
> - **Phase 4 is folded into phase 5.** Its preparatory parts ship in the 1.1.0 work and are
>   already in the tree: the `FluxFilesPlugin::license()` seam (`fluxfiles_license` filter,
>   `LicenseManager` if present else `NoLicense`, installed with
>   `ModuleRegistry::setLicenseResolver()`; `seedLicenseEnv()` is gone), new-form public links
>   (`publicLinkUrl()` mints the per-blog REST `<id>/page` URL), and the `FLUXFILES_CORE_VERSION`
>   stamp (placeholder in `fluxfiles.php`, 3rd argument of `scripts/stamp-wp-version.py`, passed
>   by `build-wordpress.sh` from the latest `core-v*` tag; shown in Site Health / About).
>   The updater and `Update URI` stay until phase 5 removes them (§10 step 5).
> - The `wordpress-pro` update-server product (`$ANY_LICENSE_MODULES`) is deferred to phase 7
>   with `fluxfiles-pro`.
> - Later phase-5 choices: the WP build uses **WordPress's bundled CodeMirror** instead of the
>   vendored copy (plugin guideline 13: no duplicating core libraries; resolves open question 2),
>   and **new installs store files under `wp-content/uploads/fluxfiles`** while an existing
>   `wp-content/fluxfiles` is kept (§6).
> - **`Requires PHP` stays `8.1.0`** everywhere (the readme outline's `8.1` is the same floor).

Status: design, not implemented. Date: 2026-11-06. Owner decisions (two plugins; the four
licence-locked features move into their modules on every host) are **settled**; this doc
designs within them.

> **Decided (owner-approved 2026-11-06), refining the original decisions:**
> 1. **`fluxfiles-pro` ships 12 modules, not 13: no `sso`.** SSO does not apply to WordPress
>    (`FluxFilesPlugin::MODULE_ENV` deliberately omits `FLUXFILES_SSO_*`), so shipping it would
>    be dead code.
> 2. **The WP `public-link.php` is replaced by a REST page route, with a 90-day redirect shim.**
>    **UPDATE (owner decision, after Plugin Check 2.2.0): the shim is REMOVED, never shipped.**
>    The paid WordPress modules had no users, so no old-form links exist; `public-link.php`, its
>    tests, its CI lint entry and its dist-guard special case are deleted. The paragraphs below
>    describe the superseded plan.
>    Its job (serving `share.html` and `intake.html` with the API base injected) becomes a REST
>    page route stub in the free plugin. The stub calls core `PublicRoutes::page()`, which reads
>    the page out of the module, so the HTML and handlers live in the modules as decided. For
>    **one transition cycle** (at least 90 days after 1.1.0), the free plugin keeps
>    `public-link.php` as a WordPress-free, 20-line redirect, so that links minted before 1.1.0
>    still open (§5.4). It never loads `wp-load.php`.
> 3. **Core removal takes two core releases.** Release X adds the seams and keeps a legacy
>    fallback. Release Y deletes the code. Without the gap, a standalone operator who updates
>    core before the modules loses Share/Intake links, retention and fixity on the spot (§4.5).

---

## 1. Problem and who pays

The FluxFiles WP plugin (`packages/wordpress`, wordpress-v1.0.4) can't be listed on
WordPress.org. The reasons, re-verified against the code:

| Guideline | Evidence | Fix in this doc |
|---|---|---|
| 8: no updates from non-WP.org servers | `fluxfiles.php:12` has `Update URI`, `:82`/`:95` require and boot the updater; `includes/FluxFilesUpdater.php:57-60` hooks `pre_set_site_transient_update_plugins`, `plugins_api` and `upgrader_pre_download` | §2.4, §7 |
| 5: no locked functionality | The free code runs behind a licence gate at `FluxFilesApi.php:2713` (`runRetention`), `:2751` (`runFixityCheck`), `:2785` (`AuditChain::verify`) and `api/PublicLinks.php:131-139` and `:319-325`. The same pattern sits in core `index.php:1145`, `:1175` and `:1207`, and in Laravel `FluxFilesController.php:1587`, `:1624` and `:1655` | §4 |
| Plugin data wiped on update | `modules-autoload.php:81` resolves `<plugin>/vendor/fluxfiles/<id>/src/`, and `docs/guides/ACTIVATE.md:47` tells WP customers to unzip there | §3.3 |
| No direct `wp-load.php` | `public-link.php:21-34` | §5.4 |
| readme | 8 tags, a 153-character short description, `Tested up to: 6.7` (current WP is 7.1.4 per api.wordpress.org), no External services section, no Source & build section | §9 |

**Who pays.**
- Free: every WordPress site, the persona "WP agency or site owner who wants S3/R2 media". The
  free plugin is MIT and complete: storage, optimization, search, trash, media-library bridge,
  audit log write and view, legal-hold *enforcement* and the scorecard view.
- Paid: the existing licence holders (Pro, Studio and Enterprise editions) buy `fluxfiles-pro`
  instead of unzipping modules into `vendor/`. There is no new SKU and no pricing change. The
  catalogue gains one product id, `wordpress-pro`.

## 2. Architecture

### 2.1 Stays stateless

Nothing new is stored server-side. The licence key and module-engine settings stay in the WP
options they already use (`fluxfiles_license_key` and `fluxfiles_module_env`), which are now
owned by pro. Fixity baselines stay in `<prefix>/_fluxfiles/fixity.json`. Share and Intake
records stay where the modules keep them today. The split moves code only: no bytes on disk
and no option names change, so existing data carries over unchanged.

### 2.2 Core seams (MIT core, every host)

All of these are new files or small changes in `packages/core/api/`:

| Seam | Shape | Replaces |
|---|---|---|
| `LicenseCheckInterface` | `licensed(string $module): bool`, `status(): string`, `info(): array` | the concrete `LicenseManager` type in `ModuleRegistry::require()`/`requireServer()` and `ComplianceScorecard::build()` |
| `NoLicense implements LicenseCheckInterface` | always `free`; `info()` has the same keys as `LicenseManager::info()` with empty or null values | — |
| `LicenseManager implements LicenseCheckInterface` | unchanged otherwise | — |
| `ModuleRegistry::license(): LicenseCheckInterface` and `ModuleRegistry::setLicenseResolver(?callable)` | Default resolver: `class_exists(LicenseManager::class) ? LicenseManager::fromEnv() : new NoLicense()` | all 30-odd `LicenseManager::fromEnv()` call sites (`index.php`, `FileManagerFactory.php:82,113,126,148`, `PublicLinks.php`). This also lets WP drop the `seedLicenseEnv()` `$_ENV` hack (`FluxFilesPlugin.php:405`) |
| `ModuleAutoloader::register(array $bases)` | The body of today's `modules-autoload.php` closure, with its kebab and plain-case logic, as a class. `modules-autoload.php` shrinks to `ModuleAutoloader::register([__DIR__.'/vendor/fluxfiles/', __DIR__.'/../../fluxfiles/'])` | — |
| `PublicModuleInterface extends ModuleInterface` | `handlePublic(string $method, string $uri, PublicContext $ctx): void` and `static pages(): array` (`['share' => '/abs/path/share.html']`) | `handleSharePublic()`/`handleIntakePublic()` |
| `PublicContext` (value object) | `dm`, `diskConfigs`, `meta()` (with today's `ff_public_meta_repo` logic), `secret`, `license`, `hostInjected`; helpers `param()` (`ff_str_param`), `audit()` (`ff_public_audit`), `rateLimit(string $bucket, int $limit)`, `tokenBucket(string $token)` (`ff_share_token_jti`) | the `ff_*` helpers the handlers use |
| `PublicRoutes` | `ROUTES` (`share` → info/unlock/file/page, `intake` → info/upload/page), `moduleFor(string $uri): ?string`, `dispatch(string $id, string $method, string $uri, PublicContext $ctx): void`, `page(string $id, string $apiBase): ?string` | the uri checks at `index.php:284-296`, WP `publicLink()`, Laravel `publicLink()` and `publicPage()` |
| `HttpTransportInterface` with `CurlTransport` as the default | `request(string $method, string $url, array $headers, ?string $body, int $timeout): array{status,headers,body}` | raw cURL in `AiTagger.php:357` and `BucketDoctor.php:320` (constructor-injected, with the cURL default) |
| `Log::warn(string $msg)` with `Log::setSink(?callable)` | Default sink is `error_log` | the `error_log()` calls in shipped core files |
| FileManager sweep seams (public, generic) | `filesystemFor(disk, perm)`, `scopedKey(rel)`, `relativeKey(key)`, `isVisibleKey(key)` (not reserved and not hidden for the token), `isHeld(disk, key)`, `visibleFiles(disk, relRoot, perm): iterable<{key,rel,mtime,size}>`, and `removeKeys(disk, keys, 'trash'\|'delete', callable $each)`. `removeKeys` runs inside `bulkOpen/Tick/Close` and calls `trashScoped()`/`deleteScoped()`, so the owner_only, perm and hold checks are unchanged | the private helpers `runRetention()` and `runFixityCheck()` reach into today |

`PublicRoutes::dispatch()` is the gate. It calls `ModuleRegistry::requireServer($id, $ctx->license)`,
the existing layers 1 and 2: 501 `module_not_installed` and 402 `license_required` or
`license_expired`. It then calls `$module->handlePublic()` and wraps any `ApiException` in the
standard envelope. **New in X:** if the installed module doesn't implement
`PublicModuleInterface`, it falls back to the legacy `PublicLinks.php` handler. **In Y (core-v1.1.0,
implemented):** that case answers 501 `module_update_required` with `{module}` instead; the check
sits right after `requireServer()`, before the page branch, so the page route answers the same.

### 2.3 Free plugin extension points (`packages/wordpress`)

| Hook | Type | Fired or applied where | Pro uses it to |
|---|---|---|---|
| `FLUXFILES_EXT_API` | const int, `1` | `fluxfiles.php` | refuse to boot when it's below what pro needs |
| `FLUXFILES_CORE_VERSION` | const string (the bundled core tag, stamped at build by `stamp-wp-version.py`) | `fluxfiles.php` | check the max core floor of its bundled modules |
| `fluxfiles_loaded` | action (`FluxFilesPlugin $p`) | `plugins_loaded` priority 1, after the core autoload | boot (§3.1) |
| `fluxfiles_module_paths` | filter `string[]` of absolute base dirs (relative/missing dropped, `realpath()`ed) | applied in `plugins_loaded` priority 1 just BEFORE `fluxfiles_loaded`, so add the filter at file load; when non-empty the free plugin calls `ModuleAutoloader::register()` | add `fluxfiles-pro/modules/` |
| `fluxfiles_license` | filter `?LicenseCheckInterface` (a returned licence whose methods throw fails closed: 500, never entitled) | `FluxFilesPlugin::license()` (default `new NoLicense()`), wired into `ModuleRegistry::setLicenseResolver()` | return `new LicenseManager($key)` |
| `fluxfiles_api_boot` | action (`FluxFilesApi $api`; fires on every construction: any REST namespace + attachment deletion, possibly more than once per request, so callbacks must be idempotent; instance not fully initialised) | `FluxFilesApi::__construct`, where `seedModuleEnv()`/`seedLicenseEnv()` are called today | seed the module-engine `$_ENV` |
| `fluxfiles_admin_sections` | action (`string $page='fluxfiles'`, `string $group='fluxfiles'`) | end of `FluxFilesAdmin::registerSettings()` | add the Licence and Module engines sections |
| `fluxfiles_edition_presets` | filter `array<string,array<string,bool>>` | `FluxFilesPlugin::applyEditionPreset()`, default `[]` | supply the `pro/agency/studio/enterprise` map (moved out of free). With no map, an `edition` is ignored, and the plugin calls `_doing_it_wrong()` under `WP_DEBUG` instead of throwing |
| `fluxfiles_rest_routes` | action (`string $ns`, `FluxFilesApi $api`; add-on routes must use `[FluxFilesApi::class, 'checkAuth']` as `permission_callback`, `$api` helpers are private for now) | end of route registration | nothing in v1; it's the fallback if a reviewer objects to the paid-route stubs (§11) |
| `fluxfiles_token_overrides` | filter (exists, `FluxFilesPlugin.php:1238`) | — | unchanged |

Load order: WP loads `fluxfiles-pro/` before `fluxfiles/`, so `FLUXFILES_EXT_API` and
`FLUXFILES_CORE_VERSION` are undefined while Pro's main file loads. Pro does its version guard in
`plugins_loaded` priority 0 or in `fluxfiles_loaded`, and adds `fluxfiles_module_paths` at file load.
`fluxfiles_edition_presets` keys are normalised with `array_change_key_case` (lowercase matching).

Phase-5 tracking (not implemented here): N5, the bundled `modules-autoload.php` still auto-registers
`<plugin>/vendor/fluxfiles/`; remove it in 5.7. N7, the `EXT_API`-mismatch admin notice (§2.5) is
deferred to phase 7.

The free plugin also mints `pro_hints=false` by default. A `fluxfiles_token_overrides` filter
can flip it back.

### 2.4 `fluxfiles-pro` (private repo, gitignored at `packages/wordpress-pro/`)

```
fluxfiles-pro/
  fluxfiles-pro.php     Plugin Name: FluxFiles Pro; Requires Plugins: fluxfiles;
                        Requires at least: 6.5; Requires PHP: 8.1.0;
                        Update URI: https://updates.fluxfiles.dev/fluxfiles-pro
  includes/ProPlugin.php    boot, compatibility checks, notices
  includes/ProLicense.php   licenseKey() (moved: option, then env), the fluxfiles_license filter
  includes/ProAdmin.php     Licence section, renderLicenseStatus, expiry notice and its dismiss
                            AJAX (moved from FluxFilesAdmin.php:103-124, 300-540); Module engines
                            (MODULE_ENV and its sanitiser/seeder, moved from FluxFilesPlugin.php:283-383)
  includes/ProUpdater.php   FluxFilesUpdater moved; MODULE='wordpress-pro',
                            slug fluxfiles-pro/fluxfiles-pro.php, REQUIRES_PHP
  lib/LicenseManager.php    byte-identical copies from the core tag the pro build pins
  lib/UpdateClient.php
  modules/<id>/src/…        12 modules (every ModuleRegistry::$map entry except sso, which does
                            not apply to WordPress), unpacked from the pack-modules zips
  modules/share/public/share.html, modules/intake/public/intake.html
  modules.lock.json         {id: {version, sha256}}: what was bundled
  uninstall.php             deletes fluxfiles_license_key, fluxfiles_module_env, fluxfiles_update_url,
                            the fluxfiles_update_manifest transient, and the user meta
                            fluxfiles_license_notice_dismissed
```

The modules live inside pro's own folder. A WP.org update of `fluxfiles` never touches them,
and a pro update replaces them together with the plugin that gates them.

### 2.5 Version and floor coupling

- **`Requires Plugins: fluxfiles`** (WP 6.5 and later): WP won't activate pro without the free plugin.
- **Pro boot guard.** Pro boots only when all three hold; otherwise it shows a notice and does nothing else:
  1. `defined('FLUXFILES_EXT_API') && FLUXFILES_EXT_API >= 1`
  2. `version_compare(FLUXFILES_VERSION, '1.1.0', '>=')`
  3. `version_compare(FLUXFILES_CORE_VERSION, FLUXFILES_PRO_REQUIRES_CORE, '>=')`, where
     `FLUXFILES_PRO_REQUIRES_CORE` is the max `fluxfiles/fluxfiles` floor across the bundled
     modules' `composer.json`, computed at build.

  On free 1.0.x this is the "standby" state used by the migration (§5).
- **Free side.** A pro whose `FLUXFILES_PRO_EXT_API` is below `FLUXFILES_EXT_API` gets one
  admin notice. Bump `FLUXFILES_EXT_API` only when a hook breaks.

### 2.6 Standalone core and Laravel after the move

- **Standalone.** Modules still load from `vendor/fluxfiles/<id>/src/` through
  `modules-autoload.php` (now delegating to `ModuleAutoloader`), and `fluxfiles update <id>` is
  unchanged.
- **Standalone recipient pages.** They become `GET /api/fm/share/page` and
  `GET /api/fm/intake/page` (`PublicRoutes::page`). `public/share.html` and
  `public/intake.html` become 10-line, feature-free redirect stubs. Each stub sets
  `<meta name="referrer" content="no-referrer">` and calls
  `location.replace('/api/fm/<id>/page' + location.search + location.hash)`. That keeps
  existing `share_base_url` values and issued links working without new nginx rules: every
  documented config already routes `/api/` to `index.php`.
- **Laravel** (`FluxFilesController.php`):
  - `publicLink()` becomes `PublicRoutes::dispatch($which, …, new PublicContext($this->diskManager, $diskConfigs, $this->metaRepo, …, ModuleRegistry::license()))`.
  - `publicPage()` becomes `PublicRoutes::page()`, plus two new `…/page` routes in `FluxFilesServiceProvider`.
  - `retentionRun`, `fixityCheck` and `auditVerify` become the same thin stubs as core (§4).
  - `composer.json` floor shipped as `^1.0.4`; `adapter-core-floor` CI enforces it.

## 3. JWT claims

**No new claims and no new env vars.** Existing rows that change wording only:
`pro_hints`, `allow_legal_hold`, `allow_audit_export`, `retention_rules`,
`share_base_url`/`intake_base_url`, `FLUXFILES_LICENSE_KEY` and `FLUXFILES_UPDATE_URL`
(listed at the end).

## 4. Moving the four locked features

For all four, the route stays in core, Laravel and WP as a **thin stub**. A stub checks the
perm (`audit`), the disk and the path scope, and calls `ModuleRegistry::require()` (501, 402
or 403 as today). It then calls the module method and writes the audit lines the route writes
today. Keeping the audit in the route means the `AuditPolicy` map (`/retention/run` →
`retention_run`, `/fixity/check` → `fixity_check`) and the
`test-audit-coverage-http.php` regex (`$method === 'POST' && $uri === '/api/fm/…'`) still
pass unchanged, so stubs must keep that literal form.

### 4.1 Retention → `legal-hold`

- **Moves.** The body of `FileManager::runRetention()` (`FileManager.php:3257-3342`) becomes
  `LegalHoldModule::runRetention(FileManager $fm, string $disk, bool $dryRun, int $max = 500): array`,
  rebuilt on `visibleFiles()`, `isHeld()` and `removeKeys()`. The report shape is
  byte-identical.
- **Stays in core.** `Claims::$retentionRules` and its parsing (`sanitizeRetentionRules`), plus
  the route stub `POST /api/fm/retention/run`.
- **Removed in Y.** `FileManager::runRetention()`.
- **UI.** The fm.js "Retention" entry stays behind `proGate('allow_legal_hold','legal-hold')`.
- **Tests.** `tests/unit/test-retention.php`, plus the retention cases in
  `test-index-carry-and-bulk.php` and `test-audit-fixes-core-fm.php`, move to the legal-hold
  repo. Core keeps tests for the new seams (`removeKeys` respects holds, owner_only and perm).

### 4.2 Fixity → `audit-export`

- **Moves.**
  - `FixityChecker` becomes `FluxFiles\AuditExport\FixityChecker`, together with
    `WRITE_ACTIONS` and `MANIFEST`. The `_fluxfiles/fixity.json` path is unchanged, so
    existing baselines resume.
  - `FileManager::runFixityCheck()` becomes `AuditExportModule::runFixityCheck(FileManager $fm, string $disk, $auditEntries, int $budget)`.
  - `AuditChain::trustedEntries()`, whose only consumer is fixity, moves too.
- **Stays in core.** `AuditChain::configure/key/canonical/hash/isAuthentic`, the write side,
  which every backend needs to chain entries. Also the route stub `POST /api/fm/fixity/check`
  and the `fixity_mismatch`/`fixity_check` audit lines.
- **Tests.** `test-fixity.php`, plus the FixityChecker cases in `test-audit-fixes-core-meta.php`,
  move to audit-export.

### 4.3 Audit chain verify → `audit-export`

- **Moves.** `AuditChain::verify()` becomes `AuditExportModule::verifyChain(array $entries): array`.
- **Stays in core.** The route stub `GET /api/fm/audit/verify`, keeping its unscoped-token check.
- **Test oracle.** `tests/unit/test-audit-chain.php`, Laravel `test-laravel-db-metadata.php:671,676,801`
  and the DB-backend suites use `verify()` as an oracle for the *write* path. They switch to a
  test-only `packages/core/tests/support/chain-verify.php`. That copy lives in `tests/`, which
  never ships, so the oracle stays in CI without the module.

### 4.4 Share and Intake public handlers → `share`, `intake`

- **Moves.**
  - `handleSharePublic`, `ff_share_payload`, `ff_share_send_bytes`, `ff_share_headers`,
    `ff_content_disposition` and `ff_share_rate_limit` become `FluxFiles\Share\PublicShareHandler`.
    `ShareModule` implements `PublicModuleInterface`.
  - `handleIntakePublic` and `ff_intake_rate_limit` become `FluxFiles\Intake\PublicIntakeHandler`.
  - `public/share.html` and `public/intake.html` become `<module>/public/*.html`. The pages read
    the API base from `<meta name="fm-api-base">`, keeping a `window.__FM_API_BASE__` fallback,
    so no host injects an inline `<script>`.
  - The virus/DLP/webhook wiring inside the intake handler uses `$ctx->license`, not `fromEnv()`.
- **Stays in core.** `api/helpers.php` (the renamed remains of `PublicLinks.php`):
  `ff_str_param`, `ff_public_meta_repo` and `ff_sso_rate_limit`, which `index.php` still uses
  (`:491`, `:563`, `:1502`, `:1608`). Also `ShareGrant`, `ImageToken` and `StreamToken` (token
  primitives, see §11) and the `PublicRoutes` dispatch stubs.
- **Packaging.** `scripts/pack-modules.php` gains `public` in `PACKAGED_PATHS`. Its layout
  check and `UpdateClient::install()` must accept a top-level `public/` beside `src/`.
- **Tests.**
  - The helper half of `tests/integration/test-share-public.php` (traversal, MIME by extension
    and unlock buckets) moves to the share repo.
  - Core keeps the free-core halves of `test-share-http.php`, `test-intake-http.php` and
    `test-intake-notify-http.php` (501 envelope), plus a new X-only "legacy module falls back" case.

### 4.5 Release ordering (core floor rule)

1. **Core X.** Seams, plus the fallbacks: `dispatch()` falls back to `PublicLinks.php`, and the
   stubs use `method_exists($mod,'runRetention') ? $mod->runRetention(…) : $fm->runRetention(…)`
   (same for fixity and verify). X calls the new module methods, so **X is the module floor**,
   per `.claude/CLAUDE.md` rule (1).
2. **Module releases** of share, intake, legal-hold and audit-export, with
   `"fluxfiles/fluxfiles": "^X"`.
3. **Core Y.** Fallbacks and legacy code deleted. An old module answers 501 `module_update_required`.

**New error code:** `module_update_required` (501, params `{module}`). It needs keys in all
16 `packages/core/lang/*.json`.

## 5. Migration for existing installs

> **Superseded by the owner decision at the top:** there are no existing paid WordPress users,
> so §5.2 (bridge 1.0.5) and the update-server parts of §5.3 are not built. They are kept as
> the record of the option that was rejected. §5.4 (links already issued) still applies.

### 5.1 Who is affected

These are GitHub-zip sites on 1.0.x, polling the update server for `module=wordpress`. A
subset have modules in `wp-content/plugins/fluxfiles/vendor/fluxfiles/<id>/`. Any WP upgrade
of the free plugin (the old updater included) deletes that folder, which is the bug being fixed.

### 5.2 Bridge release `wordpress-v1.0.5` (old channel, last one with the updater)

1. **New links.** Mints new-form links `get_rest_url(blog, 'fluxfiles/v1/api/fm/<id>/page') + ?token=`.
   This needs core X's `PublicRoutes::page`. The old `public-link.php` keeps serving links
   minted before.
2. **Legacy-module check.** `FluxFilesPlugin::legacyModules()` lists `vendor/fluxfiles/*/src`.
   `FluxFilesUpdater::inject()` **withholds** a manifest flagged `handover: true` while
   `legacyModules() !== []` and `fluxfiles-pro` isn't active. A dismissible notice says:
   "Install FluxFiles Pro (from your account) before updating; your licence key carries over."
3. **Pro on the bridge.** Pro activated on 1.0.5 runs in standby (§2.5): it doesn't boot, but
   its presence releases the hold.

### 5.3 Handover release `wordpress-v1.1.0` (= the WP.org build)

- **Update server** (`docs/update-server.example.php`):
  - The catalogue's `wordpress` entry gains an optional
    `bridge: {version:"1.0.5", zip, sha256, requires_php}`.
  - It serves the bridge when the request's `current` is below `bridge.version`, otherwise
    1.1.0, with `handover: true` in the signed payload. No site skips the bridge.
  - A new product `wordpress-pro` is served to any verified licence with at least one module
    and `updatesAllowed()` (`$ANY_LICENSE_MODULES`). `licensed('wordpress-pro')` is false for
    every real key, so it can't use the module path.
- **The update itself.** 1.1.0 has no `Update URI` and no updater. From then on WP core asks
  api.wordpress.org for slug `fluxfiles`. **Precondition:** the WP.org listing is live before
  the channel flips to 1.1.0. Otherwise sites are stranded with no update source.
- **Licence carry-over.** Automatic: pro reads the same `fluxfiles_license_key` and
  `fluxfiles_module_env` options, and the free `uninstall.php` never deletes them.
- **Manual-zip notice.** A site that skips the bridge by uploading the 1.1.0 zip by hand loses
  `vendor/fluxfiles/`. It gets one dismissible notice, shown only when `fluxfiles_license_key`
  is non-empty and pro is absent: "Your paid modules now ship in FluxFiles Pro."

### 5.4 Links already issued

- Share links are capped at 30 days (`ShareModule::MAX_TTL`) and Intake links at 90 days
  (`IntakeModule::MAX_TTL`). Tokens are signed with `fluxfiles_secret`, which is unchanged,
  and records stay in storage, so only the **URL path** is at risk.
- **New-form links** (from 1.1.0 on) hit REST and keep working on every version.
- **Old-form links** (`/wp-content/plugins/fluxfiles/public-link.php?page=…&token=…&blog=…`) —
  **REMOVED, see decision 2: the shim does not ship and no such links exist.** Superseded plan:
  1.1.0 ships `public-link.php` as a **WordPress-free redirect**. It loads nothing, reads only
  `page` (whitelist `share|intake`), `token` and `blog`. It answers `302` to
  `dirname(REQUEST_URI path, 4) . '/?rest_route=/fluxfiles/v1/api/fm/<page>/page&…'`, with
  `Referrer-Policy: no-referrer` and `Cache-Control: no-store`. `?rest_route=` works under any
  permalink setting. On multisite the shim passes `blog` through unchanged (`&blog=N`) and the
  REST page route resolves it: for a blog other than the current one it validates the id with
  `publicLinkRestBase()` and answers a `302` to that blog's own `<id>/page` URL (bad, deleted,
  archived or spam blog: `404`); the shim itself stays WordPress-free.
- **Removal.** The redirect is deleted in the first free release at least 90 days after
  1.1.0 (tracked in §10, phase 8).
- **Known gap.** A site whose `WP_CONTENT_URL` is on another host would mis-resolve the
  relative redirect. Its old links break, which is no worse than not having the shim.
  The same holds for a plugin dir that is not two levels below the site root (custom
  `WP_PLUGIN_DIR`/`WP_PLUGIN_URL`) and for a server that leaves `PATH_INFO` in `SCRIPT_NAME`:
  the same-origin target is wrong (a broken link, not a security issue).

## 6. Storage layout

Unchanged: `_fluxfiles/fixity.json`, share/intake records, `_fluxfiles/audit*`.

The free plugin's storage root gets a fix for the hard-coded `WP_CONTENT_DIR/fluxfiles`
(`FluxFilesPlugin.php:107`):
- A new site option `fluxfiles_storage_base` is set once on activation: `WP_CONTENT_DIR/fluxfiles`
  if that directory already exists (owner decision: an existing install keeps it), otherwise
  `wp_upload_dir()['basedir'].'/fluxfiles'`, i.e. new installs store under
  `wp-content/uploads/fluxfiles` (main site).
- A `FLUXFILES_STORAGE_DIR` constant and a `fluxfiles_storage_base` filter override it.
- `multisiteSegment()` still appends `sites/<id>`.
- Existing installs keep their path.

## 7. Security

- **Public routes.** Same gate and order as today: secret check, `requireServer` (501/402),
  per-token and two-bucket rate limits, module enforcement, then bytes. The move is verbatim
  and is pinned by the moved tests plus `tests/e2e/test-share-http.php`.
- **Redirect shim.** No WordPress, no filesystem read, `page` from a fixed whitelist, `token`
  and `blog` passed through `rawurlencode`, and a same-origin relative `Location` only (never
  absolute, no host header use). It has no open-redirect surface.
- **Pro updater.** The current guarantees move unchanged: signed manifest, sha256 re-hash,
  https-only endpoint, licence in the `Authorization` header. It filters strictly by its own
  slug and never touches `fluxfiles`.
- **HTTP seam.** `FluxFilesHttpTransport` routes AiTagger and BucketDoctor through `wp_remote_request`.
  `UrlImporter` **keeps** cURL: it needs `CURLOPT_RESOLVE` pinning and `assertConnectedIpSafe()`
  (TOCTOU, see `rules/url-import-exif.md`), which `wp_remote_*` can't do. It gets a
  `// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- DNS-pinned SSRF guard` and
  a readme note.
- **`proc_open`.** `ProcessEnv::minimal()` stays. The calls are optional (gs, ffmpeg) and
  already guarded by binary detection.
- **Error code.** `module_update_required` is new (i18n ×16).

## 8. Build and release

### 8.1 Free zip (`scripts/build-wordpress.sh`)

- **`packages/wordpress/.distignore`**, consumed by `rsync --exclude-from`: `tests/`,
  `composer.json`, `composer.lock`, `.gitignore`, `*.md` except `readme.txt`, `.wordpress-org/`,
  `phpcs.xml*`, `.distignore`.
- **Core files the plugin never runs, not copied:**
  - `api/index.php`, `api/LicenseManager.php`, `api/UpdateClient.php`
  - `api/OidcDiscovery.php`, `api/SsoStateToken.php`, `api/SsoBootToken.php`, `api/DemoMode.php`,
    `api/TusServer.php` (tus is core-only, not proxied)
  - `embed.php`, `modules-autoload.php`, `config/disks.php`, `public/share.html`, `public/intake.html`

  Excluding these, rather than adding `ABSPATH` guards, settles the direct-access finding.
  `api/Db/` **stays** (`FluxFilesDbMetadataHandler` and `FluxFilesCli` use it).
- **Composer.** The temp `composer.json` drops `autoload.files` before `composer install`.
  Otherwise `vendor/autoload.php` would `require` the missing `modules-autoload.php`.
- **AWS SDK trim.** Add `"extra": {"aws/aws-sdk-php": ["S3", "Sts"]}` and
  `"scripts": {"pre-autoload-dump": "Aws\\Script\\Composer\\Composer::removeUnusedServices"}`
  to `packages/core/composer.json`. This only affects root installs (standalone and this
  build), never library consumers. Core references only `Aws\S3`, `Aws\Exception`, `Aws\Result`,
  `Aws\Middleware` and `Aws\CommandInterface`. `Sts` is kept for assume-role and web-identity
  credential chains (open question 4). Measure the result; target ≤ 5 MB zipped.
- **Vendor clean.** Extend the existing find/rm with `docs`, `examples`, `.github` and `*.md`,
  but keep every `LICENSE*`/`NOTICE*`.
- **CodeMirror.** Owner decision: the WP build loads **WordPress's bundled CodeMirror**
  (`wp-includes/js/codemirror/`, guideline 13) and does not ship `assets/vendor/codemirror/`
  (open question 2).
- **Stamp** `FLUXFILES_CORE_VERSION` from `git describe --tags --abbrev=0 --match 'core-v*'`
  (prefix stripped). **Done** (phase 4 prep): `stamp-wp-version.py` 3rd argument; release builds fail without a reachable core tag (the workflow checks out with `fetch-depth: 0`) or when `packages/core` changed since it.

### 8.2 CI guard: `scripts/check-wp-dist.php`

The guard is PHP, like `pack-modules.php`. It runs as a new job `wp-dist-guard` in
`test.yml`, and before publishing in `wordpress-release.yml`, replacing the current "Verify
the ZIP contents" step. It unzips `build/fluxfiles.zip` and **fails** on any of the following:
- **Forbidden strings anywhere** outside `vendor/`: `Update URI`, `pre_set_site_transient_update_plugins`,
  `upgrader_pre_download`, `plugins_api`, `wp-load.php` (any `require`/`include`), and
  `LicenseManager` as a class definition.
- **Forbidden symbols:** `function runRetention`, `function runFixityCheck`, `class FixityChecker`,
  `function verify(` in `api/AuditChain.php`, `function handleSharePublic`, `function handleIntakePublic`.
- **Forbidden paths:** `vendor/fluxfiles/`, `modules-autoload.php`, `api/index.php`, `embed.php`,
  `config/disks.php`, `public/share.html`, `public/intake.html`, `api/LicenseManager.php`,
  `api/UpdateClient.php`, `tests/`, `.git*`, `phpunit*`.
- **`curl_init`** outside `api/UrlImporter.php` and `vendor/`.
- **Size** over 8 MB.
- **readme.txt rules:** more than 5 tags; a short description over 150 characters;
  `Tested up to` major below the current major (fetched from
  `api.wordpress.org/core/version-check/1.7/`, skipped offline); `Stable tag` not equal to the
  header `Version`; or missing `== External services ==` / `== Source & build ==` sections.

It also asserts the required paths the release workflow checks today, minus `modules-autoload.php`.
`public-link.php` (removed) is a forbidden path.

### 8.3 Plugin Check in CI

A job `wp-plugin-check` uses `wordpress/plugin-check-action@v1` with `build-dir: build/fluxfiles`.
The action boots `@wordpress/env` itself. It fails on errors. Warnings are reported, and an
allow-list file `packages/wordpress/.plugin-check-ignore` records each with a reason (proc_open,
the UrlImporter cURL, vendor/). It runs on PRs touching `packages/wordpress/**` or core `api/**`.

### 8.4 WP.org SVN deploy

In `wordpress-release.yml`, after the GitHub release:
- Use `10up/action-wordpress-plugin-deploy@stable` with `SLUG: fluxfiles`,
  `BUILD_DIR: build/fluxfiles`, `ASSETS_DIR: .wordpress-org` (repo root), `VERSION` from
  the tag, and secrets `SVN_USERNAME`/`SVN_PASSWORD`.
- Gate it with `if: vars.WPORG_DEPLOY == 'true'` until the listing is approved.
- Add `10up/action-wordpress-plugin-asset-update@stable` on master for readme- or asset-only changes.
- The GitHub zip and the SVN tag are built once, from the same `build/fluxfiles`.
- Remove the `wordpress-catalogue.json` step. The old-channel `wordpress` entry is now
  maintained only for the bridge (§5.3).

### 8.5 `fluxfiles-pro` release

- **Packing.** `scripts/pack-wordpress-pro.php` (public repo, like `pack-modules.php`) builds
  from the **pro repo tag** (`v1.0.0`; own repo, so the ≤ 3-tags rule doesn't apply):
  1. Run `pack-modules.php` for the 12 bundled ids (all of `ModuleRegistry::$map` except `sso`)
     and unzip each into `modules/<id>/`.
  2. Copy `api/LicenseManager.php` and `api/UpdateClient.php` from the pinned core tag.
  3. Compute `FLUXFILES_PRO_REQUIRES_CORE`.
  4. Write `modules.lock.json`.
  5. Do a reproducibility double-build and compare.
  6. Write the catalogue entry `wordpress-pro`.
- **Catalogue.** `EXTERNALLY_BUILT` gains `wordpress-pro`, so `pack-modules.php` preserves the entry.
- **Shipping modules.** Modules ship *inside* the pro zip; the alternative is open question 1.
- **Pro repo CI.** Pro's own smoke checks that the `lib/` copies are byte-identical to the
  pinned core tag, that `REQUIRES_PHP` matches the header, and that the boot guard works.

### 8.6 Assets: `.wordpress-org/` (repo root, not `packages/wordpress/`)

`banner-772x250.png`, `banner-1544x500.png`, `icon-128x128.png` and `icon-256x256.png` exist
already (`banner.svg` and `icon.svg` are sources; WP.org does not use SVG banners). Still
missing: `screenshot-1..4.png`, which follow the readme captions and are generated by
extending `scripts/landing-screenshots/`.

## 9. readme.txt rewrite outline

```
=== FluxFiles ===
Contributors: thaipc   (the WP.org account `thaipc` now exists and its profile is live)
Tags: file manager, amazon s3, cloudflare r2, media library, file upload
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Multi-storage file manager for WordPress: local disk, Amazon S3 and Cloudflare R2, with image optimization, search and media library integration.
```

The short description is 145 characters. The sections:
- **Description.** Free features only. Add one line: "An optional FluxFiles Pro add-on (sold
  separately) adds share links, upload portals and compliance tools." Remove the
  `vendor/fluxfiles/` instructions (`readme.txt:43-46`). Use **fluxfiles.dev** for the pricing
  link: it's used in `ComplianceScorecard.php:25`, `public/index.html` and `bin/fluxfiles`,
  while `fluxfiles.io` appears only in `readme.txt:43,102`.
- **Installation**, **FAQ**, with the Module engines FAQ dropped (that's pro now), and **Screenshots** (4).
- **External services.** List each service, what is sent and when, and its terms and privacy
  links. **Verify every URL before submitting.**

  | Service | When | What is sent | Terms / Privacy |
  |---|---|---|---|
  | Amazon S3 | an admin configures an S3 disk | file bytes, object keys and metadata tags; Bucket Doctor probes | aws.amazon.com/service-terms · aws.amazon.com/privacy |
  | Cloudflare R2 | an admin configures an R2 disk | as for S3 | cloudflare.com/terms · cloudflare.com/privacypolicy |
  | Your SFTP server | a developer mints a BYOB SFTP token | file bytes, paths | your own |
  | AI tagging provider: Anthropic, OpenAI, Google Gemini, OpenRouter, Groq, Mistral, xAI, or a compatible or local endpoint | only when an admin sets a provider and API key, and the token has `ai_auto_tag` | a downscaled image (≤ 1024 px), the prompt and the API key; tags and alt text come back | each provider's API terms and privacy policy, one row each |
  | Any URL a user imports | only with `allow_url_import` | an HTTP GET to that URL (SSRF-guarded) | n/a |
  | Operator-run embeds: ttyd/gotty/wetty, Stirling-PDF, Collabora/OnlyOffice, DocuSeal | only when `terminal_pty_url`, `pdf_tools_url`, `office_url` or `esign_url` is set | an iframe load; `office_url`/`esign_url` receive the selected file's URL | your own instance |

  Also say explicitly: no telemetry, no update or licence calls from this plugin.
- **Source & build.**
  - Repo: `https://github.com/thai-pc/fluxfiles`.
  - `scripts/build-wordpress.sh` builds the zip.
  - `assets/fm.js` is the readable source of `fm.min.js` (`scripts/build-ui.sh`).
  - Alpine.js `<version>` (upstream URL); CodeMirror `<version>` (upstream), or a note that WP's copy is used.
  - PHP dependencies come from `packages/core/composer.lock`.
- **Changelog** and **Upgrade Notice 1.1.0.** "Paid modules moved to the FluxFiles Pro add-on;
  install it before updating if you use Share, Intake or compliance features."

## 10. Phased plan (separate PRs, in dependency order)

| # | PR | Repo(s) | Release | Depends on | Tests |
|---|---|---|---|---|---|
| 0 | Owner: ~~create the WP.org account~~ done: `thaipc` exists and its profile is live (2026-11-06); still to do: confirm the `fluxfiles` slug is free (not in the directory as of 2026-11-06), make assets, create SVN secrets | — | — | — | — |
| 1 | **Core seams and fallbacks** (§2.2, §4.5 step 1): interfaces, `ModuleRegistry::license()`, `ModuleAutoloader`, `PublicContext`/`PublicRoutes`, `HttpTransportInterface`, `Log`, FileManager seams; `/api/fm/<id>/page` routes; `module_update_required` ×16; `pack-modules.php` `public` path and the `UpdateClient` layout; AWS trim in `composer.json` | public | **core-vX** (with laravel-v… if touched; ≤ 3 tags per push) | — | unit: `NoLicense`, resolver, `ModuleAutoloader` (kebab cases), `removeKeys` (holds, owner_only, perm), `visibleFiles` (hidden, reserved); integration: `dispatch` with a fake `PublicModuleInterface` module (gate order 501→402→handler) and with a legacy module (fallback); unchanged: `test-audit-coverage-http`, `test-config-doc`, the free-core e2e (501s), DB suites |
| 2 | **Module moves** (§4.1-4.4): legal-hold `runRetention`; audit-export `FixityChecker`, `runFixityCheck`, `verifyChain`, `trustedEntries`; share/intake public handlers and pages (meta API base) | **private ×4** | share, intake, legal-hold, audit-export (each a new tag, floor `^X`; check by hand per CLAUDE.md rule 1); then `pack-modules.php` | 1 | the moved core tests, now in each repo; modules-live e2e (`tests/modules-live/**/share-intake.spec.ts`, `records.spec.ts`) against core X plus the new modules on standalone, Laravel and WP |
| 3 | **DONE (core-v1.1.0)**: adapters switched to module dispatch first (laravel-v1.0.3, WP `servePublicPage`), then core removal. **Core removal** (§4.5 step 3): delete the legacy code, `PublicLinks.php` → `api/helpers.php`, redirect-stub `public/*.html`, stubs for old modules → `module_update_required`, test oracle `tests/support/chain-verify.php`; Laravel controller/routes and floor `^Y`; docs (`.claude/rules/audit-log.md`, `.claude/api-map.md`, `.claude/CLAUDE.md` paid-module and PHP-floor lines, `ACTIVATE.md`, `RETENTION-LEGAL-HOLD-DESIGN.md`) | public | **core-vY**, laravel-v… | 2 released | core suite with no modules: 501 everywhere; with an old-module fixture: `module_update_required`; Laravel smoke; `adapter-core-floor`; browser `share-landing`/`intake-landing` through the redirect stub |
| 4 | **FOLDED INTO 5 (owner decision, no existing paid WP users): no bridge 1.0.5, no hold/handover, no update-server bridge.** Preparation done in the 1.1.0 work: licence seam, new-form links (+ REST `?blog=` redirect), `FLUXFILES_CORE_VERSION` stamp. ~~**WP bridge 1.0.5** (§5.2): new-form links, `legacyModules()`, updater hold on `handover`, pro standby detection; update-server `bridge` and `$ANY_LICENSE_MODULES`~~ | public | ~~wordpress-v1.0.5~~ (none) | core X | wp-smoke: link URL form (single site, multisite, plain permalinks), `?blog=` redirect incl. bad blogs, the licence seam, the core-version stamp; the old shim still serves. `$ANY_LICENSE_MODULES` is deferred to phase 7 |
| 5 | **WP free 1.1.0, functional** (§2.3, §5.3-5.4): delete `FluxFilesUpdater`, the `Update URI`, the licence/Module engines/expiry code, `public-link.php` → redirect shim, `modules-autoload` usage; add the hooks and `FLUXFILES_EXT_API` (`FLUXFILES_CORE_VERSION` is already done, §8.1); thin REST stubs; page routes; `pro_hints=false` default; `fluxfiles_storage_base`; `uninstall.php` | public | (tagged with 6) | core Y | wp-smoke: invert test `:954` (no `Update URI`); remove the updater tests `:631-940` and `:1748-1759` (they move to pro); hooks fire in order; default `NoLicense` → a paid route gives 501, and fake module plus fake provider → 200; page route emits the meta tag, not `<script>`; shim redirect math (subdir, multisite `blog`, a bad `page` → 404); every `register_setting` option is in `uninstall.php` and none of pro's are; storage base keeps an existing dir |
| 6 | **WP hygiene, build and CI** (§8.1-8.4, §9, the list below): readme, `.distignore`, `check-wp-dist.php`, Plugin Check job, 10up deploy (flagged off), assets | public | **wordpress-v1.1.0** | 5 | `wp-dist-guard` (CI), `wp-plugin-check` (CI), `tests/apps` WP e2e (`npm run e2e:wp`) against the trimmed zip |
| 7 | **fluxfiles-pro v1.0.0** (§2.4, §8.5), 12 modules, + `pack-wordpress-pro.php` + catalogue | **private (new repo)** + public script | pro `v1.0.0` | 5, 2 | pro smoke (moved updater and licence-notice tests from `test-wp-smoke.php`/`test-wp-license-notice.php`; boot guard on 1.0.5/1.1.0; module autoload from `modules/`; `sso` absent); modules-live WP spec with pro instead of `vendor/` |
| 8 | **Launch**: submit the 1.1.0 zip to WP.org; on approval set `WPORG_DEPLOY=true` and redeploy; then flip the old channel to 1.1.0 (`handover`). Later, the first free release ≥ 90 days after 1.0.5 deletes the `public-link.php` shim and the `check-wp-dist.php` exemption | public | — | 6, 7 | manual: 1.0.4 → 1.0.5 → 1.1.0 on a staging site with and without modules; a share link minted on 1.0.4 opens after 1.1.0 |

**Phase-5 notes (from the phase-1 review).**
- `FluxFilesHttpTransport::request()` must pass `redirection => 0`. `HttpTransportInterface` promises
  that redirects are not followed (`CurlTransport` does the same); `wp_remote_request` follows
  up to 5 by default.
- The dist guard's rule "no `curl_init` outside `api/UrlImporter.php`" (§8.2) must also allow
  `api/CurlTransport.php` (the default transport, which a host may leave in place) and the
  DNS-pinned BYOB path in `api/BucketDoctor.php` (`httpGet()` with a pinned IP), which needs the
  cURL handle for `CURLOPT_RESOLVE` and `assertConnectedIpSafe()`.

Private-repo phases (2 and 7) need their own releases. Their core floor is **X** (rule 1:
X is the first core whose `index.php` calls the new methods). Floors can't be CI-guarded,
because the packages are gitignored, so check them by hand. A tag isn't a release:
`pack-modules.php` / `pack-wordpress-pro.php` must rebuild the zips and the catalogue.

### WP coding-hygiene fixes by file (phase 6 unless noted)

- `fluxfiles.php`
  - Drop `Update URI`, the updater require and `boot()` (phase 5).
  - Add `Text Domain: fluxfiles` and `License URI`.
  - Escape the two inline notices (`:31-39`, `:58-64`) and make them translatable.
- `includes/FluxFilesAdmin.php`
  - Licence, expiry notice and Module engines go to pro (phase 5).
  - `sanitize_text_field(wp_unslash(...))` on its 3 superglobal reads.
  - `esc_html__`/`esc_attr` on output.
  - The heredoc JS at `:483` leaves with the notice.
- `includes/FluxFilesApi.php`
  - 9 superglobal reads: `wp_unslash` plus a type-appropriate sanitiser, or a `phpcs:ignore`
    with a reason where the raw value is a token or binary.
  - `error_log` at `:571` and `:578` goes through `FluxFilesPlugin::debugLog()` (gated on `WP_DEBUG`).
  - Inject `FluxFilesHttpTransport` into AiTagger and BucketDoctor (`:871`, `:1493`, `:2076`).
  - Set the `Log::setSink` to `debugLog`.
- `includes/FluxFilesShortcode.php:74-76`: heredoc HTML with an inline `<script>` → `wp_register_script('fluxfiles-sdk')`, `wp_add_inline_script()`, and escaped attributes.
- `includes/FluxFilesMediaButton.php:108`: inline `<script>` → `wp_add_inline_script` on an enqueued handle.
- `includes/FluxFilesRateLimiterStorage.php:66`: `error_log` → `debugLog`.
- `includes/FluxFilesCli.php`: `phpcs:ignore` with reasons (CLI context).
- `includes/FluxFilesPlugin.php`
  - `storagePath()` → `fluxfiles_storage_base` (§6).
  - Edition presets → filter.
  - Licence and module-env code → pro (phase 5).
  - `pro_hints` default false.
- `uninstall.php`: new; free options and DB tables (`FluxFilesDbSchema`) only. It never deletes
  storage unless the opt-in `fluxfiles_delete_data_on_uninstall` is set.
- Shipped core files:
  - `AiTagger` and `BucketDoctor` use the transport.
  - The shipped `error_log` calls go through `Log`.
  - `UrlImporter` cURL is justified (§7).
  - `ComplianceScorecard.php:25` pricing domain: confirm `fluxfiles.dev`.
- `assets/vendor/codemirror/`: add the `LICENSE`, or replace with WP's copy (open question 2).

## 11. Risks and open questions

1. **Modules inside the pro zip vs installed separately.** Recommendation: inside, so there's
   one WP-native update and they can't drift from the gate. The cost is that every buyer gets
   every module's code, which is already true of anyone patching a licence check.
   Alternative: per-edition pro zips chosen by the update server from `modules[]`.
2. **Bundled CodeMirror vs WP's.** *Decided (owner, 2026-11-06): the WP build uses WP's.* WP ships CodeMirror 5 at `wp-includes/js/codemirror/`, and
   reviewers dislike duplicating core libraries. The proposal is a `<meta name="fm-codemirror-base">`
   that the WP host sets to `includes_url('js/codemirror/')`; modes WP's bundle lacks fall back
   to plain text. Verify the mode list against fm.js's 23 modes before deciding.
3. **Reviewer judgement calls.** None of these can be settled before review:
   - The REST-served iframe admin UI and the HTML page routes served from REST.
   - The paid-route thin stubs (501 without pro). The fallback is to move their registration
     to pro via `fluxfiles_rest_routes`.
   - The inert paid-module UI shells in `fm.js` behind `proGate` (hidden when framed, as in WP).
   - `proc_open` (gs, ffmpeg).
   - The UrlImporter cURL.
   - ~~The transitional redirect `public-link.php`.~~ Removed before submission.
4. **AWS trim.** Does `["S3"]` alone break any credential provider core's `S3Client` config
   reaches (Sts or SSO for assume-role)? Test on LocalStack and with web identity before
   dropping `Sts`.
5. **Zip size limit.** No current WP.org figure could be confirmed; a 2017 Trac ticket cites
   25 MB for the review uploader. Today's zip is 12 MB, mostly the AWS SDK. The CI cap is
   8 MB; measure after the trim.
6. **Slug.** `fluxfiles` isn't in the directory, and the slug comes from `Plugin Name`. Approval
   makes it permanent. It's reserved only on submission, so submit as soon as phase 6 is green.
7. **`ShareGrant`, `OidcDiscovery`, `SsoStateToken`, `SsoBootToken`.**
   - `ShareGrant` is used only by share and is a token primitive like `ImageToken`. Recommendation:
     keep it in core for v1.
   - The three SSO classes leave the WP zip, but in standalone core they still back the paid SSO
     routes. Moving them into `sso` would apply decision 2's principle consistently; that's a
     separate small PR, not needed for WP.org.
8. **Handover timing.** The old channel may flip to 1.1.0 only after the WP.org listing is
   live. Until then 1.1.0 ships on GitHub only, and existing sites stay on their current release.
9. **Standalone operators on old modules after core Y** get `module_update_required` until
   they run `fluxfiles update <id>`. This needs a loud CHANGELOG entry plus an `OPERATIONS.md` note.

Sources:
- WordPress.org detailed plugin guidelines (5, 6, 7, 8 and add-ons): https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- Slug rules: https://wordpress.org/plugins/developers/add/
- Current WP version (7.1.4): https://api.wordpress.org/core/version-check/1.7/
- The 25 MB review-uploader figure: https://meta.trac.wordpress.org/ticket/3066

---

## Claims to add to `docs/reference/CONFIG.md`

**No new claims or env vars.** Wording-only edits to existing rows:

- `pro_hints`: "The WordPress free plugin mints `false` by default (override via the
  `fluxfiles_token_overrides` filter)."
- `allow_legal_hold`, `retention_rules`: "`/retention/run` is implemented by the legal-hold
  module; core only routes and audits."
- `allow_audit_export`: "`/audit/verify` and `/fixity/check` are implemented by the
  audit-export module."
- `share_base_url`, `intake_base_url`: "Canonical page is `/api/fm/share/page` /
  `/api/fm/intake/page`; `/public/share.html` and `/public/intake.html` are redirect stubs and
  stay valid."
- `FLUXFILES_LICENSE_KEY`: "WordPress: entered in FluxFiles Pro → Settings (option
  `fluxfiles_license_key`); the free plugin never reads it."
- `FLUXFILES_UPDATE_URL`: "WordPress: used by FluxFiles Pro only; the free plugin updates from
  WordPress.org."
- **Error code** (in the error-code list, not a claim): `module_update_required` (501): the
  installed module predates the core API it needs; update the module.
