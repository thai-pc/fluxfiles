# FluxFiles AI Context

This directory is for AI agents working on the FluxFiles codebase. Read this file first, then use the linked notes for deeper context. The repo root [`AGENTS.md`](../AGENTS.md) is the tool-agnostic summary of the same guidance — keep the two roughly in sync.

## Product Summary

FluxFiles is a standalone, embeddable PHP file manager. The core app exposes a PHP API and zero-build frontend that can be embedded by iframe/SDK. The repo also ships adapters for Laravel, WordPress, React, Vue/Nuxt, CKEditor 4, and TinyMCE.

Primary capabilities:

- Local, AWS S3, and Cloudflare R2 storage through Flysystem v3.
- JWT auth with permission, disk, path prefix, upload size, extension, quota, owner-only, and BYOB disk claims.
- File operations: list, upload, download presign, rename, move, copy, delete, mkdir, cross-disk copy/move.
- Metadata: title, alt text, caption, tags, file hash, ownership, search index, folder index, audit log.
- Image processing: WebP variants, crop/regenerate variants.
- Optional AI tagging through Claude/OpenAI-compatible vision providers.
- Iframe SDK and framework wrappers that communicate by `postMessage`.

## High-Level Structure

- `packages/core/`: main PHP package and standalone app.
- `packages/core/api/index.php`: HTTP entrypoint, CORS, locale routes, auth, dependency wiring, route dispatch.
- `packages/core/api/FileManager.php`: central file operation service and most security checks.
- `packages/core/api/DiskManager.php`: builds Flysystem disks for local/S3/R2 and runtime BYOB disks.
- `packages/core/api/Claims.php`: decoded JWT claim model and path scoping.
- `packages/core/api/StorageMetadataHandler.php`: metadata, index, folder index, hash, and audit storage.
- `packages/core/assets/` and `packages/core/public/`: standalone UI assets.
- `packages/sdk/`: browser SDK exposed as `FluxFiles`.
- `packages/laravel/`: Laravel service provider, facade, config, route proxy, Blade component.
- `packages/wordpress/`: WordPress plugin wrapper around the core package.
- `packages/react/`: React component/hooks wrapper.
- `packages/vue/`: Vue 3/Nuxt component/composable wrapper.
- `packages/ckeditor4/`, `packages/tinymce/`: editor integrations.
- `docs/`: design and roadmap notes.

More detailed maps:

- See `architecture.md` for runtime flow and module responsibilities.
- See `development.md` for commands, tests, and change guidance.
- See `api-map.md` for current core routes.

## Working Rules

- Do not treat the server as the owner of user metadata. Metadata should live with user storage, not in a central database.
- Keep JWT enforcement centralized and consistent: disk access, permissions, path scope, owner-only, upload size, and extension checks matter.
- Never expose storage credentials. BYOB credentials are encrypted in JWTs and decrypted only at runtime.
- Do not add new stateful server dependencies unless the task explicitly changes the stateless/BYOB direction.
- Avoid editing `dist/` files unless the task is specifically about published artifacts. Prefer source under `src/` and rebuild.
- Do not read or print secrets from `.env` unless the user explicitly asks. Use `.env.example` for documentation.
- `packages/core/storage/`, metadata sidecars, `_fluxfiles/`, generated variants, lock/index files, and uploaded test files are runtime data.

## Common Entry Points

- Standalone dev server:

```bash
cd packages/core
php -S localhost:8080 router.php
```

- Generate a test token:

```bash
php packages/core/tests/generate-token.php
```

- Core package install:

```bash
composer install -d packages/core
```

- React package checks:

```bash
cd packages/react
npm run typecheck
npm run build
```

- Vue package checks:

```bash
cd packages/vue
npm run typecheck
npm run build
```

## Current Notes

- The core route file currently has direct delete, not trash/restore/purge routes.
- `docs/FLUXFILES-ROADMAP.md` (gitignored, local) contains roadmap ideas and may be older than the implemented route surface. Verify implementation before following roadmap endpoints.
- `docs/METADATA-STORAGE-DESIGN.md` captures the important principle that metadata travels with user storage. `docs/TEST-PLAN.md` is the living test plan.
- Auth uses `firebase/php-jwt` **v7** (constraint `^7.0`): HS256 keys must be **≥ 32 bytes** or token encode/decode throws. `JwtCompat`/`ImageCompat` shims abstract jwt v5–v7 / intervention v2–v3.
- Local metadata sidecars live at `_fluxfiles/meta/{key}.json` (not `{file}.meta.json`), so a user-uploaded `*.meta.json` cannot collide with or overwrite them. S3/R2 use object metadata.
- `owner_only` is also enforced at folder level (delete/rename/move a folder containing other users' files → 403 via `assertOwnsTree`). BYOB endpoints are SSRF-checked.

## Tests & Tooling

- Core PHP tests: `packages/core/tests/{unit,integration}/*.php`, e2e `tests/e2e/test-api.sh` + env-gated `tests/e2e/test-s3-live.php`, browser `tests/e2e/browser` (Playwright). Run: `for f in packages/core/tests/unit/*.php packages/core/tests/integration/*.php; do php "$f"; done`.
- Each wrapper owns its tests: `packages/{sdk,react,vue,ckeditor4,tinymce}/tests` (vitest), `packages/{wordpress,laravel}/tests/test-*-smoke.php` (stubbed PHP). `scripts/pack-smoke.sh` verifies published dist/types.
- Docker: `docker/Dockerfile` (`ARG PHP_VERSION`, runs the suite), `docker/Dockerfile.prod` (nginx+php-fpm), `docker-compose.yml` (app + MinIO), `Makefile` (`make test`/`test-all`/`up`). CI is `.github/workflows/test.yml` (8 jobs).
