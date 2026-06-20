# Development Guide For AI Agents

## Environment

- PHP requirement: `>= 8.1`.
- Core PHP dependencies live in `packages/core/composer.json`.
- Root `vendor/` exists in this checkout, but core package commands should usually run with `-d packages/core` or from `packages/core`.
- Node packages:
  - `packages/react`: tsup + TypeScript, React peer dependency.
  - `packages/vue`: Vite + Vue + vue-tsc.
  - `packages/sdk`: plain JS package with type definitions.

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
WebP), etc.):

```bash
for f in packages/core/tests/unit/*.php packages/core/tests/integration/*.php; do php "$f"; done
bash packages/core/tests/e2e/test-api.sh   # HTTP e2e — needs a server on :8080; not idempotent
php packages/wordpress/tests/test-wp-smoke.php
php packages/laravel/tests/test-laravel-smoke.php
```

Self-booting HTTP e2e (start their own `php -S` + back up/restore `packages/core/.env`;
need the `curl` extension) — for the pre-auth byte-serving routes:

```bash
php packages/core/tests/e2e/test-stream-http.php   # gated local media (/api/fm/stream), Range
php packages/core/tests/e2e/test-img-http.php      # on-demand WebP (/api/fm/img), cache + negotiation
```

Browser (Playwright; boots `router.php` itself) lives in `packages/core/tests/browser/`
— includes `media-preview.spec.ts` (presigned-URL auto-refresh, claim gating).

Live S3/R2 test (env-gated; skips if no bucket). Works against MinIO, AWS S3, or R2:

```bash
FXTEST_S3_LABEL=MinIO FXTEST_S3_ENDPOINT=http://127.0.0.1:9000 \
FXTEST_S3_REGION=us-east-1 FXTEST_S3_BUCKET=fluxfiles-test \
FXTEST_S3_KEY=minioadmin FXTEST_S3_SECRET=minioadmin123 \
FXTEST_S3_VISIBILITY=private FXTEST_S3_CREATE_BUCKET=1 \
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
make test PHP=8.4         # or: make test-all  (8.1–8.4)

# Dev stack: standalone app (:8080) + MinIO (:9000, console :9001)
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
- For route shape changes, update `api/index.php`, docs/README snippets, SDK/framework callers, and tests together.
- For storage changes, test at least local disk behavior. S3/R2 behavior can differ for metadata, URL signing, and directory placeholders.
- For metadata changes, update both per-file metadata and `_fluxfiles/index.json` behavior.
- For folder behavior, update `_fluxfiles/dirs.json` tracking.
- For upload changes, verify duplicate detection, quota, extension blocking, dangerous filename rules, owner metadata, image variants, and audit logging.
- For copy/move/delete changes, verify variants and metadata are copied/moved/deleted as expected.
- For auth/security changes, add or update claim-focused tests. `prefix`, `owner_only`, `disks`, `perms`, `allowed_ext`, `max_upload`, and `max_storage` are security-sensitive.
- For UI changes in `packages/core/assets/fm.js`, test through the iframe with a real token.
- For React/Vue wrapper changes, update source files under `src/`; rebuild only when publishing or when the task asks for dist artifacts.

## Release/Package Notes

- Version numbers are mirrored across packages such as `packages/react/package.json`, `packages/vue/package.json`, Laravel composer constraints, and changelog content. Check all package manifests when doing release work.
- `packages/vue/dist` and `packages/react/dist` are generated publish artifacts.
- `scripts/build-wordpress.sh` is used for WordPress packaging.

## Common Pitfalls

- Roadmap docs can describe planned endpoints that are not implemented. Confirm route existence with `rg "uri ===" packages/core/api/index.php`.
- S3 metadata updates require copy-to-self semantics; do not assume a simple metadata update API.
- Local metadata sidecars should be hidden from normal listings.
- BYOB local disks are intentionally rejected.
- The SDK and framework wrappers rely on a stable `postMessage` protocol. Keep message names compatible unless doing an intentional breaking change.
- A token may expire while the iframe is active. SDK wrappers include token refresh hooks; preserve that flow.
