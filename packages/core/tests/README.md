# FluxFiles core tests

Layout by test type:

| Dir | What | Needs |
|-----|------|-------|
| `unit/` | Pure logic — claims, owner-only, visibility URLs, i18n, rate limiter, BYOB crypto/SSRF, disk manager | PHP only |
| `integration/` | Local-filesystem behaviour — metadata sidecars, image variants, existing-file indexer | PHP + temp FS |
| `e2e/` | `test-api.sh` (running server) and `test-s3-live.php` (live S3/R2, env-gated) | server / S3 backend |
| `manual/` | Browser fixtures for SDK + editor integrations (open in a browser) | manual |
| `apps/` | React/Vue/Laravel host scaffolds for wrapper testing | node / composer |
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

# Live S3/R2 (skips cleanly if no bucket configured). Works with MinIO, AWS, R2:
FXTEST_S3_LABEL=MinIO FXTEST_S3_ENDPOINT=http://127.0.0.1:9000 \
FXTEST_S3_REGION=us-east-1 FXTEST_S3_BUCKET=fluxfiles-test \
FXTEST_S3_KEY=minioadmin FXTEST_S3_SECRET=minioadmin123 \
FXTEST_S3_VISIBILITY=private FXTEST_S3_CREATE_BUCKET=1 \
php tests/e2e/test-s3-live.php
```

Tests load `FLUXFILES_SECRET` from `.env` (repo root or `packages/core/`). It must be ≥ 32 bytes — firebase/php-jwt v7 rejects shorter HS256 keys.

CI (`.github/workflows/test.yml`) runs `unit/` + `integration/` on PHP 8.1–8.4, `e2e/test-api.sh` per version, and `e2e/test-s3-live.php` against a MinIO container.
