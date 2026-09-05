# Architecture Notes

## Runtime Flow

1. Browser opens `packages/core/public/index.html` either directly or inside an iframe.
2. The host page or wrapper SDK sends configuration by `postMessage`: endpoint, JWT token, active disk, mode, path, locale, theme, and picker options.
3. UI calls `/api/fm/*` routes with `Authorization: Bearer <JWT>`.
4. `packages/core/api/index.php` handles CORS, public locale/UI routes, JWT auth, disk setup, metadata repo setup, rate limiting, audit logging, and route dispatch.
5. `FileManager` performs file operations through `DiskManager` and stores metadata through `StorageMetadataHandler`.

## Core Backend Components

> Note: "component" here means a PHP class/file in `core/api/`, not a **paid
> module** in the `ModuleRegistry`/licensing sense used elsewhere in this doc
> (share/intake/versioning/webhooks/ai/ocr/virus/backup/c2pa/audit-export/sso) —
> the two meanings of "module" collide, so this section avoids the word.

- `api/index.php`
  - Public routes: `/public/index.html`, `/api/fm/lang`, `/api/fm/lang/{locale}`.
  - Pre-auth byte-serving routes (authenticated by a per-file query-string token, not the main JWT, because `<video>`/`<img>` can't send headers): `/api/fm/stream` (gated local media, Range) and `/api/fm/img` (on-demand WebP). Both reject a missing/placeholder secret and are dispatched before the main auth block.
  - Authenticated routes: all other `/api/fm/*` routes.
  - Loads `.env` from package root or monorepo root.
  - Applies CORS and origin checks for mutating requests.
  - Builds `Claims`, `DiskManager`, `StorageMetadataHandler`, `FileManager`, `QuotaManager`, `RateLimiterFileStorage`, `AuditLogStorage`, `ChunkUploader`, and optional `AiTagger`.

- `api/Claims.php`
  - Decodes the product-specific JWT payload into typed-ish public properties.
  - Important fields: `sub`, `perms`, `disks`, `prefix`, `max_upload`, `allowed_ext`, `max_storage`, `owner_only`, `byob_disks`.
  - Per-tenant feature claims (set on the instance after construct, so the constructor signature stays stable for positional callers/adapters): `ai_auto_tag`, `rate_read/write`, `variants`; URL-import (`allow_url_import`, …); media-preview (`media_preview`, `preview_url_ttl`, `max_preview_mb`, `stream_token_ttl`); on-demand WebP (`webp_enabled`, `webp_max_width`, `webp_default_quality`). All forwarded by every token helper (core `fluxfiles_token` array params, adapter override arrays, Node `createToken`).
  - `scopePath()` sanitizes `.` and `..`, strips null bytes, and prepends the user's prefix.
  - `isPathInScope()` checks already-scoped keys.

- `api/FileManager.php`
  - Main service for list, upload, delete, rename, move, copy, cross-disk operations, mkdir, crop, AI tag, presign, and file metadata.
  - Enforces permissions and disk access through claims.
  - Uses scoped paths before touching storage.
  - Hides internal `_fluxfiles` and `_variants` from listings; legacy `{file}.meta.json` sidecars are hidden only when their base file still exists (genuine user `*.meta.json` uploads are shown).
  - Handles duplicate detection through SHA-256 metadata index.
  - Tracks parent directories for folder search.

- `api/DiskManager.php`
  - Builds Flysystem local, S3-compatible, and **SFTP** disks.
  - R2/MinIO-style endpoints are treated as S3 with path-style endpoint and `retain_visibility` disabled.
  - **SFTP** (`SFTP_*` env, or BYOB): a 3rd driver for VPS/hosting. Connect/disconnect per request (no pool, no DB); password OR private key; host is `SsrfGuard::assertHostSafe`-checked (allowlist via `FLUXFILES_SSRF_ALLOW_HOSTS`). SFTP has no static/presigned URL, so `fileUrl()` returns a tokened `/api/fm/stream` link and `handleMediaStream` reads it via Flysystem (no Range). Chunk upload + presign reject SFTP (S3-only). Serving is core-standalone (the proxies don't expose `/stream`).
  - BYOB disks can be registered at runtime (s3 or sftp), but local BYOB disks are rejected.

- `api/StorageMetadataHandler.php`
  - Metadata is storage-backed by default, not database-backed — **except** the
    opt-in `FLUXFILES_STORAGE_BACKEND=db` mode, which moves this same bookkeeping
    (metadata/search/folder-index/audit/trash/rate-limits) into the operator's own
    self-hosted SQLite/MySQL/Postgres instead (`api/Db/*`, gated by a server env
    var, not a JWT claim or a paid module — see `docs/DB-STORAGE-MIGRATION-DESIGN.md`).
    The rest of this section describes the default `json` backend.
  - S3/R2: object metadata plus `_fluxfiles/index.json`.
  - Local: sidecar at `_fluxfiles/meta/{key}.json` (inside the protected namespace; legacy `{file}.meta.json` is migrated on read) plus `_fluxfiles/index.json`.
  - Folder search uses `_fluxfiles/dirs.json`.
  - Audit uses `_fluxfiles/audit.jsonl`.
  - File hash is stored for duplicate detection but removed from public metadata responses.

- `api/ChunkUploader.php`
  - Supports multipart uploads for S3-compatible disks through init, presign part, complete, and abort endpoints.

- `api/ImageOptimizer.php`
  - Generates fixed WebP variants on upload (thumb/medium/large) under `_variants/`. Keep list/delete/copy/move behavior aware of variants.
  - `transform()` does on-demand resize→WebP (returns null = serve original for SVG/animated-GIF/non-raster/decompression-bomb); `transformCacheKey()` names the cache inside `_variants/` (mtime-stamped, so the existing delete/trash cleanup invalidates it for free). Uses `ImageCompat` (intervention/GD) — no Imagick.

- `api/AiTagger.php`
  - Optional AI metadata generation. Controlled by `FLUXFILES_AI_PROVIDER`, `FLUXFILES_AI_API_KEY`, and `FLUXFILES_AI_MODEL`.

- `api/StreamToken.php` / `api/RangeStreamer.php`
  - Gated local media (`FLUXFILES_LOCAL_PRIVATE=true`): `StreamToken` is a short-lived, single-file token (`t=stream`) carried in the query string; `RangeStreamer` parses HTTP Range and `fseek`-serves 206/200/416 without rewinding. Production fast path: nginx `X-Accel-Redirect` (`FLUXFILES_XACCEL`) — PHP only verifies the token, nginx streams the bytes.

- `api/ImageToken.php`
  - Per-file token (`t=img`, distinct from the stream token) for the `/api/fm/img` WebP endpoint; carries the tenant `mw` (max width) + `dq` (default quality) so the pre-auth handler can clamp without the main JWT.

- `api/SsrfGuard.php` / `api/UrlImporter.php`
  - URL import (`POST /api/fm/import-url`, opt-in via `allow_url_import`): synchronous fetch → upload pipeline. `SsrfGuard` is the shared SSRF denylist (also used by the BYOB endpoint check).

## Frontend And Embedding

- `packages/core/public/index.html` and `packages/core/assets/fm.js` implement the standalone UI.
- `packages/sdk/fluxfiles.js` is the plain browser SDK. It creates/removes the iframe modal, sends `FM_CONFIG`, listens for `FM_SELECT`, token refresh requests, events, and close messages.
- `packages/react/src/useFluxFiles.ts` and `packages/vue/src/useFluxFiles.ts` mirror the SDK postMessage protocol for framework users.
- `packages/react/src/FluxFiles.tsx` and `packages/vue/src/FluxFiles.vue` are thin iframe components with imperative control APIs.

## Framework Adapters

- Laravel:
  - `FluxFilesServiceProvider` registers config, views, Blade component, routes in proxy mode, and directives.
  - `FluxFilesManager` generates JWT tokens and BYOB tokens.
  - `FluxFilesController` proxies/serves core assets in Laravel mode.

- WordPress:
  - `fluxfiles.php` boots the plugin.
  - `includes/FluxFilesPlugin.php` wires activation, admin, REST API, media button, and shortcode.
  - `FluxFilesPlugin::diskConfigs()` maps WordPress options to core disk configs.
  - Token generation mirrors Laravel/core helpers.

## Storage Invariants

- Internal storage paths use `_fluxfiles/` and `_variants/`; these should not appear as normal user files.
- Metadata updates should update both the object/sidecar and the relevant index.
- Copy/move/delete behavior should keep metadata, variants, folder index, and audit behavior coherent.
- Path prefix scoping is a security boundary. Do not bypass `Claims::scopePath()` or `Claims::isPathInScope()`.
