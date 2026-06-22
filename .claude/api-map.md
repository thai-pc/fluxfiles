# Core API Map

All routes below are implemented in `packages/core/api/index.php`.

## Public Routes

- `GET /public/index.html`: serves UI with injected locale data.
- `GET /public` and `GET /public/`: same UI route.
- `GET /api/fm/lang`: list available locales.
- `GET /api/fm/lang/{locale}`: fetch locale messages.
- `GET /api/fm/stream?token=<stream-token>`: gated local media stream (Range-capable). Authenticated by a per-file stream token (not the main JWT), since `<video>`/`<audio>` can't send headers. Only serves files on a `private => true` local disk (`FLUXFILES_LOCAL_PRIVATE=true`).
- `GET /api/fm/img?token=<image-token>&width=&quality=&format=`: on-demand WebP transform of one image, cached in the file's `_variants/`. Authenticated by a per-file `ImageToken` (distinct type from the stream token; an `<img>` can't send headers). Width is rounded to 100px + clamped to the token's `mw`; quality snaps to 60/75/80/90; `format=auto` serves the original to non-WebP clients. S3/R2 cache hits 302-redirect to a presigned URL; local serves bytes. Exposed per image as `img_base` in `list()` when `webp_enabled` + a stream secret are set. Each image also gets a ready-to-use **`img_srcset`** (candidate `/api/fm/img?…&width=W W` entries from the `srcset_widths` ladder — snapped to 100px, clamped to `webp_max_width`, capped at the source width; the source width is always offered) and, when the `srcset_sizes` claim is set, an **`img_sizes`** string. Pure metadata, same gate as `img_base`.

## Authenticated File Routes

All authenticated routes require `Authorization: Bearer <JWT>`.

- `GET /api/fm/list?disk=local&path=&limit=0&cursor=`
  - Lists files and directories. With `limit > 0`, returns `{items,next_cursor,total}`; otherwise returns the legacy flat array.

- `POST /api/fm/upload`
  - Multipart form fields: `file`, `disk`, `path`, optional `force_upload`.

- `POST /api/fm/import-url`
  - JSON body: `url` (required), `disk`, `path`, `filename`, `overwrite`. Server-side
    fetch of a public URL → the upload pipeline. Gated by the `allow_url_import`
    claim (default false → 403). SSRF-guarded (`SsrfGuard`): per-hop IP denylist +
    post-connect re-check, streaming size cap, magic-byte MIME deny-list, SVG off by
    default. Its own rate bucket (`import_rate_limit`, default 10/min). See
    `UrlImporter.php`.

- `DELETE /api/fm/delete`
  - JSON body: `disk`, `path`.

- `POST /api/fm/rename`
  - JSON body: `disk`, `path`, `name`.

- `POST /api/fm/move`
  - JSON body: `disk`, `from`, `to`.

- `POST /api/fm/copy`
  - JSON body: `disk`, `from`, `to`.

- `POST /api/fm/mkdir`
  - JSON body: `disk`, `path`.

- `POST /api/fm/cross-copy`
  - JSON body: `src_disk`, `src_path`, `dst_disk`, `dst_path`.

- `POST /api/fm/cross-move`
  - JSON body: `src_disk`, `src_path`, `dst_disk`, `dst_path`.

- `POST /api/fm/presign`
  - JSON body: `disk`, `path`, `method`, `ttl`.

- `POST /api/fm/crop`
  - JSON body: `disk`, `path`, `x`, `y`, `width`, `height`, optional `save_path`.

- `POST /api/fm/ai-tag`
  - JSON body: `disk`, `path`.

- `GET /api/fm/meta?disk=local&path=file.jpg`
  - Returns file URL, size, modified time, mime-ish metadata, and variants.

- `GET /api/fm/disk/doctor?disk=local`
  - Health-check a disk (read/write/list/presign reachability). Used to validate
    BYOB credentials before issuing a long-lived token.

