# Architecture Notes

## Runtime Flow

1. Browser opens `packages/core/public/index.html` either directly or inside an iframe.
2. The host page or wrapper SDK sends configuration by `postMessage`: endpoint, JWT token, active disk, mode, path, locale, theme, and picker options.
3. UI calls `/api/fm/*` routes with `Authorization: Bearer <JWT>`.
4. `packages/core/api/index.php` handles CORS, public locale/UI routes, JWT auth, disk setup, metadata repo setup, rate limiting, audit logging, and route dispatch.
5. `FileManager` performs file operations through `DiskManager` and stores metadata through the `MetadataRepositoryInterface` (`StorageMetadataHandler` by default, `Db\DbMetadataHandler` with `FLUXFILES_STORAGE_BACKEND=db`).

## Core Backend Components

> Note: "component" here means a PHP class/file in `core/api/`, not a **paid
> module** in the `ModuleRegistry`/licensing sense used elsewhere (the paid modules
> are listed in `ModuleRegistry::$map` — share, intake, versioning, webhooks, ai, ocr,
> virus, backup, c2pa, audit-export, sso, dlp, legal-hold) — the two meanings of
> "module" collide, so this section avoids the word.

- `api/index.php`
  - Public routes: `/public/index.html`, `/api/fm/lang`, `/api/fm/lang/{locale}`.
  - Pre-auth byte-serving routes (authenticated by a per-file query-string token, not the main JWT, because `<video>`/`<img>` can't send headers): `/api/fm/stream` (gated local + SFTP media, Range) and `/api/fm/img` (on-demand WebP/AVIF). Both reject a missing/placeholder secret and are dispatched before the main auth block.
  - Other pre-auth routes: the Share/Intake recipient routes (`share/info|unlock|file`, `intake/info|upload`, `PublicLinks.php`, gated installed + licensed) and the SSO bridge (`sso/login|callback|exchange`, gated by `FLUXFILES_SSO_ENABLED` + installed + licensed).
  - Authenticated routes: all other `/api/fm/*` routes, including `/api/fm/tus` (handled by `TusServer` outside the JSON envelope).
  - Loads `.env` from package root or monorepo root.
  - Applies CORS and origin checks for mutating requests.
  - Builds `Claims`, `DiskManager`, the metadata repository, `FileManager`, `QuotaManager`, the rate limiter (`RateLimiterFactory` → file or DB storage), `AuditLogStorage`, `ChunkUploader`, optional `AiTagger`, and wires the paid-module hooks (virus/DLP scanner, versioning, webhooks) only when the matching claim is on.

- `api/Claims.php`
  - Decodes the product-specific JWT payload into typed-ish public properties.
  - Important fields: `sub`, `perms` (`read`/`write`/`delete`/`audit`), `disks`, `prefix`, `max_upload`, `allowed_ext`, `max_storage`, `max_files`, `owner_only`, `byob_disks`.
  - Per-tenant feature claims (set on the instance after construct, so the constructor signature stays stable for positional callers/adapters): rate limits, variants, URL import, media preview, on-demand images, watermark overlay, zip/extract, BYO-embed URLs (terminal/PDF/office/e-sign), optimize, `strip_exif`, `media_posters`, `allow_presign_put`, `audit_reads`, usage thresholds, and every paid-module gate (`allow_<x>` + tuning claims, `retention_rules`). The full list is `docs/reference/CONFIG.md` (CI-guarded against this file).
  - `scopePath()` sanitizes `.` and `..`, strips null bytes, and prepends the user's prefix.
  - `isPathInScope()` checks already-scoped keys.

- `api/FileManager.php`
  - Main service for list, upload, delete/trash, rename, move, copy, cross-disk operations, mkdir, crop, watermark, AI tag, presign (GET/PUT/POST), zip/extract, content editor, chmod, file metadata, duplicate finder, retention runs, fixity checks and on-demand virus scans.
  - Enforces permissions and disk access through claims, plus legal-hold enforcement (`assertNoActiveHold()`, license-independent).
  - Uses scoped paths before touching storage.
  - Hides internal `_fluxfiles` and `_variants` from listings (case-insensitively); legacy `{file}.meta.json` sidecars are hidden only when their base file still exists (genuine user `*.meta.json` uploads are shown). Refuses writes into `.git/` on SFTP disks.
  - Handles duplicate detection through SHA-256 metadata index (`dedupe_uploads`).
  - Tracks parent directories for folder search.

- `api/DiskManager.php`
  - Builds Flysystem local, S3-compatible, and **SFTP** disks.
  - R2/MinIO-style endpoints are treated as S3 with path-style endpoint and `retain_visibility` disabled.
  - **SFTP** (`SFTP_*` env, or BYOB): a 3rd driver for VPS/hosting. Connect/disconnect per request (no pool, no DB); password OR private key; host is `SsrfGuard::assertHostSafe`-checked (allowlist via `FLUXFILES_SSRF_ALLOW_HOSTS`); optional host-key pinning / strict algorithms. SFTP has no static/presigned URL, so `fileUrl()` returns a tokened `/api/fm/stream` link and `MediaEndpoint::stream()` reads it via Flysystem (no Range). Chunk upload + presign reject SFTP (S3-only); large SFTP uploads use tus instead. Serving is proxied by the Laravel/WordPress adapters too (they port `/stream`).
  - BYOB disks can be registered at runtime (s3 or sftp), but local BYOB disks are rejected.

- `api/StorageMetadataHandler.php`
  - Metadata is storage-backed by default, not database-backed — **except** the
    opt-in `FLUXFILES_STORAGE_BACKEND=db` mode, which moves this same bookkeeping
    (metadata/search/folder-index/audit/trash/holds/rate-limits) into the operator's own
    self-hosted SQLite/MySQL/Postgres instead (`api/Db/*`, gated by a server env
    var, not a JWT claim or a paid module — see `docs/design/DB-STORAGE-MIGRATION-DESIGN.md`).
    The rest of this section describes the default `json` backend.
  - S3/R2: object metadata plus `_fluxfiles/index.json`.
  - Local/SFTP: sidecar at `_fluxfiles/meta/{key}.json` (inside the protected namespace; legacy `{file}.meta.json` is migrated on read) plus `_fluxfiles/index.json`.
  - Folder search uses `_fluxfiles/dirs.json`; trash `_fluxfiles/trash.json`; legal holds `_fluxfiles/holds.json`.
  - Audit uses `_fluxfiles/audit.jsonl`, archived (not dropped) to `_fluxfiles/audit/archive/` on rotation, every entry HMAC-chained (`AuditChain`).
  - File hash is stored for duplicate detection but removed from public metadata responses.

- `api/ChunkUploader.php`
  - Supports multipart uploads for S3-compatible disks through init, presign part, complete, and abort endpoints (part sizes/ETags validated before completion).

- `api/TusServer.php`
  - tus 1.0 resumable uploads (core + creation + termination) for any disk; staged in `<FLUXFILES_STORAGE_PATH>/tus/`, rules checked at creation, the last chunk goes through the normal upload pipeline. Core-standalone only.

- `api/ImageOptimizer.php`
  - Generates fixed WebP variants on upload (thumb/medium/large) under `_variants/`. Keep list/delete/copy/move behavior aware of variants.
  - `transform()` does on-demand resize→WebP/AVIF (returns null = serve original for SVG/animated-GIF/non-raster/decompression-bomb/undecodable); `transformCacheKey()` names the cache inside `_variants/` (mtime-stamped, so the existing delete/trash cleanup invalidates it for free). Uses `ImageCompat` (intervention/GD) — no Imagick.

- `api/ExifStripper.php` / `api/MediaPoster.php` / `api/PdfOptimizer.php` / `api/OptimizeModule.php`
  - Upload-time privacy and media helpers: lossless EXIF/XMP/IPTC/MPF stripping (`strip_exif`), PDF/video poster thumbnails (`media_posters`, opt-in; Ghostscript ≥ 10.05.0 / ffmpeg), and the free optimize pipeline (WebP at rest + Ghostscript PDF compression).

- `api/ProcessEnv.php`
  - `ProcessEnv::minimal()` — the only environment external tools (gs, ffmpeg, tesseract, c2patool, clamdscan) receive. Use it for any new `proc_open`.

- `api/AiTagger.php`
  - Optional AI metadata generation. Controlled by `FLUXFILES_AI_PROVIDER`, `FLUXFILES_AI_API_KEY`, `FLUXFILES_AI_MODEL`, `FLUXFILES_AI_BASE_URL`.

- `api/StreamToken.php` / `api/RangeStreamer.php`
  - Gated local media (`FLUXFILES_LOCAL_PRIVATE=true`) and SFTP serving: `StreamToken` is a short-lived, single-file token (`t=stream`) carried in the query string; `RangeStreamer` parses HTTP Range and `fseek`-serves 206/200/416 without rewinding. Production fast path: nginx `X-Accel-Redirect` (`FLUXFILES_XACCEL`) — PHP only verifies the token, nginx streams the bytes.

- `api/ImageToken.php`
  - Per-file token (`t=img`, distinct from the stream token) for the `/api/fm/img` endpoint; carries the tenant `mw` (max width) + `dq` (default quality) so the pre-auth handler can clamp without the main JWT.

- `api/SsrfGuard.php` / `api/UrlImporter.php`
  - URL import (`POST /api/fm/import-url`, opt-in via `allow_url_import`): synchronous fetch → upload pipeline. `SsrfGuard` is the shared SSRF denylist (also used by the BYOB endpoint check).

- `api/ModuleRegistry.php` / `api/LicenseManager.php` / `api/UpdateClient.php`
  - The paid-module 3-layer gate (installed → licensed → `allow_<x>` claim; `requireServer()` for the claim-less pre-auth SSO routes), offline Ed25519 licence verification, and the signed module installer behind `fluxfiles update <module>`.

- `api/AuditLogStorage.php` / `api/AuditChain.php` / `api/FixityChecker.php` / `api/ComplianceScorecard.php`
  - Audit logging (named actions, `access_denied`, optional read auditing), the HMAC chain + verifier, the SHA-256 fixity baseline (`_fluxfiles/fixity.json`), and the free compliance checklist.

## Frontend And Embedding

- `packages/core/public/index.html` and `packages/core/assets/fm.js` implement the standalone UI; `public/share.html` and `public/intake.html` are the Share/Intake recipient pages.
- `packages/sdk/fluxfiles.js` is the plain browser SDK. It creates/removes the iframe modal, sends `FM_CONFIG`, listens for `FM_SELECT`, token refresh requests, events, and close messages.
- `packages/react/src/useFluxFiles.ts` and `packages/vue/src/useFluxFiles.ts` mirror the SDK postMessage protocol for framework users.
- `packages/react/src/FluxFiles.tsx` and `packages/vue/src/FluxFiles.vue` are thin iframe components with imperative control APIs.

## Framework Adapters

- Laravel:
  - `FluxFilesServiceProvider` registers config, views, Blade component, routes in proxy mode (incl. the public share/intake/stream/img routes outside the auth group), and directives.
  - `FluxFilesManager` generates JWT tokens and BYOB tokens.
  - `FluxFilesController` proxies the core API (every route except tus, metadata export/import and SSO) and serves core assets in Laravel mode.

- WordPress:
  - `fluxfiles.php` boots the plugin (and refuses to load below PHP 8.1 with an admin notice).
  - `includes/FluxFilesPlugin.php` wires activation, admin, REST API, media button, shortcode, and storage protection (`.htaccess` for `wp-content/fluxfiles/`).
  - `includes/FluxFilesApi.php` registers the REST proxy routes under `/wp-json/fluxfiles/v1/`.
  - `FluxFilesPlugin::diskConfigs()` maps WordPress options to core disk configs.
  - Token generation mirrors Laravel/core helpers; WP capabilities map to the `role` presets.

## Storage Invariants

- Internal storage paths use `_fluxfiles/` and `_variants/`; these should not appear as normal user files.
- Metadata updates should update both the object/sidecar and the relevant index.
- Copy/move/delete behavior should keep metadata, variants, folder index, version history, and audit behavior coherent.
- Path prefix scoping is a security boundary. Do not bypass `Claims::scopePath()` or `Claims::isPathInScope()`.
