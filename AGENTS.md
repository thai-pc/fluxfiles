# AGENTS.md

Guidance for AI coding agents working in this repo. This is the tool-agnostic
entry point; the deeper canonical notes live in `.claude/` —
[`CLAUDE.md`](.claude/CLAUDE.md), [`architecture.md`](.claude/architecture.md),
[`development.md`](.claude/development.md), [`api-map.md`](.claude/api-map.md).
Read those for detail. For the human-facing monorepo map (engine ↔ adapters, the
three integration patterns, the publish topology), see [`docs/reference/ARCHITECTURE.md`](docs/reference/ARCHITECTURE.md).

## What FluxFiles is

A standalone, embeddable PHP file manager (PHP **≥ 8.1**). The core exposes a PHP
API + a zero-build (Alpine.js + htmx) frontend that hosts embed by iframe/SDK.
Adapters ship for Laravel, WordPress, React, Vue/Nuxt, CKEditor 4, TinyMCE,
Summernote, plus a vanilla SDK, server-side token SDKs for Node and Python, and an
MCP server for AI agents.

Capabilities: Local / AWS S3 / Cloudflare R2 (and any S3-compatible store) / SFTP via
Flysystem v3; JWT auth with rich claims (perms, disk, path prefix, quota,
owner-only, allowed extensions, BYOB); file ops + cross-disk copy/move; soft-delete
of files & folders (trash/restore, storage-resident); metadata that travels with
storage; image WebP variants + crop + **on-demand WebP/AVIF** (`/api/fm/img`); optional
AI tagging; inline media preview with presigned-URL auto-refresh + **gated local
streaming** (`/api/fm/stream`, Range); URL import (`/api/fm/import-url`, SSRF-guarded);
**resumable tus uploads** (`/api/fm/tus`, core-only); chunked S3 uploads + presigned
PUT/POST; duplicate finder; opt-in `strip_exif` and PDF/video posters; i18n (16 locales,
RTL); rate limiting; an HMAC-chained audit log; Bucket Doctor diagnostics.

