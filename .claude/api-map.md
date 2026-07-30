# Core API Map

All routes below are implemented in `packages/core/api/index.php`.

**Token types.** Every narrow token handed to an untrusted party is signed with the same
`FLUXFILES_SECRET` but carries a type marker: `t=stream` (`StreamToken`), `t=img`
(`ImageToken`), `t=share_grant` (`ShareGrant`), `t=share` (share link), `t=intake` (upload
portal). A **main access JWT never has `t`** — `JwtMiddleware::handle` refuses any typed
token (and the legacy `share`/`intake` booleans) with `403 token_not_access`, so a share or
portal link can't be replayed as `Authorization: Bearer` to bypass the password, download
cap, expiry or revocation its own endpoint enforces.

## Public Routes

- `GET /public/index.html`: serves UI with injected locale data.
- `GET /public` and `GET /public/`: same UI route.
- `GET /api/fm/lang`: list available locales.
- `GET /api/fm/lang/{locale}`: fetch locale messages.
- `GET /api/fm/stream?token=<stream-token>`: gated local media stream (Range-capable). Authenticated by a per-file stream token (not the main JWT), since `<video>`/`<audio>` can't send headers. Only serves files on a `private => true` local disk (`FLUXFILES_LOCAL_PRIVATE=true`).
- `GET /api/fm/img?token=<image-token>&width=&height=&fit=&dpr=&quality=&format=`: on-demand image transform of one image, cached in the file's `_variants/`. Authenticated by a per-file `ImageToken` (distinct type from the stream token; an `<img>` can't send headers). **`format=auto` (default) content-negotiates AVIF → WebP → original from the `Accept` header** (AVIF when the build supports it); `format=avif`/`webp` force it — avif/webp cache as separate keys, response sends `Vary: Accept`. Width rounded to 100px + clamped to the token's `mw`; quality snaps to 60/75/80/90. **Box sizing:** `height` + `fit` (`cover` crops / `contain` fits, default) + `dpr` (1/2/3, multiplies the requested size) — all in the cache key, never upsizing. S3/R2 cache hits 302-redirect to a presigned URL; local serves bytes. Exposed per image as `img_base` in `list()` when `webp_enabled` + a stream secret are set. Each image also gets a ready-to-use **`img_srcset`** (candidate `/api/fm/img?…&width=W W` entries from the `srcset_widths` ladder — snapped to 100px, clamped to `webp_max_width`, capped at the source width; the source width is always offered) and, when the `srcset_sizes` claim is set, an **`img_sizes`** string. Pure metadata, same gate as `img_base`.

- `GET /api/fm/share/info?token=<share-jwt>` · `POST /api/fm/share/unlock {token,password}` · `GET /api/fm/share/file?token=<share-jwt>[&g=<grant>][&dl=1]`: the **Share landing** (`/public/share.html`), authenticated by the share token itself (no main JWT). Registered before the auth block, gated **installed + licensed only** (no claim — a public request has no token claims) → `501 module_not_installed` / `402 license_required` on free core. `info` counts a view and returns `{label, expires, has_password, downloads/max_downloads/remaining, file{name,size,mime,kind,preview_url}, files[], brand}` — no **response field** carries `path`, `disk`, `store`, `owner` or `password_hash` (the share token and the preview `ImageToken` are signed but *readable* JWTs scoped to the shared object, so the recipient can decode that one object key — which they are authorised to read anyway; what stays hidden is the rest of the tenant layout); a **password-protected share returns only `{has_password, expires, brand}`** until `unlock` exchanges the password for a short-TTL **`ShareGrant`** (`t=share_grant`, jti-bound, 10 min) that the bytes GET carries (the password never rides a query string). `file` enforces + counts **before** any byte, then dispatches on the **disk driver**: `s3` (S3 + R2) → 302 to a `share_url_ttl`-bounded presigned URL (`ResponseContentDisposition`), `local` (private **and** public) + `sftp` → streamed through the app (a static local URL is never emitted). Inline only for the `handleMediaStream` MIME set, `dl=1` forces attachment; `nosniff` + `CSP: sandbox` + `no-store` + `no-referrer`. Preview: images via `/api/fm/img` (bounded, never counted), PDFs only on an **uncapped** share. Rate limits: `FLUXFILES_SHARE_RATE_LIMIT` (60/min per share) + `FLUXFILES_SHARE_UNLOCK_LIMIT` (5/min per share+IP) **and** `FLUXFILES_SHARE_UNLOCK_TOTAL` (30/min per share, no IP component — the ceiling IP rotation cannot escape). Errors: `share_invalid` 403 · `share_expired` 410 · `share_revoked` 404 · `share_password` 401 · `share_grant_invalid` 403 · `share_exhausted` 410 · `share_gone` 404 · `share_unavailable` 502.

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
  - JSON body: `disk`, `from`, `to`. Folder moves/renames relocate the **whole
    subtree incl. empty subdirectories** (`FileManager::moveDirectoryTree`: one
    atomic adapter `move()` on local/SFTP, a marker-recreating walk on S3/R2) and
    only drop the source once the destination exists. Moving a folder into its own
    subtree → `400 move_into_self`.

