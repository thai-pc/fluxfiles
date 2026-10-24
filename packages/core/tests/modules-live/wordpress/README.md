# Paid modules, live, through the WordPress plugin

The same idea as the standalone rig one level up (real modules, a real licence,
real engines, nothing mocked), but every request goes through the **WordPress
plugin**: the `[fluxfiles]` shortcode embeds the UI, the SDK hands it a
WP-minted token, and every `/api/fm/*` call is proxied by `FluxFilesApi` under
`/wp-json/fluxfiles/v1/`.

What is real, and how it is configured (the operator's way, not the test's):

| Piece | How the rig sets it up |
|---|---|
| Plugin | `scripts/build-wordpress.sh`, run in a **staged copy** of the repo (`.stage/repo`), so it never touches the repo's `build/` (wp-env mounts that) |
| Modules | each module dir zipped like `pack-modules.php` does (`composer.json`, `LICENSE`, `README.md`, `src/`) and installed with core's own `UpdateClient::install()` into `<plugin>/vendor/fluxfiles/<id>/`, which is where `docs/guides/ACTIVATE.md` tells a WordPress customer to unzip them. Built from the module **worktrees**, not their tags |
| Licence | pasted into the `fluxfiles_license_key` **option** (Settings → FluxFiles → Licence). `FLUXFILES_LICENSE_KEY` is deliberately not in the container env, and `proxy.spec.ts` asserts that |
| Module claims | `fixtures/fluxfiles-live.php`, an mu-plugin on the `fluxfiles_token_overrides` filter (the only supported way: the plugin has no settings UI for module claims). It uses `edition: enterprise` plus tuning from `fixtures/overrides.json` |
| `audit` permission | ticked in Settings → FluxFiles → Default Permissions **through the real settings form** (`grantAuditViaSettings()`) |
| Second disk (Backup) | the plugin's S3 options, pointed at LocalStack |
| Module engine config | the plugin's `fluxfiles_module_env` option (Settings → FluxFiles → Module engines; `fixtures/module-env.json`, written by the entrypoint): AI Vision provider/endpoint, DLP endpoint, webhook allow-internal. `FLUXFILES_C2PA_MANIFEST` stays a real container env var to prove env and option coexist (`proxy.spec.ts`) |

WordPress runs on `ff-live-wp:latest` = `ff-modules-live:latest` (PHP 8.2 + ClamAV
clamd + Tesseract + c2patool + Ghostscript) + mysqli + wp-cli, served by `php -S`
(8 workers) with a WP router, plus MariaDB. It reuses the base rig's `ff-ai`,
`ff-hooks`, LocalStack and Presidio services under its own compose project.

## Run

```bash
cd packages/core/tests/modules-live
npm install && npx playwright install chromium       # once
bash stage.sh && docker compose build core            # once: builds ff-modules-live:latest
cd wordpress
../node_modules/.bin/playwright test                  # stage → build → up → test → down -v
FF_LIVE_KEEP=1  ../node_modules/.bin/playwright test  # leave the stack up afterwards
FF_LIVE_REUSE=1 ../node_modules/.bin/playwright test  # reuse a running stack (fast loop)
docker compose -p ff-live-wp -f docker-compose.wordpress.yml down -v   # manual teardown
```

Host ports: 8390 (WordPress, admin/password), 8393 (webhook receiver). Like the
base rig this needs the 13 private module checkouts and the vendor's local
licence-signing key, so it is a **local** suite, not CI. `stage.sh` never prints
the key, the licence or `.stage/wp.env`. `.dockerignore` keeps the env file, the
licence and the repo copy out of the image.

The page uses a full-width block group around the shortcode. In the theme's
default content column the iframe is under 768px wide and the UI switches to its
mobile layout (action sheet instead of a context menu, collapsed toolbar).

Not covered, because WordPress does not proxy it: the SSO bridge (`/api/fm/sso/*`,
a standalone-UI login screen by design).
