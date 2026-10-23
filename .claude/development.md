# Development Guide For AI Agents

## Environment

- PHP requirement: `>= 8.2.35` (latest security-patched 8.2; 8.3/8.4 also tested). Five places must agree — every `composer.json` `require.php` + `config.platform.php`, the WP plugin header/`readme.txt` `Requires PHP`, `FLUXFILES_MIN_PHP` and `FluxFilesUpdater::REQUIRES_PHP`; `test-wp-smoke.php` guards the WP side. Raising it again = same edits + `composer update --lock`.
- Core PHP dependencies live in `packages/core/composer.json`.
- Root `vendor/` exists in this checkout, but core package commands should usually run with `-d packages/core` or from `packages/core`.
- Node packages:
  - `packages/react`: tsup + TypeScript, React peer dependency.
  - `packages/vue`: Vite + Vue + vue-tsc.
  - `packages/sdk`: plain JS package with type definitions.
  - `packages/node`, `packages/mcp`: TypeScript, vitest.
- Python package: `packages/python` (`fluxfiles-token`, pytest).

Do not print `.env` contents. Use `.env.example` and package config files when documenting settings.

## Useful Commands

Install core dependencies:

```bash
composer install -d packages/core
```

Run standalone core server:

```bash
cd packages/core
php -S localhost:8080 router.php
```

Generate test JWT:

```bash
php packages/core/tests/generate-token.php
```

Core tests/scripts — the canonical run-all is the glob (covers unit + integration,
including `test-claims`, `test-diskmanager`, `test-tenant-config` (per-tenant claims),
`test-stream` + `test-stream-gating` (gated media), `test-image-transform` (on-demand
WebP/AVIF), `test-config-doc` (every claim documented in CONFIG.md), `test-i18n`
(16-locale key parity), etc.):

```bash
for f in packages/core/tests/unit/*.php packages/core/tests/integration/*.php; do php "$f"; done
bash packages/core/tests/e2e/test-api.sh   # HTTP e2e — needs a server on :8080; not idempotent
php packages/wordpress/tests/test-wp-smoke.php
php packages/laravel/tests/test-laravel-smoke.php
```

### Self-booting HTTP e2e (`tests/e2e/*-http.php`)

Each of these boots its own `php -S`, backs up/restores `packages/core/.env`, and
needs the `curl` extension — they run sequentially against distinct ports so they
don't collide with each other or with a dev server you already have open. CI runs
**every file matching this glob automatically** in the `selfboot-e2e` job
(`for f in tests/e2e/*-http.php; do php "$f"; done`) — a new file matching
`*-http.php` is picked up with no registration anywhere, just give it an unused
port (or bind an ephemeral one, as `test-write-policy-http.php` does). Current files:

```bash
php packages/core/tests/e2e/test-stream-http.php              # gated local media (/api/fm/stream), Range — port 8099
php packages/core/tests/e2e/test-img-http.php                 # on-demand WebP/AVIF (/api/fm/img), cache + negotiation — port 8101
php packages/core/tests/e2e/test-zip-http.php                 # zip/extract streaming — port 8103
php packages/core/tests/e2e/test-usage-http.php               # usage/quota dashboard endpoint — port 8104
php packages/core/tests/e2e/test-sftp-http.php                # SFTP disk driver over HTTP routes — port 8105
php packages/core/tests/e2e/test-audit-export-http.php        # audit-export module (NDJSON/CSV, purge) — ports 8106-8108
php packages/core/tests/e2e/test-virus-http.php               # virus-scan fail-closed gate — port 8107
php packages/core/tests/e2e/test-compliance-scorecard-http.php # compliance scorecard — port 8109
php packages/core/tests/e2e/test-share-http.php               # share module (public link tokens) — ports 8110-8112
php packages/core/tests/e2e/test-intake-http.php              # intake module (public upload portals) — ports 8113-8115
php packages/core/tests/e2e/test-intake-notify-http.php       # intake notify-on-receipt webhook — ports 8116-8118 (+ receiver 8163)
php packages/core/tests/e2e/test-router-storage-http.php      # router.php never serves storage bookkeeping — port 8119
php packages/core/tests/e2e/test-audit-coverage-http.php      # every mutating route has a named audit action — port 8121
php packages/core/tests/e2e/test-tus-http.php                 # resumable tus uploads — port 8123
php packages/core/tests/e2e/test-sso-http.php                 # SSO bridge (OIDC exchange) — ports 8130-8135
php packages/core/tests/e2e/test-dlp-http.php                 # DLP fail-closed gate — port 8151
php packages/core/tests/e2e/test-write-policy-http.php        # quota/owner policy on writes (DB backend) — ephemeral port
```

