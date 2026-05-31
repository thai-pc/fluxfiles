# Changelog

All notable changes to FluxFiles are documented here. This project adheres to
[Semantic Versioning](https://semver.org/).

## [0.1.0] — 2026-05-30

First public release. FluxFiles is a standalone, embeddable PHP file manager: a
zero-build frontend plus a stateless PHP API that any host app can embed by
iframe or SDK, with framework adapters for Laravel, WordPress, React, Vue/Nuxt,
CKEditor 4, and TinyMCE.

> The `0.1.0` tag re-baselines the project for its first public release. Earlier
> internal `1.x` tags predate this versioning and are not part of the public
> release line.

### Core file manager

- **File operations** — list, upload, download presign, rename, move, copy,
  delete, mkdir, and cross-disk copy/move.
- **Multi-storage via Flysystem v3** — Local, AWS S3, and Cloudflare R2. R2/MinIO
  endpoints are treated as path-style S3.
- **Cursor-based pagination** — `GET /api/fm/list?limit=&cursor=` returns
  `{ items, next_cursor, total }`; omitting `limit` keeps the legacy flat array.
  The UI auto-pages with a "Load more" control.
- **Image processing** — WebP variants (thumb/medium/large) generated on upload,
  plus inline crop with aspect-ratio presets (replace original or save as copy).
- **Duplicate detection** — SHA-based hash index; stale/system-path entries are
  self-healed instead of surfacing phantom duplicates.
- **Optional AI tagging** — Claude / OpenAI-compatible vision providers, auto-tag
  on upload or on demand; tags feed search.
- **Internationalization** — 16 locales with RTL support and CSS logical
  properties; locale passthrough from the SDK and every adapter.
- **Zero-build UI** — Alpine.js + htmx standalone frontend served from
  `packages/core/public`.

### Auth & security

- **JWT (HS256, firebase/php-jwt v7)** with claims for permissions, disks, path
  prefix, upload size, allowed extensions, storage quota, `owner_only`, and BYOB
  disks. HS256 secrets must be ≥ 32 bytes.
- **Centralized enforcement** — disk access, permissions, path scoping
  (`Claims::scopePath()`), `owner_only` (enforced at folder level too via
  `assertOwnsTree`), upload-size, and extension checks live in one place.
- **BYOB (Bring Your Own Bucket)** — storage credentials are AES-256-GCM encrypted
  in the JWT and decrypted only at runtime; never exposed. BYOB endpoints are
  SSRF-guarded (loopback/private/reserved IPs and the cloud metadata address are
  rejected) and local BYOB disks are refused.
- **Upload hardening** — served uploads carry `X-Content-Type-Options: nosniff`,
  a sandbox CSP, and attachment disposition for HTML/SVG/XML; path-traversal is
  blocked. Uploads cannot write into the internal `_fluxfiles/` / `_variants/`
  namespaces.
- **CSRF / origin checks** on mutating requests, with a same-origin fallback when
  `ALLOWED_ORIGINS` is unset.
- **Rate limiting**, **audit log**, and **storage quota** per user.

### Storage-backed metadata

- Metadata travels with user storage — no central database. Local disks use
  sidecars under the protected `_fluxfiles/meta/{key}.json` namespace (so a
  user-uploaded `*.meta.json` can never collide), with legacy `{file}.meta.json`
  sidecars migrated on read. S3/R2 use object metadata. Indexes live in
  `_fluxfiles/index.json` (search), `_fluxfiles/dirs.json` (folder search), and
  `_fluxfiles/audit.jsonl` (audit).

### Adapters

- **Laravel** — service provider, facade, publishable config, route proxy, Blade
  component, JWT middleware, and a `php artisan fluxfiles:seed` command to index
  pre-existing directories.
- **WordPress** — plugin with settings page, `[fluxfiles]` shortcode, editor media
  button, REST proxy at `/wp-json/fluxfiles/v1/`, and a `wp fluxfiles seed`
  WP-CLI command.
- **React** — `<FluxFiles>`, `<FluxFilesModal>`, and the `useFluxFiles` hook.
- **Vue 3 / Nuxt** — component + composable, including `onTokenRefresh`.
- **CKEditor 4** and **TinyMCE** — editor integrations.
- **Browser SDK** — `FluxFiles` global that manages the iframe modal and the
  `postMessage` protocol (`FM_READY` → `FM_CONFIG`, `FM_SELECT`, token refresh,
  commands, events, close).

### Tooling & tests

- **Core PHP** — unit/integration suites plus an API e2e script and an env-gated
  live S3/R2 test (MinIO/AWS/R2).
- **Browser e2e (Playwright + chromium)** — render/auth smoke and full UI
  interaction coverage: upload, folder create + breadcrumb navigation, search,
  dark-mode toggle, delete, inline crop (save-as-copy), single-pick `FM_SELECT`,
  multi-select (`multiple:true`) returning an `FM_SELECT` array, and bulk
  operations (multi-select delete + move).
- **Wrapper tests** — vitest for the JS adapters, stubbed-PHP smokes for
  WordPress/Laravel, and a pack-&-install smoke that typechecks published
  tarballs.
- **Docker** — dev/test image (PHP 8.1–8.4 matrix), production image
  (nginx + php-fpm), and a compose stack with MinIO.
- **CI** — GitHub Actions across core PHP, API e2e, live S3 (MinIO), wrappers,
  browser e2e, pack-smoke, and the production Docker image.

### Requirements

- PHP **8.1+** across `core`, `laravel`, and `wordpress`.
