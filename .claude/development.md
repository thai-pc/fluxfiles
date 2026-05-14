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

Core tests/scripts:

```bash
bash packages/core/tests/test-api.sh
php packages/core/tests/test-metadata.php
php packages/core/tests/test-ratelimiter.php
php packages/core/tests/test-diskmanager.php
php packages/core/tests/test-i18n.php
php packages/core/tests/test-claims.php
php packages/core/tests/test-owner-only.php
php packages/core/tests/test-byob.php
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
