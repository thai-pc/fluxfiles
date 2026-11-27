# Contributing to FluxFiles

Thanks for helping. Bug reports, documentation fixes and pull requests are all welcome.

- **Security problems:** do not open a public issue. Report them privately as described in
  [`SECURITY.md`](SECURITY.md).
- **Questions and ideas:** open an issue with the *Feature request* template, or a bug
  report if something is broken.
- Everyone taking part is expected to follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## What this repository contains

A monorepo of MIT-licensed packages:

| Path | What |
|---|---|
| `packages/core` | The PHP API, the zero-build UI, `embed.php` token helpers, the `fluxfiles` CLI |
| `packages/laravel`, `packages/wordpress` | PHP adapters (each with a stubbed smoke test) |
| `packages/sdk`, `packages/react`, `packages/vue`, `packages/ckeditor4`, `packages/ckeditor5`, `packages/tinymce`, `packages/summernote` | Browser SDK and wrappers (vitest) |
| `packages/node`, `packages/python`, `packages/mcp` | Token SDKs and the MCP server |
| `docs/` | Guides, the configuration reference and free/core design records |

**Paid modules are not part of this repository.** Share links, upload portals,
versioning, webhooks, AI vision, OCR, virus scan, DLP, legal hold, audit export, SSO,
C2PA and backup are separate private packages that core loads when they are installed
and licensed. Pull requests that add paid-module code or design specs here can't be
accepted; changes to the free core that a module calls into are fine.

## Development setup

Requirements: PHP 8.1 or newer with the `gd`, `curl`, `json`, `openssl`, `mbstring` and
`fileinfo` extensions (plus `zip` for extract and `pdo_sqlite` for the SQLite backend test),
Composer 2, and Node.js 20+ for the JS packages.

```bash
git clone https://github.com/thai-pc/fluxfiles.git
cd fluxfiles
composer install -d packages/core

# Tokens are signed with FLUXFILES_SECRET (>= 32 bytes). The file is gitignored.
echo "FLUXFILES_SECRET=$(openssl rand -hex 32)" > packages/core/.env

# Run the standalone app on http://localhost:8080/public/
(cd packages/core && php -S localhost:8080 router.php)
```

To open the UI you need a token: `php packages/core/tests/generate-token.php` prints a
few test tokens (append `?token=…` to `/public/index.html`; that URL form is for local
testing only).

Never commit a real `.env`, credentials or anything under `packages/core/storage/`,
`_fluxfiles/` or `storage/tus/`: those are runtime data.

## Running the tests

All commands run from the repo root.

```bash
# Core PHP: unit + integration (the main suite, what most changes need)
for f in packages/core/tests/unit/*.php packages/core/tests/integration/*.php; do php "$f"; done

# Self-booting HTTP e2e (each starts its own php -S; needs the curl extension)
for f in packages/core/tests/e2e/*-http.php; do php "$f"; done

# API e2e against a dev server on :8080 (needs curl + python3; not idempotent)
export $(grep '^FLUXFILES_SECRET=' packages/core/.env)
(cd packages/core && php -S 127.0.0.1:8080 router.php) &
bash packages/core/tests/e2e/test-api.sh

# PHP adapters
php packages/laravel/tests/test-laravel-smoke.php
php packages/wordpress/tests/test-wp-smoke.php

# JS packages: run inside the package you changed
cd packages/react && npm install && npm test      # same for sdk, vue, ckeditor4, ckeditor5, tinymce, summernote, node, mcp

# Python token SDK
cd packages/python && python -m venv .venv && .venv/bin/pip install -e '.[dev]' && PYTHONPATH=src .venv/bin/pytest

# Browser tests (Playwright against the standalone UI)
cd packages/core/tests/browser && npm install && npx playwright install chromium && npm test
```

`make test PHP=8.5` (or `make test-all` for 8.1 to 8.5) runs the core suite in a clean
Docker container. CI (`.github/workflows/test.yml`) runs all of the above plus the
database backends and several guard tests.

## Rules the CI enforces

A few guard tests fail the build when a change forgets something. Knowing them up front
saves a round trip:

- **New or renamed token claim or env var:** document it in
  [`docs/reference/CONFIG.md`](docs/reference/CONFIG.md), the single source of truth
  (`test-config-doc.php`).
- **New UI string:** add the key to every locale in `packages/core/lang/` (`test-i18n.php`).
- **New mutating route:** give it a named audit action.
- **Design docs:** `docs/design/` holds free/core records only, on an allowlist
  (`test-no-paid-specs-public.php`).
- **External tools** started with `proc_open` get a minimal environment
  (`ProcessEnv::minimal()`), never the server's.
- The main JWT never goes in a URL; adapters send it over `postMessage` and API calls use
  `Authorization: Bearer`.

## Pull requests

1. Branch from `master` and keep the change focused: one fix or feature per PR.
2. Add or update tests for behaviour changes, and run the suites for every package you
   touched.
3. Update the docs that describe what you changed (README, guide, CONFIG.md, the
   package README).
4. Don't edit built files under `dist/`; change the source and let the build produce
   them.
5. Don't bump versions or add release tags; the maintainer does that when releasing.
   You may add a line under `## [Unreleased]` in `CHANGELOG.md`.
6. Fill in the pull request template. CI must be green before review.

By contributing you agree that your contribution is licensed under the
[MIT licence](LICENSE) of this repository.