(`test-virus-http.php`'s 8107 and `test-audit-export-http.php`'s 8106-8108 overlap
by one port; harmless since the suites run sequentially, never concurrently.
`scripts/landing-screenshots/capture.mjs` defaults to 8140.)

`test-sftp-live.php` is also self-booting and runs in the same `selfboot-e2e` CI
job, but it's a separate step (not part of the `*-http.php` glob above) because it
drives the live SFTP driver rather than an HTTP route:

```bash
php packages/core/tests/e2e/test-sftp-live.php   # live SFTP driver, needs FXTEST_SFTP_* (CI boots an atmoz/sftp container)
```

### DB-backend tests (`FLUXFILES_STORAGE_BACKEND=db`)

See `docs/design/DB-STORAGE-MIGRATION-DESIGN.md` for the design. The SQLite variant is
ungated and already runs as part of the regular unit-test glob above; MySQL and
PostgreSQL are env-gated and get their own CI jobs against real service
containers:

```bash
php packages/core/tests/unit/test-db-metadata-sqlite.php   # ungated, in-memory SQLite — part of the unit-test glob above, no setup needed

# Env-gated (CI: db-mysql job, real mysql:8 service container)
FXTEST_DB_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=fluxfiles_test;charset=utf8mb4' \
FXTEST_DB_MYSQL_USER=root FXTEST_DB_MYSQL_PASSWORD=root \
php packages/core/tests/unit/test-db-metadata-mysql.php
FXTEST_DB_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=fluxfiles_test;charset=utf8mb4' \
FXTEST_DB_MYSQL_USER=root FXTEST_DB_MYSQL_PASSWORD=root \
php packages/wordpress/tests/test-wp-db-metadata-mysql.php

# Env-gated (CI: db-postgres job, real postgres:16 service container)
FXTEST_DB_PGSQL_DSN='pgsql:host=127.0.0.1;port=5432;dbname=fluxfiles_test' \
FXTEST_DB_PGSQL_USER=postgres FXTEST_DB_PGSQL_PASSWORD=postgres \
php packages/core/tests/unit/test-db-metadata-pgsql.php

# Laravel DB adapter (CI: lockfiles-and-laravel-db job; runs on the Laravel lock)
composer install -d packages/laravel && php packages/laravel/tests/test-laravel-db-metadata.php
```

Browser (Playwright; boots `router.php` itself) lives in `packages/core/tests/browser/`
— includes `media-preview.spec.ts` (presigned-URL auto-refresh, claim gating).

Live S3/R2 test (env-gated; skips if no bucket). Works against LocalStack, AWS S3, or R2:

```bash
FXTEST_S3_LABEL=LocalStack FXTEST_S3_ENDPOINT=http://127.0.0.1:4566 \
FXTEST_S3_REGION=us-east-1 FXTEST_S3_BUCKET=fluxfiles-test \
FXTEST_S3_KEY=test FXTEST_S3_SECRET=test \
FXTEST_S3_VISIBILITY=private FXTEST_S3_CREATE_BUCKET=1 \
FXTEST_S3_ANON_NOT_ENFORCED=1 \
php packages/core/tests/e2e/test-s3-live.php
```

React:

```bash
cd packages/react
npm run typecheck
npm run build
```

Vue:

```bash
cd packages/vue
npm run typecheck
npm run build
```

Wrapper tests live inside each package (vitest+jsdom for JS, stubbed PHP for PHP):