Two byte-serving routes are pre-auth, authenticated by a short-lived per-file
query-string token (an `<video>`/`<img>` can't send headers): `/api/fm/stream`
(gated local/SFTP media, `StreamToken`/`RangeStreamer`, opt-in via
`FLUXFILES_LOCAL_PRIVATE`) and `/api/fm/img` (on-demand WebP/AVIF, `ImageToken`,
cached in `_variants/`). Both mint only when a stream secret is wired
(`FileManager::setStreamSecret`) — the standalone app always does this, and the
Laravel/WordPress proxy adapters do too, so both routes are fully proxied
(ported from `index.php`'s inline handlers directly into each adapter's
controller, since they aren't extracted into a reusable core file). Per-tenant
claims gate them (`media_preview`/`preview_url_ttl`/`max_preview_mb`/`stream_token_ttl`,
`webp_enabled`/`webp_max_width`/`webp_default_quality`), forwarded by every token helper.

## Repository layout

- `packages/core/` — the PHP package + standalone app. `api/index.php` (router,
  CORS, auth, dispatch), `api/FileManager.php` (file ops + most security checks),
  `api/DiskManager.php` (Flysystem disks), `api/Claims.php` (JWT claims),
  `api/StorageMetadataHandler.php` (metadata/index/audit), `api/Db/` (opt-in DB
  backend), `assets/` (the page serves the committed `fm.min.js`/`fm.min.css`: after
  editing `fm.js`/`fm.css` run `bash scripts/build-ui.sh`; CI fails on a stale pair),
  `public/`, `lang/`, `tests/`.
- `packages/sdk/` — vanilla browser SDK (`FluxFiles` global).
- `packages/node/` — server-side token SDK (`@fluxfiles/node`), mints JWTs in JS.
- `packages/mcp/` — MCP server (`@fluxfiles/mcp`, stdio bin `fluxfiles-mcp`): read-only tools by default, write/trash behind env flags; refuses plain `http://` to a non-local host (`FLUXFILES_MCP_ALLOW_HTTP=1` overrides).
- `packages/python/` — server-side token SDK (`fluxfiles-token` on PyPI), mints JWTs in Python.
- `packages/{react,vue,ckeditor4,tinymce,summernote}/` — JS/TS wrappers (tsup/esbuild + vitest).
- `packages/{laravel,wordpress}/` — PHP adapters (each bundles/uses the core).
- `docs/`, `.github/workflows/test.yml`, `docker/`, `scripts/`.

## Working rules (the architectural grain — keep features fitting it)

- **Stateless. No central DB.** All server state lives in the **JWT (claims)** or
  in the **user's storage** under `_fluxfiles/` (sidecars + JSON index files,
  file-locked). By default there is no database — metadata is `_fluxfiles/meta/{key}.json`
  (local/SFTP) or object metadata (S3/R2); search uses `_fluxfiles/index.json`, folder
  search `dirs.json`, audit `audit.jsonl`. **Exception:** the opt-in
  `FLUXFILES_STORAGE_BACKEND=db` mode (env var, default `json`) moves that
  bookkeeping — metadata, search/folder index, audit log, trash manifest, quota,
  and the rate limiter — into the operator's own self-hosted MySQL/PostgreSQL/SQLite
  instead of JSON files. File bytes stay fully storage-resident either way; it's
  not a JWT claim and not a paid module. See `docs/design/DB-STORAGE-MIGRATION-DESIGN.md`.
- **Authorization = signed JWT claims**; the host app owns identity/policy,
  FluxFiles only enforces. Keep enforcement centralized and consistent (disk,
  perms, path scope via `Claims::scopePath`/`isPathInScope`, owner-only, upload
  size, extension).
- **BYOB**: user S3/R2/SFTP credentials are AES-256-GCM encrypted (HKDF-derived key,
  info `fluxfiles-byob-enc`) inside the JWT and decrypted only at runtime — never
  stored or logged. The same crypto/claim format is mirrored in `@fluxfiles/node`
  and `fluxfiles-token` (PHP↔Node↔Python contract — change all together).
- **Security-first**: SSRF guard on BYOB endpoints, XSS-neutralized uploads
  (CSP sandbox + attachment for html/svg), extension immutability on
  rename/move/copy, `_fluxfiles/`/`_variants/` blocked from list/search/ops,
  external tools (gs/ffmpeg/tesseract/c2patool/clamdscan) run with
  `ProcessEnv::minimal()` so they never inherit secrets.
- Prefer turning a request into a **variant of an existing pattern** over adding a
  new category (DB, public endpoint, realtime server). New storage = a Flysystem
  driver + `DiskManager` case; pipeline steps (AI tag, antivirus) = like
  `AiTagger`; "share" = a narrow short-TTL token, not a stateful public endpoint.
- Don't edit `dist/` or `vendor/` (build artifacts); edit source and rebuild.
  Don't read/print secrets from `.env`.
- **`docs/reference/CONFIG.md` is the single source of truth for every JWT claim + env var.**
  Document any new claim there (the `test-config-doc.php` guard enforces it). Mint
  tokens with the one-options-array API — `fluxfiles_token(['user'=>…, 'claims'=>[…]])`
  — where `claims` (mirrored in node/python/laravel/wordpress) sets any claim by raw name.
- **Never put the main JWT in a URL** — adapters use `postMessage`, API uses
  `Authorization: Bearer`. `?token=` is dev-only (stripped on boot + `Referrer-Policy:
  no-referrer`). Per-file `/img`/`/stream` query tokens are fine (1-file scope, short
  TTL, distinct type). CSRF uses `Origin`, not `Referer`.
- **BYO-embed over build-and-sell**: where great free OSS self-host exists, embed it via
  a free config toggle, don't build a competitor. All four shipped: terminal →
  `terminal_pty_url` (ttyd), PDF → `pdf_tools_url` (Stirling), office → `office_url`
  (Collabora/OnlyOffice), e-sign → `esign_url` (DocuSeal).
- **Optimization is FREE/core** (was paid; `packages/optimize/` is retired). Paid modules
  are **13**: share/intake/versioning/webhooks/ai/ocr/virus/backup/c2pa/audit-export/sso/
  dlp/legal-hold (gitignored, `ModuleRegistry` 3-layer gate). `ModuleRegistry::$map` is
  the source of truth — check it, don't trust a count. Operator UI exists for
  Share/Intake/Versioning/AI Vision/OCR/Virus (on-demand scan)/Backup/Audit export
  (incl. integrity check + chain verify)/Legal hold (incl. retention); SSO adds a
  "Sign in with SSO" button. C2PA, Webhooks and DLP are engine-only (API/config, no UI).
- **Virus scan is fail-closed.** `allow_virus_scan` wires `FileManager::setVirusScanner()`
  in `index.php` *and* the Laravel/WP proxies (they build their own `FileManager`). It
  scans `upload()` / `putContent()` / each `extractZip()` entry **before** writing;
  anything that stops a scan (module absent, licence, claim, engine down) refuses the
  write instead of storing unscanned, and `/api/fm/chunk/*` plus presigned PUT/POST are
  refused outright because those bytes never reach the server. DLP (`allow_dlp_scan`)
  works the same way.

## Setup & common commands

```bash
# Core dev server
cd packages/core && php -S localhost:8080 router.php
# Install core deps
composer install -d packages/core
# Generate a test token
php packages/core/tests/generate-token.php
```

JWT signing secret is `FLUXFILES_SECRET` and **must be ≥ 32 bytes** (php-jwt v7).
Config is read by `\FluxFiles\EnvBootstrap::load()` in every entry point. It reads real env vars (`FLUXFILES_*`, `AWS_*`, `R2_*`, `SFTP_*`) first and fills the rest from `.env`, so a real env var wins. Don't call Dotenv directly.

## Tests

```bash
# Core PHP (unit + integration)
for f in packages/core/tests/unit/*.php packages/core/tests/integration/*.php; do php "$f"; done
# i18n parity across all 16 locales / CONFIG.md claim coverage
php packages/core/tests/unit/test-i18n.php
php packages/core/tests/unit/test-config-doc.php
# Browser (Playwright) — boots router.php itself
cd packages/core/tests/browser && npx playwright test
# HTTP e2e (needs a server on :8080) — not idempotent, clean storage/uploads/_api_test_dir between runs
bash packages/core/tests/e2e/test-api.sh
# Self-booting HTTP e2e (each starts its own php -S)
for f in packages/core/tests/e2e/*-http.php; do php "$f"; done
# Live S3 + Bucket Doctor (env-gated; CI runs it against LocalStack)
FXTEST_S3_* ... php packages/core/tests/e2e/test-s3-live.php
# Wrapper packages
cd packages/<react|vue|sdk|ckeditor4|tinymce|summernote|node|mcp> && npm install && npm test
# Adapter smokes (stubbed)
php packages/wordpress/tests/test-wp-smoke.php
php packages/laravel/tests/test-laravel-smoke.php
```

CI is `.github/workflows/test.yml` (16 jobs: core-php, lockfiles-and-laravel-db,
adapter-core-floor, iframe-allow, api-e2e, selfboot-e2e, s3-localstack, db-mysql,
db-postgres, wrappers, node-sdk (also builds/tests `@fluxfiles/mcp`), python-token,
browser-e2e, editor-e2e, pack-smoke, docker-build), on a PHP 8.1–8.5 matrix where it
matters. `selfboot-e2e` runs every `tests/e2e/*-http.php` (each boots its own `php -S`)
plus `test-sftp-live.php` against an `atmoz/sftp` container. `db-mysql`/`db-postgres`
run the `FLUXFILES_STORAGE_BACKEND=db` suite against real MySQL/PostgreSQL service
containers (see `docs/design/DB-STORAGE-MIGRATION-DESIGN.md`). `lockfiles-and-laravel-db`
fails when a committed `composer.lock` drifts from its `composer.json` and runs the
Laravel DB adapter test.

## Releases & versioning

- **Per-package annotated tags**: `core-vX.Y.Z`, `react-vX.Y.Z`,
  `node-vX.Y.Z`, `wordpress-vX.Y.Z`, `mcp-vX.Y.Z`, etc. Each package versions independently.
- `CHANGELOG.md` uses an umbrella `## [X.Y.Z] — date` header per release wave,
  with a `> Released:` line listing the package tags in that wave. Its own counter is
  **not** the core version — read `git tag | grep '^core-v' | sort -V | tail -1`.
- Release flow: bump the package version (package.json / plugin header /
  composer) → add/extend the CHANGELOG entry → `chore(release): <pkg> X.Y.Z`
  commit → annotated tag → push commit + tag.
- **Push ≤3 release tags per `git push`** — pushing >3 at once silently triggers
  no publish workflows. Batch the tags when releasing several packages together.
- npm packages publish from their subdir (`npm publish`; scope access is
  pre-configured). `npm publish` needs the maintainer's 2FA OTP.
- The WordPress plugin ZIP is built fresh from the current core by
  `scripts/build-wordpress.sh`; a `wordpress-v*` tag runs `wordpress-release.yml`,
  which attaches the ZIP (+ sha256 and a catalogue entry carrying `requires_php`) to a
  GitHub Release.
- **PHP floor** (`^8.1.0`; CI covers 8.1–8.5) is stated in five places that must agree — every
  `composer.json` `require.php` + `config.platform.php`, the WP plugin header/`readme.txt`
  `Requires PHP`, `FLUXFILES_MIN_PHP` and `FluxFilesUpdater::REQUIRES_PHP`;
  `test-wp-smoke.php` guards the WordPress side.

## Conventions

- Branch off `master`; commit/push only when asked. End commit messages with a
  `Co-Authored-By:` trailer when pairing with an agent.
- Match surrounding code style; keep comment density and naming idiomatic.
- Add/extend tests with every behavior change; run the relevant suite before
  declaring done.
