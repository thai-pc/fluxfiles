# AGENTS.md

Guidance for AI coding agents working in this repo. This is the tool-agnostic
entry point; the deeper canonical notes live in `.claude/` —
[`CLAUDE.md`](.claude/CLAUDE.md), [`architecture.md`](.claude/architecture.md),
[`development.md`](.claude/development.md), [`api-map.md`](.claude/api-map.md).
Read those for detail.

## What FluxFiles is

A standalone, embeddable PHP file manager. The core exposes a PHP API + a
zero-build (Alpine.js + htmx) frontend that hosts embed by iframe/SDK. Adapters
ship for Laravel, WordPress, React, Vue/Nuxt, CKEditor 4, TinyMCE, plus a vanilla
SDK and a Node server-side token SDK.

Capabilities: Local / AWS S3 / Cloudflare R2 (and any S3-compatible store) via
Flysystem v3; JWT auth with rich claims (perms, disk, path prefix, quota,
owner-only, allowed extensions, BYOB); file ops + cross-disk copy/move; file
soft-delete (trash/restore, storage-resident); metadata that travels with
storage; image WebP variants + crop; optional AI tagging;
i18n (16 locales, RTL); chunked S3 uploads; rate limiting; audit log; Bucket
Doctor diagnostics.

## Repository layout

- `packages/core/` — the PHP package + standalone app. `api/index.php` (router,
  CORS, auth, dispatch), `api/FileManager.php` (file ops + most security checks),
  `api/DiskManager.php` (Flysystem disks), `api/Claims.php` (JWT claims),
  `api/StorageMetadataHandler.php` (metadata/index/audit), `assets/`, `public/`,
  `lang/`, `tests/`.
- `packages/sdk/` — vanilla browser SDK (`FluxFiles` global).
- `packages/node/` — server-side token SDK (`@fluxfiles/node`), mints JWTs in JS.
- `packages/{react,vue,ckeditor4,tinymce}/` — TS wrappers (tsup + vitest).
- `packages/{laravel,wordpress}/` — PHP adapters (each bundles/uses the core).
- `docs/`, `.github/workflows/test.yml`, `docker/`, `scripts/`.

## Working rules (the architectural grain — keep features fitting it)

- **Stateless. No central DB.** All server state lives in the **JWT (claims)** or
  in the **user's storage** under `_fluxfiles/` (sidecars + JSON index files,
  file-locked). There is **no SQLite** — metadata is `_fluxfiles/meta/{key}.json`
  (local) or object metadata (S3/R2); search uses `_fluxfiles/index.json`, folder
  search `dirs.json`, audit `audit.jsonl`.
- **Authorization = signed JWT claims**; the host app owns identity/policy,
  FluxFiles only enforces. Keep enforcement centralized and consistent (disk,
  perms, path scope via `Claims::scopePath`/`isPathInScope`, owner-only, upload
  size, extension).
- **BYOB**: user S3/R2 credentials are AES-256-GCM encrypted (HKDF-derived key,
  info `fluxfiles-byob-enc`) inside the JWT and decrypted only at runtime — never
  stored or logged. The same crypto/claim format is mirrored in `@fluxfiles/node`
  (PHP↔Node contract — change both together).
- **Security-first**: SSRF guard on BYOB endpoints, XSS-neutralized uploads
  (CSP sandbox + attachment for html/svg), extension immutability on
  rename/move/copy, `_fluxfiles/`/`_variants/` blocked from list/search/ops.
- Prefer turning a request into a **variant of an existing pattern** over adding a
  new category (DB, public endpoint, realtime server). New storage = a Flysystem
  driver + `DiskManager` case; pipeline steps (AI tag, antivirus) = like
  `AiTagger`; "share" = a narrow short-TTL token, not a stateful public endpoint.
- Don't edit `dist/` or `vendor/` (build artifacts); edit source and rebuild.
  Don't read/print secrets from `.env`.

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

## Tests

```bash
# Core PHP (unit + integration)
for f in packages/core/tests/unit/*.php packages/core/tests/integration/*.php; do php "$f"; done
# i18n parity across all 16 locales
php packages/core/tests/unit/test-i18n.php
# Browser (Playwright) — boots router.php itself
cd packages/core/tests/browser && npx playwright test
# HTTP e2e (needs a server on :8080) — not idempotent, clean storage/uploads/_api_test_dir between runs
bash packages/core/tests/e2e/test-api.sh
# Live S3 + Bucket Doctor (env-gated; CI runs it against MinIO)
FXTEST_S3_* ... php packages/core/tests/e2e/test-s3-live.php
# Wrapper packages
cd packages/<react|vue|sdk|ckeditor4|tinymce|node> && npm install && npm test
# Adapter smokes (stubbed)
php packages/wordpress/tests/test-wp-smoke.php
php packages/laravel/tests/test-laravel-smoke.php
```

CI is `.github/workflows/test.yml` (8 jobs: core-php, api-e2e, s3-minio,
wrappers, node-sdk, browser-e2e, pack-smoke, docker-build).

## Releases & versioning

- **Per-package annotated tags**: `core-vX.Y.Z`, `react-vX.Y.Z`,
  `node-vX.Y.Z`, `wordpress-vX.Y.Z`, etc. Each package versions independently.
- `CHANGELOG.md` uses an umbrella `## [X.Y.Z] — date` header per release wave,
  with a `> Released:` line listing the package tags in that wave.
- Release flow: bump the package version (package.json / plugin header /
  composer) → add/extend the CHANGELOG entry → `chore(release): <pkg> X.Y.Z`
  commit → annotated tag → push commit + tag.
- npm packages publish from their subdir (`npm publish`; scope access is
  pre-configured). `npm publish` needs the maintainer's 2FA OTP.
- The WordPress plugin ZIP is built fresh from the current core by
  `scripts/build-wordpress.sh` (no committed vendored core to sync); attach the
  ZIP to a GitHub Release / WP.org.

## Conventions

- Branch off `master`; commit/push only when asked. End commit messages with a
  `Co-Authored-By:` trailer when pairing with an agent.
- Match surrounding code style; keep comment density and naming idiomatic.
- Add/extend tests with every behavior change; run the relevant suite before
  declaring done.
