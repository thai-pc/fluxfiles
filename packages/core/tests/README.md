# FluxFiles core tests

Layout by test type:

| Dir | What | Needs |
|-----|------|-------|
| `unit/` | Pure logic — claims, owner-only, visibility URLs, i18n parity, CONFIG.md claim coverage, rate limiter, BYOB crypto/SSRF, disk manager, DB backend (SQLite) | PHP only |
| `integration/` | Local-filesystem behaviour — metadata sidecars, image variants, trash, legal-hold enforcement, existing-file indexer | PHP + temp FS |
| `e2e/` | `test-api.sh` (running server), self-booting `*-http.php` (each boots its own `php -S` — stream, img, zip, usage, tus, share/intake, SSO, virus/DLP gates, audit export/coverage, …), and `test-s3-live.php`/`test-sftp-live.php` (live S3/R2/SFTP, env-gated) | server / S3 / SFTP backend |
| `browser/` | Playwright suite against the standalone `/public/` UI (`chmod`, `media-preview`, `import-url`, etc.) — CI job `browser-e2e` | node + Playwright |
| `manual/` | Browser fixtures for SDK + editor integrations (serve via `router.php`, open `http://localhost:8080/tests/manual/*.html` — not `file://`) | manual |
| `apps/` | Real-adapter e2e: React/Vue/Laravel host apps + the WordPress plugin driving the core iframe (see `apps/README.md`) | node / composer / Docker |
| `generate-token.php` | Helper to mint test JWTs | PHP |

## Run

```bash
cd packages/core

# All automated PHP tests
for f in tests/unit/*.php tests/integration/*.php; do php "$f"; done

# A single test
php tests/unit/test-claims.php

# API end-to-end (needs the server running)
php -S localhost:8080 router.php &        # in another shell
bash tests/e2e/test-api.sh

# Self-booting HTTP e2e (each starts and stops its own server on its own port)
for f in tests/e2e/*-http.php; do php "$f"; done

# Live S3/R2 (skips cleanly if no bucket configured). Works with LocalStack, AWS, R2:
FXTEST_S3_LABEL=LocalStack FXTEST_S3_ENDPOINT=http://127.0.0.1:4566 \
FXTEST_S3_REGION=us-east-1 FXTEST_S3_BUCKET=fluxfiles-test \
FXTEST_S3_KEY=test FXTEST_S3_SECRET=test \
FXTEST_S3_VISIBILITY=private FXTEST_S3_CREATE_BUCKET=1 \
FXTEST_S3_ANON_NOT_ENFORCED=1 \
php tests/e2e/test-s3-live.php
```

Tests load `FLUXFILES_SECRET` from `.env` (repo root or `packages/core/`). It must be ≥ 32 bytes — firebase/php-jwt v7 rejects shorter HS256 keys.

CI (`.github/workflows/test.yml`) runs `unit/` + `integration/` on PHP 8.2–8.4 (`core-php`), `e2e/test-api.sh` per version (`api-e2e`), every `e2e/*-http.php` plus `test-sftp-live.php` against an `atmoz/sftp` container (`selfboot-e2e`), `e2e/test-s3-live.php` against a LocalStack container (`s3-localstack`), the MySQL/PostgreSQL DB-backend suites (`db-mysql`/`db-postgres`) and the Playwright suite (`browser-e2e`). Port allocation for new self-booting tests is listed in `.claude/development.md`.