## Trash (soft-delete)

Soft-delete is move-based into `_fluxfiles/trash/<id>/` with a `_fluxfiles/trash.json`
manifest (storage-resident, scoped by prefix/owner). Files **and folders** are
supported; folders move the whole subtree incl. variants. All gated by the
`delete` permission. Trash ids are validated `[A-Za-z0-9_-]`.

- `POST /api/fm/trash` — JSON body: `disk`, `path`. Soft-delete to trash.
- `POST /api/fm/trash/restore` — JSON body: `disk`, `trash_id`, optional `path`.
- `GET /api/fm/trash/list?disk=local` — list current scope's trash entries.
- `POST /api/fm/trash/purge` — JSON body: `disk`, `trash_id`. Permanently remove one entry.
- `POST /api/fm/trash/empty` — JSON body: `disk`. Permanently empty the scope's trash.

> `DELETE /api/fm/delete` is **permanent** (no trash). The UI soft-deletes via `/trash`.

## Metadata/Search/Quota/Audit

- `GET /api/fm/metadata?disk=local&key=file.jpg`
  - Fetch stored metadata.

- `PUT /api/fm/metadata`
  - JSON body: `disk`, `key`, optional `title`, `alt_text`, `caption`, `tags`.

- `DELETE /api/fm/metadata`
  - JSON body: `disk`, `key`.