```bash
# JS wrappers — postMessage protocol
cd packages/sdk       && npm install && npm test
cd packages/react     && npm install && npm test
cd packages/vue       && npm install && npm test
cd packages/ckeditor4 && npm install && npm test
cd packages/tinymce   && npm install && npm test
cd packages/summernote && npm install && npm test

# Token SDKs + MCP server (node/python include PHP↔language parity tests that need core's vendor/)
cd packages/node   && npm install && npm run typecheck && npm test
cd packages/mcp    && npm install && npm run typecheck && npm run build && npm test
cd packages/python && pip install -e '.[dev]' && pytest

# PHP adapters (need `composer install -d packages/core` first)
php packages/wordpress/tests/test-wp-smoke.php
php packages/laravel/tests/test-laravel-smoke.php

# Browser e2e — boots the PHP server + drives the iframe UI in chromium
cd packages/core/tests/browser
npm install && npx playwright install chromium && npm test

# Published-artifact smoke — pack each wrapper + install its tarball into a
# throwaway consumer and typecheck (verifies dist/types, not just src/)
bash scripts/pack-smoke.sh all
```

Docker (dev/test + production):

```bash
# Run the core suite in a clean container on a given PHP version
make test PHP=8.4         # or: make test-all  (8.2–8.4)

# Dev stack: standalone app (:8080) + LocalStack S3 (:4566)
make up                   # docker compose up --build
make down

# Build + run the production image (nginx + php-fpm)
docker build -f docker/Dockerfile.prod -t fluxfiles/fluxfiles:latest .
docker run -p 8080:80 -e FLUXFILES_SECRET=<32+ bytes> fluxfiles/fluxfiles:latest
```

WordPress package:

```bash
composer install -d packages/wordpress
```

Laravel package:

```bash
composer install -d packages/laravel
```

## Change Guidance

- For backend file behavior, start with `packages/core/api/FileManager.php`, then check `StorageMetadataHandler.php`, `Claims.php`, and `api/index.php`.
- For route shape changes, update `api/index.php`, docs/README snippets, `.claude/api-map.md`, `docs/guides/API.md`, SDK/framework callers, both proxy adapters (or their "intentionally unproxied" allowlists), and tests together.
- For storage changes, test at least local disk behavior. S3/R2 behavior can differ for metadata, URL signing, and directory placeholders. If the operator has opted into `FLUXFILES_STORAGE_BACKEND=db`, also check the DB-backend suites above — bookkeeping (metadata/search/audit/etc.) reads/writes through a different code path there even though file bytes still go through the same disk.
- For metadata changes, update both per-file metadata and `_fluxfiles/index.json` behavior.
- For folder behavior, update `_fluxfiles/dirs.json` tracking.
- For upload changes, verify duplicate detection, quota, extension blocking, dangerous filename rules, owner metadata, image variants, `strip_exif`, legal hold, virus/DLP scanning, and audit logging — and that `/tus` (whose last chunk reuses the pipeline) still behaves.
- For copy/move/delete changes, verify variants, metadata and version history are copied/moved/deleted as expected.
- For auth/security changes, add or update claim-focused tests. `prefix`, `owner_only`, `disks`, `perms`, `allowed_ext`, `max_upload`, and `max_storage` are security-sensitive.
- For any new external tool, run it via `proc_open` with an argv array and `ProcessEnv::minimal()` as the environment.
- For UI changes in `packages/core/assets/fm.js`, test through the iframe with a real token; new strings go into all 16 `lang/*.json` files.
- For React/Vue wrapper changes, update source files under `src/`; rebuild only when publishing or when the task asks for dist artifacts.

## Release/Package Notes

- Version numbers are mirrored across packages such as `packages/react/package.json`, `packages/vue/package.json`, `packages/mcp/package.json`, Laravel composer constraints, and changelog content. Check all package manifests when doing release work.
- `packages/vue/dist`, `packages/react/dist` and `packages/node/dist` are generated publish artifacts.
- `scripts/build-wordpress.sh` is used for WordPress packaging; `wordpress-release.yml` runs it on a `wordpress-v*` tag.

## Common Pitfalls

- Roadmap docs can describe planned endpoints that are not implemented. Confirm route existence with `rg "uri ===" packages/core/api/index.php`.
- S3 metadata updates require copy-to-self semantics; do not assume a simple metadata update API.
- Local metadata sidecars should be hidden from normal listings.
- BYOB local disks are intentionally rejected.
- The SDK and framework wrappers rely on a stable `postMessage` protocol. Keep message names compatible unless doing an intentional breaking change.
- A token may expire while the iframe is active. SDK wrappers include token refresh hooks; preserve that flow.
