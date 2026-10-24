# Paid modules, live — through the Laravel proxy

The same idea as the base rig (`../README.md`), but the browser only ever talks to
a **real Laravel 12 app** running `fluxfiles/laravel` in proxy mode: the host page
renders `<x-fluxfiles :overrides>` (the adapter mints the JWT server-side from its
own named claim overrides), the SDK comes from Laravel's `/fluxfiles.js`, the UI from
Laravel's `/public/index.html`, every `/api/fm/*` call goes through
`FluxFilesController`, and the share/intake recipient pages + their public API are
Laravel's too.

The app is installed the way a customer installs it: core + the adapter via
Composer (path repos, **copied** into `vendor/`, versions = the newest real
`core-v*`/`laravel-v*` tags), each module unpacked into `vendor/fluxfiles/<id>/` the
way `fluxfiles update <module>` does — core's own `modules-autoload.php` must find
them, there is no test autoloader. No `FLUXFILES_BASE_PATH`. Engines (clamd,
Tesseract, c2patool, Ghostscript) run in the Laravel container because the modules
shell out from the PHP that runs them; the image is `FROM ff-modules-live:latest`.

| Spec | Covers |
|---|---|
| `share-intake.spec.ts` | share create/list/analytics/revoke, password unlock, intake create/upload/list/analytics/revoke — recipient side on Laravel's public routes; claim gate 403 |
| `records.spec.ts` | versioning list/restore, webhooks on proxied writes + `webhooks/test`, legal hold place/status/list/release + `legal_hold_blocked` audit, `retention/run`, audit export/verify/fixity/purge |
| `engines.spec.ts` | AI Vision (3 ops), OCR, virus (EICAR, on-demand scan, 409 on unscannable paths, `virus_blocked` audit), DLP (`dlp_blocked`), backup → S3, C2PA sign/verify |
| `platform.spec.ts` | SDK + translated UI on a default install, license, the routes deliberately not proxied (SSO, tus, metadata export/import), `config:cache` |

## Run

```bash
cd packages/core/tests/modules-live
npm install && npx playwright install chromium     # once (shared with the base rig)
npx playwright test -c laravel/playwright.config.ts                  # stage → build → up → test → down
FF_LIVE_KEEP=1  npx playwright test -c laravel/playwright.config.ts  # leave the stack up
FF_LIVE_REUSE=1 npx playwright test -c laravel/playwright.config.ts  # reuse a running stack
docker compose -p ff-live-laravel -f laravel/docker-compose.laravel.yml down -v   # manual teardown
```

Needs what the base rig needs (Docker, the 13 module checkouts, the local license
signing key). `laravel/stage.sh` copies core + `packages/laravel` + the module sources
into `laravel/.stage/src` (never the dev `.env`, never `vendor/`) and writes
`laravel/.stage/laravel.env` (license + engine endpoints) — it prints neither. The
base image is built first if it's missing. Host ports: **8290** (Laravel — host page,
UI, API, public pages) and **8293** (webhook receiver). Compose project
`ff-live-laravel`, so it runs alongside the base stack.