- `POST /api/fm/copy`
  - JSON body: `disk`, `from`, `to`. Files only — a directory source is rejected
    with `400 copy_dir_unsupported` (recursive folder copy isn't implemented).

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
- `POST /api/fm/optimize` — **FREE / core** (`\FluxFiles\OptimizeModule` + `\FluxFiles\PdfOptimizer`)
  - Recompresses an image to a smaller WebP (same dims, EXIF stripped) + compresses PDFs via Ghostscript (`pdf_level`; `501 pdf_unavailable` when `gs` absent). Single + batch (`paths[]`) + on-upload `auto_optimize`; savings recorded in `_fluxfiles/` (usage dashboard). Opt-in per token via the free `allow_optimize` claim (it replaces/deletes originals → `403 optimize_forbidden` without it); needs `write` (and `delete` to replace the original). Returns JSON → proxied by Laravel + WordPress. *(Was a paid module; folded into the MIT core once AVIF/WebP delivery became free in `/img`. The license/3-layer gate — `ModuleInterface`/`ModuleRegistry`/`Claims::isAllowed` — still gates the remaining paid modules: share/ai/ocr/virus/backup/c2pa.)*

- `POST /api/fm/share {disk, path, ttl?, label?, password?, max_downloads?}` · `GET /api/fm/share/list?disk=` · `POST /api/fm/share/revoke {disk, jti}` — **paid** (`share`, 3-layer gate incl. `allow_share`)
  - Operator side of the landing above. Create returns `{token, jti, expires, label, max_downloads, has_password, url}` — the **token is returned once and never stored**, so a listed share can only be revoked, not re-linked; `url` comes from the `share_base_url` claim, else the request origin + `/public/share.html`. The record (`<store>/_fluxfiles/shares.json`) carries `store`/`owner`/`label`/`url_ttl`/`preview`; `list`/`revoke` are owner-filtered under `owner_only`. Claims: `share_url_ttl`, `share_base_url`, `share_preview` (all read at create time and baked into the record, so a public request needs none). Core-standalone — not proxied by the adapters.

- `POST /api/fm/intake {disk, path(dir), ttl?, label?, password?, max_files?, max_mb?, allowed_ext?}` · `GET /api/fm/intake/list?disk=` · `POST /api/fm/intake/revoke {disk, jti}` — **paid** (`intake`, 3-layer gate incl. `allow_intake`)
  - Operator side of the Upload Portals (`/public/intake.html` + the pre-auth `intake/info|upload`). Create mints a **write-only** portal token scoped to the folder and returns `{token, jti, expires, label, max_files, max_mb, allowed_ext, has_password, url}` — same one-shot posture as Share (never stored, revoke-only), and `url` comes from the `intake_base_url` claim, else the request origin + `/public/intake.html`. The record (`<store>/_fluxfiles/intakes.json`) carries `store`/`owner`/caps/`received`; `list`/`revoke` are owner-filtered under `owner_only` (a legacy record without `owner` fails **closed**), and revoke writes a **tombstone** so an interleaved upload's counter write can't resurrect the portal. Core-standalone — not proxied by the adapters.

- `POST /api/fm/watermark {disk, path, type, text?, logo_data?, x, y, scale, opacity, color?, dest?}` — **FREE / core**
  - Burn-in watermark editor (drag-and-drop logo/text), permanent into the file bytes. Needs `write`. **Non-destructive:** snapshots the true original to `_fluxfiles/originals/` on the first burn, so re-editing reads from the clean original (no stacking) and `dest` saves a copy (extension immutable). Rejects burn-in on a preview/overlay-watermark token (`409 watermark_overlay_active`). Distinct from the serve-time **overlay** (`watermark_*` claims on `/img`, preview-only). Proxied by Laravel + WordPress.
- `POST /api/fm/watermark/remove {disk, path}` — restores the pre-watermark original from the backup (`404 no_watermark_backup` if none), clears `meta.watermarked`. Proxied by Laravel + WordPress.

- `POST /api/fm/terminal {disk, cmd, cwd?, confirm?}` — **FREE / core**, SFTP disks only
  - Stateless SSH **command-runner** (one `exec` per request over phpseclib; cwd threaded back). Hard-gated: server kill-switch `FLUXFILES_TERMINAL_DISABLED`, the `allow_terminal` claim (default off), `write` perm, an SFTP disk that grants a shell, a dangerous-command double-confirm (`409 terminal_confirm_required`). Core-standalone (NOT proxied; laravel forwards the claim only in standalone mode). **Free PTY upgrade:** the `terminal_pty_url` claim makes the UI embed a self-hosted ttyd/gotty/wetty instead — no server-side endpoint change.

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

- Per-tenant config is carried in **JWT claims**, not a `/config` route — the token *is* the config. **[`docs/CONFIG.md`](../docs/CONFIG.md) is the single source of truth for all ~65 claims + env vars** (grouped: access, upload, images/WebP/AVIF, watermark, media, optimize, terminal, usage, import, paid-gates). Mint them in one options object: `fluxfiles_token(['user'=>…, 'claims'=>[…]])`, where `claims` is the escape hatch for any claim by its raw name (same passthrough in node/laravel/wordpress).
- **Keys are relative to the token `prefix`.** When a token is path-scoped (e.g. `prefix=users/42/`), every `key`/`file_key`/`dir_key`/`next_cursor`/`original_key` returned is **stripped of the prefix** (the prefix is the tenant's root, kept invisible) — e.g. on-disk `users/42/reports` is returned as `reports`. The API accepts both relative and absolute paths on input (scoping is idempotent). `url`/`permanent_url` keep the **real** (prefixed) storage path. With no prefix, keys are unchanged.
- `list`, `search`, and `search-folders` rows include a stable `created` (Unix seconds) alongside `modified`; folders carry `created` too (S3/R2 dir prefixes may lack `modified`). The UI sorts by `created || modified`.
- Mutating requests validate the `Origin` header against `FLUXFILES_ALLOWED_ORIGINS` when origins are configured.
- Route responses usually follow `{ "data": ..., "error": null }`; API exceptions include `error_code` when available.