- `GET /api/fm/chmod?disk=&path=` / `POST /api/fm/chmod {disk, path, mode}`
  - SFTP file permissions (cPanel-style). GET reads the 3-digit octal mode (read perm); POST sets it (write perm + `allow_chmod` claim, default true; octal `0?[0-7]{3}`, not recursive). SFTP-only (non-SFTP disk → 400). Core-standalone like `/stream` (the Laravel/WP proxies don't expose SFTP).
- `POST /api/fm/zip {disk, paths[], name?}`
  - Streams a `.zip` of the selected files **and folders** (recursive) — constant-memory via ZipStream (each entry piped through Flysystem readStream; local/S3/R2/SFTP). Read perm + `allow_zip` + `allow_download`; folders expanded with name preserved, `owner_only` enforced (assertOwnsTree/assertOwner), `_fluxfiles`/`_variants`/`.meta.json` skipped, archive-name de-dupe. Pre-flight sums `fileSize` → `413` if over `zip_max_mb`/`zip_max_files` before any byte. Bypasses the JSON encoder (ZipStream sends its own headers, route `exit`s); a guard/size throw happens first → JSON error. Binary streaming → **core-standalone** (unproxied, like `stream`/`img`).
- `POST /api/fm/extract {disk, path, dest?}`
  - Extracts a `.zip` in place (default `dest` = a folder named after the archive). Two-pass = **atomic** (validate all → write all, so one bad entry aborts everything). Write perm + `allow_extract`. Guards: **zip-slip** (absolute/`..`/drive-letter → 403 `zip_slip`), dangerous-ext (`assertSafeFilename`) + `allowed_ext`, system-path skip, **zip-bomb** (uncompressed size + count caps → 413), **quota** (`assertQuota` on total → 413 `quota_exceeded`). Returns JSON → **proxied** by Laravel/WordPress.
- `GET /api/fm/content?disk=&path=` / `PUT /api/fm/content {disk, path, content}`
  - Config/code editor: read/overwrite a file's **text** content (cPanel-style — `wp-config.php`, `.env`, `nginx.conf`, `deploy.sh`). GET needs read perm (binary→415, >5 MB→413). PUT needs write perm **+ `allow_code_edit` claim (default FALSE, opt-in)** and only edits an **existing** file (404 otherwise; no creating new executables). `assertExt` (allowed_ext policy) still applies, but the always-on dangerous-ext upload block does **not** (editing `.php`/`.sh` is the use case). Disk-agnostic (local/S3/R2/SFTP) → **proxied** by Laravel and WordPress (unlike SFTP-only chmod).

- `GET /api/fm/search?disk=local&q=query&limit=50`
  - Searches file key/title/alt/caption/tags through the storage-backed index.

- `GET /api/fm/search-folders?disk=local&q=query&limit=50`
  - Searches tracked folder paths.

- `GET /api/fm/quota?disk=local`
  - Returns quota information using the JWT `max_storage` claim.
- `GET /api/fm/usage?disk=local[&refresh=true]`
  - Storage usage dashboard: quota status + size/count by type (extension-based) + largest folders, computed in one `QuotaManager::getUsageBreakdown` pass (excludes `_fluxfiles/`/`_variants/`). Cached per prefix in `_fluxfiles/usage.json` for `usage_cache_ttl`s; `?refresh=true` recomputes (rate-limited 2/min). Claims: `usage_cache_ttl`/`usage_warning_threshold`/`usage_critical_threshold`/`usage_top_folders_count`/`usage_folder_depth`. Proxied by the Laravel adapter (recomputes each call, no cache).

- `GET /api/fm/license`
  - Server's commercial edition/status (non-sensitive): `{edition, status, modules, limits, expires, days_left}`. Free MIT core → `{edition:'free'}`. Verified offline by `LicenseManager` against an embedded Ed25519 public key (`FLUXFILES_LICENSE_KEY` env). Proxied by Laravel + WordPress.
- `POST /api/fm/optimize` — **paid module** (`fluxfiles/optimize`, proprietary)
  - Recompresses an image to a smaller WebP (same dims, EXIF stripped). Gated by `ModuleRegistry::require('optimize', …)` — the **3-layer gate**: installed (`class_exists` → else 501 `module_not_installed`) + licensed (`LicenseManager` → 402 `license_required`/`license_expired`) + claim (`allow_optimize` → 403). Free core has no module package → 501. Returns JSON → proxied by Laravel + WordPress. The gate (`ModuleInterface`/`ModuleRegistry`/`Claims::isAllowed`) is the reusable seam for all future paid modules.

- `GET /api/fm/audit?limit=100&offset=0`
  - Lists current user's audit entries.

## Chunk Upload

- `POST /api/fm/chunk/init`
  - JSON body: `disk`, `path`.

- `POST /api/fm/chunk/presign`
  - JSON body: `disk`, `key`, `upload_id`, `part_number`.

- `POST /api/fm/chunk/complete`
  - JSON body: `disk`, `key`, `upload_id`, `parts`.

- `POST /api/fm/chunk/abort`
  - JSON body: `disk`, `key`, `upload_id`.

## Important Route Notes

- Per-tenant config (`ai_auto_tag`, `rate_read`/`rate_write`, `variants`, upload/ext/quota limits, `owner_only`, and the URL-import claims `allow_url_import`/`max_import_mb`/`import_url_allowlist`/`import_path`/`import_rate_limit`/`import_concurrency`) is carried in **JWT claims**, not a `/config` route — the token *is* the config.
- **Keys are relative to the token `prefix`.** When a token is path-scoped (e.g. `prefix=users/42/`), every `key`/`file_key`/`dir_key`/`next_cursor`/`original_key` returned is **stripped of the prefix** (the prefix is the tenant's root, kept invisible) — e.g. on-disk `users/42/reports` is returned as `reports`. The API accepts both relative and absolute paths on input (scoping is idempotent). `url`/`permanent_url` keep the **real** (prefixed) storage path. With no prefix, keys are unchanged.
- `list`, `search`, and `search-folders` rows include a stable `created` (Unix seconds) alongside `modified`; folders carry `created` too (S3/R2 dir prefixes may lack `modified`). The UI sorts by `created || modified`.
- Mutating requests validate the `Origin` header against `FLUXFILES_ALLOWED_ORIGINS` when origins are configured.
- Route responses usually follow `{ "data": ..., "error": null }`; API exceptions include `error_code` when available.
