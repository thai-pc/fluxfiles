# Core API Map

All routes below are implemented in `packages/core/api/index.php` (verified against
core-v0.2.100). Confirm a route before relying on it:
`rg "uri ===" packages/core/api/index.php`.

**Proxy parity.** The Laravel (`packages/laravel/routes/fluxfiles.php` +
`FluxFilesServiceProvider`) and WordPress (`FluxFilesApi::registerRoutes()`) adapters
proxy **every route on this page except**: `/api/fm/tus*` (resumable upload — the UI
falls back to a plain upload there), `/api/fm/metadata/export` + `/api/fm/metadata/import`
(DB-backend tooling that talks to core's own `Db\Connection`), and the SSO bridge
(`/api/fm/sso/*`, a standalone-UI login screen). Both proxy smoke suites keep those in
an explicit "intentionally unproxied" allowlist. The public share/intake recipient
routes and `/stream`/`/img` are proxied too (registered outside the auth group).

**Token types.** Every narrow token handed to an untrusted party is signed with the same
`FLUXFILES_SECRET` but carries a type marker: `t=stream` (`StreamToken`), `t=img`
(`ImageToken`), `t=share_grant` (`ShareGrant`), `t=share` (share link), `t=intake` (upload
portal), `t=sso_state` / `t=sso_boot` (SSO). A **main access JWT never has `t`** —
`JwtMiddleware::handle` refuses any typed token (and the legacy `share`/`intake` booleans)
with `403 token_not_access`, so a share or portal link can't be replayed as
`Authorization: Bearer` to bypass the password, download cap, expiry or revocation its
own endpoint enforces.

## Public Routes

- `GET /public/index.html` (also `GET /public` and `GET /public/`): serves the UI with injected locale data (and `window.__FM_SSO__` / demo boot data when enabled).
- `GET /api/fm/lang`: list available locales.
- `GET /api/fm/lang/{locale}`: fetch locale messages.
- `GET /api/fm/stream?token=<stream-token>`: gated media stream (Range-capable). Authenticated by a per-file stream token (not the main JWT), since `<video>`/`<audio>` can't send headers. Serves files on a `private => true` local disk (`FLUXFILES_LOCAL_PRIVATE=true`) and SFTP disks (no static/presigned URL exists for SFTP). Own rate bucket `FLUXFILES_STREAM_RATE_LIMIT` (300/min per token `sub`).
- `GET /api/fm/img?token=<image-token>&width=&height=&fit=&dpr=&quality=&format=`: on-demand image transform of one image, cached in the file's `_variants/`. Authenticated by a per-file `ImageToken` (distinct type from the stream token; an `<img>` can't send headers). **`format=auto` (default) content-negotiates AVIF → WebP → original from the `Accept` header** (AVIF when the build supports it); `format=avif`/`webp` force it — avif/webp cache as separate keys, response sends `Vary: Accept`. Width rounded to 100px + clamped to the token's `mw`; quality snaps to 60/75/80/90. **Box sizing:** `height` + `fit` (`cover` crops / `contain` fits, default) + `dpr` (1/2/3, multiplies the requested size) — all in the cache key, never upsizing. S3/R2 cache hits 302-redirect to a presigned URL; local serves bytes. An undecodable body (valid header, truncated data) is treated as untransformable (415 for a preview-only token, the original otherwise). Own rate bucket `FLUXFILES_IMG_RATE_LIMIT` (120/min). Exposed per image as `img_base` in `list()` when `webp_enabled` + a stream secret are set. Each image also gets a ready-to-use **`img_srcset`** (candidate `/api/fm/img?…&width=W W` entries from the `srcset_widths` ladder — snapped to 100px, clamped to `webp_max_width`, capped at the source width; the source width is always offered) and, when the `srcset_sizes` claim is set, an **`img_sizes`** string. Pure metadata, same gate as `img_base`.

- `GET /api/fm/share/info?token=<share-jwt>` · `POST /api/fm/share/unlock {token,password}` · `GET /api/fm/share/file?token=<share-jwt>[&g=<grant>][&dl=1]`: the **Share landing** (`/public/share.html`), authenticated by the share token itself (no main JWT). Registered before the auth block, gated **installed + licensed only** (no claim — a public request has no token claims) → `501 module_not_installed` / `402 license_required` on free core. `info` counts a view and returns `{label, expires, has_password, downloads/max_downloads/remaining, file{name,size,mime,kind,preview_url}, files[], brand}` — no **response field** carries `path`, `disk`, `store`, `owner` or `password_hash` (the share token and the preview `ImageToken` are signed but *readable* JWTs scoped to the shared object, so the recipient can decode that one object key — which they are authorised to read anyway; what stays hidden is the rest of the tenant layout); a **password-protected share returns only `{has_password, expires, brand}`** until `unlock` exchanges the password for a short-TTL **`ShareGrant`** (`t=share_grant`, jti-bound, 10 min) that the bytes GET carries (the password never rides a query string). `file` enforces + counts **before** any byte, then dispatches on the **disk driver**: `s3` (S3 + R2) → 302 to a `share_url_ttl`-bounded presigned URL (`ResponseContentDisposition` + extension-pinned `ResponseContentType`), `local` (private **and** public) + `sftp` → streamed through the app (a static local URL is never emitted). Inline only for the `MediaEndpoint` MIME set, `dl=1` forces attachment; `nosniff` + `CSP: sandbox` + `no-store` + `no-referrer`. Preview: images via `/api/fm/img` (bounded, never counted), PDFs only on an **uncapped** share. Rate limits: `FLUXFILES_SHARE_RATE_LIMIT` (60/min per share) + `FLUXFILES_SHARE_UNLOCK_LIMIT` (5/min per share+IP) **and** `FLUXFILES_SHARE_UNLOCK_TOTAL` (30/min per share, no IP component — the ceiling IP rotation cannot escape). Buckets key on the `jti` only for a signature-verified token, else on the client IP (junk tokens can't bloat `rate_limit.json`). Errors: `share_invalid` 403 · `share_expired` 410 · `share_revoked` 404 · `share_password` 401 · `share_grant_invalid` 403 · `share_exhausted` 410 · `share_gone` 404 · `share_unavailable` 502.

- `GET /api/fm/intake/info?token=<portal-jwt>` · `POST /api/fm/intake/upload` (multipart, `token` + `file` [+ `password`]): the **Upload Portal** landing (`/public/intake.html`), authenticated by the write-only portal token. Same pre-auth, installed + licensed gate as Share. Uploads run the normal upload pipeline bound by the **operator's** `max_storage`/`max_files`, DLP-scanned when the operator's token had `allow_dlp_scan`, `strip_exif` forwarded, and are owned by the operator (so `owner_only` operators can manage them). Rate limits `FLUXFILES_INTAKE_RATE_LIMIT` / `FLUXFILES_INTAKE_UPLOAD_LIMIT` / `FLUXFILES_INTAKE_UPLOAD_TOTAL`.

- `GET /api/fm/sso/login?redirect=` · `GET /api/fm/sso/callback?code=&state=` · `POST /api/fm/sso/exchange {token}`: the **SSO Bridge** (paid module `sso`), a login screen for the **standalone `/public` UI** when no host app mints tokens via `fluxfiles_token()`. OIDC only (no SAML). Not proxied by the adapters. Registered pre-auth like the share landing, but gated differently — there's no `Claims` yet, so `ModuleRegistry::requireServer($id, $license)` (installed + licensed only, layers 1+2) is used instead of `require()`, and layer 3 is an explicit `FLUXFILES_SSO_ENABLED === 'true'` env check in each route (`403 sso_disabled` otherwise) rather than a per-token claim (`SsoModule::claim()` returns `''`). `login` validates `redirect` is a same-origin relative path (open-redirect guard), signs an `SsoStateToken` (`t=sso_state`, 600s, carries the OIDC `nonce`), and 302s to the IdP's `authorization_endpoint` **with PKCE (S256)**. `callback` verifies `state`, exchanges `code` (+ PKCE verifier) at the `token_endpoint`, verifies the `id_token` via JWKS (`iss`/`aud`/`nonce` checked manually), resolves the identity's groups to a claims set via `FLUXFILES_SSO_CLAIMS_MAP` — **the first map entry, in the order the map is written, whose group the user holds** (never the IdP's group order), dot-path `FLUXFILES_SSO_GROUPS_CLAIM`, fail-closed `403 sso_no_mapping` — sets the user id from `FLUXFILES_SSO_USER_CLAIM` (default `sub`; `email` only when `email_verified` is true), mints the real access JWT via `fluxfiles_token()`, seals it (AES-256-GCM) inside an `SsoBootToken` (`t=sso_boot`, 60s), and 302s to `/public/index.html#boot=<boot-token>` — a URL **fragment**, never a query string, so the real JWT is never written to server access/Referer logs. `exchange` verifies the boot token and returns `{data: {token, expires_at}, error: null}` — the only place the real JWT is ever transmitted, and only over a `POST` body. `window.__FM_SSO__ = {enabled, loginUrl}` is injected into `/public/index.html` only when SSO is enabled + installed, so the UI's "Sign in with SSO" button is absent by default. Issuer/JWKS/token-endpoint URLs are operator `.env` config, not user input — no `SsrfGuard` needed (same posture as `FLUXFILES_AIVISION_ENDPOINT`).

## Authenticated File Routes

All authenticated routes require `Authorization: Bearer <JWT>`.

- `GET /api/fm/list?disk=local&path=&limit=0&cursor=`
  - Lists files and directories. With `limit > 0`, returns `{items,next_cursor,total}`; otherwise returns the legacy flat array. Folders always come first. `sort=name|date|size|type` + `order=asc|desc` (natural, case-insensitive names; `date` = storage mtime; ties break by name, then key) and `type=image|video|audio|document|other` (files only; same buckets as the UI's `fileIcon()`, guarded by `test-list-sort-filter.php`) apply to the whole folder before paging, and `total` counts what matched; unknown values → 400. With no `sort`, order is by key, and on S3/R2 a page whose cursor is a file reads the bucket from that key on (`ListObjectsV2 StartAfter`) instead of re-listing the folder, returning `total: null` — keep the first page's count. Rows carry `on_hold` (legal hold), `poster` (when `media_posters` produced one) and `img_base`/`img_srcset` for images.

- `POST /api/fm/upload`
  - Multipart form fields: `file`, `disk`, `path`, optional `force_upload`. Pipeline: ext/dangerous-name checks, quota/`max_files`, collision policy, legal hold on overwrite, virus/DLP scan (fail-closed, before write), `strip_exif`, variants, `media_posters`, ownership, optional AI tag/`auto_optimize`.

- `POST /api/fm/import-url`
  - JSON body: `url` (required), `disk`, `path`, `filename`, `overwrite`. Server-side
    fetch of a public URL → the upload pipeline. Gated by the `allow_url_import`
    claim (default false → 403). SSRF-guarded (`SsrfGuard`): per-hop IP denylist +
    pinned resolution + post-connect re-check, streaming size cap, magic-byte MIME
    deny-list, SVG off by default. Its own rate bucket (`import_rate_limit`, default
    10/min). See `UrlImporter.php`.

- `POST /api/fm/tus` · `HEAD|PATCH|DELETE /api/fm/tus/<id>` (+ `OPTIONS`) — **resumable upload, tus 1.0** (`TusServer.php`, core + `creation` + `termination` extensions). **Core-standalone only** (not proxied).
  - Not a JSON-envelope API: status + headers only. Create carries `Upload-Length` + `Upload-Metadata: filename,path,disk` (base64); **every upload rule is checked at creation** before a byte is staged — write perm, disk, extension/dangerous name, `max_upload`, path scope, quota, `max_files`, name collision, `owner_only`, legal hold. The upload is bound to the creating token's `sub` **and** tenant `prefix` **and** disk (others get 404). `PATCH` appends at `Upload-Offset` (`409` wrong offset, `423` concurrent PATCH, `413` past `Upload-Length`); the final chunk runs the normal upload pipeline and returns the result as base64 JSON in `X-FluxFiles-Result`. Partial bytes staged in `<FLUXFILES_STORAGE_PATH>/tus/` (swept after 24 h, max 20 per user) — **multi-node deployments must share that directory or use sticky sessions**. PATCH/HEAD use their own bucket `FLUXFILES_TUS_RATE_LIMIT` (600/min); creation counts against the write bucket. The UI uses it for files > 8 MB on non-S3 disks.

- `DELETE /api/fm/delete`
  - JSON body: `disk`, `path`. **Permanent** (no trash).

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

- `POST /api/fm/cross-copy` · `POST /api/fm/cross-move`
  - JSON body: `src_disk`, `src_path`, `dst_disk`, `dst_path`. Quota and `max_files` checked on the destination.

- `POST /api/fm/presign`
  - JSON body: `disk`, `path`, `method` (`GET`/`PUT`/`POST`), `ttl`, `size` (PUT/POST).
  - **GET** (TTL ≤ 86400s): presigned download URL, with `ResponseContentDisposition` and an extension-derived `ResponseContentType` signed in (a spoofed stored type can't render inline HTML).
  - **PUT** (TTL ≤ 900s, `MAX_PUT_PRESIGN_TTL`): the `max_upload`/`max_storage`/`max_files` checks run only against the client-declared `size` at mint time — `ContentLength` **cannot** be signed into an S3/R2 URL (the AWS SDK drops that header from the signature unconditionally), so these checks are **advisory only**. Operators can refuse PUT with `allow_presign_put: false` → `403 presign_put_disabled`.
  - **POST** (TTL ≤ 900s): returns `{url, fields, expires_at, max_bytes}` — an S3 form-upload policy the **bucket enforces** (`content-length-range` = declared size, exact key, extension-pinned `Content-Type`). Cloudflare R2 has no POST Object → `400 presign_post_unsupported`.
  - Like `/api/fm/chunk/*`, PUT and POST are **refused** (`409 virus_unscannable` / `dlp_unscannable`) while `allow_virus_scan` / `allow_dlp_scan` is on — browser→S3 bytes can never be scanned. A PUT/POST onto an existing held key → `403 legal_hold_active`.

- `POST /api/fm/crop`
  - JSON body: `disk`, `path`, `x`, `y`, `width`, `height`, optional `save_path`.

- `POST /api/fm/ai-tag`
  - JSON body: `disk`, `path`. Free; BYO provider key (`FLUXFILES_AI_*`).

- `GET /api/fm/meta?disk=local&path=file.jpg`
  - Returns file URL, size, modified time, mime-ish metadata, and variants.

- `GET /api/fm/disk/doctor?disk=local&origin=`
  - **Bucket Doctor**: diagnose a disk (credentials, read/write/delete, presign, CORS, multipart, versioning) and return a report + IAM/CORS remediation. Requires `write` + the disk in the token. Useful to validate BYOB credentials before issuing a long-lived token.

## Trash (soft-delete)

Soft-delete is move-based into `_fluxfiles/trash/<id>/` with a `_fluxfiles/trash.json`
manifest (storage-resident, scoped by prefix/owner). Files **and folders** are
supported; folders move the whole subtree incl. variants. All gated by the
`delete` permission. Trash ids are validated `[A-Za-z0-9_-]`. Held paths
(legal hold) can't be trashed/purged; `trash/empty` skips held entries and reports `{purged, held}`.

- `POST /api/fm/trash` — JSON body: `disk`, `path`. Soft-delete to trash.
- `POST /api/fm/trash/restore` — JSON body: `disk`, `trash_id`, optional `path`.
- `GET /api/fm/trash/list?disk=local` — list current scope's trash entries.
- `POST /api/fm/trash/purge` — JSON body: `disk`, `trash_id`. Permanently remove one entry.
- `POST /api/fm/trash/empty` — JSON body: `disk`. Permanently empty the scope's trash.

> `DELETE /api/fm/delete` is **permanent** (no trash). The UI soft-deletes via `/trash`.

## Metadata / Archives / Editor / Search / Quota

- `GET /api/fm/metadata?disk=local&key=file.jpg` · `PUT /api/fm/metadata {disk, key, title?, alt_text?, caption?, tags?}` · `DELETE /api/fm/metadata {disk, key}`
  - The key may be prefix-free (as `list()` returns it) or already scoped — it is scoped server-side; a sibling tenant's path is still refused. PUT/DELETE on a held file → `legal_hold_active`.

- `GET /api/fm/metadata/export?disk=&prefix=&format=ndjson|csv` / `POST /api/fm/metadata/import {disk, entries[]}`
  - Bulk backup/restore of `file_metadata` rows for the DB storage backend (`FLUXFILES_STORAGE_BACKEND=db`, `docs/design/DB-STORAGE-MIGRATION-DESIGN.md` §7). **Free/core, not a paid module.** `501` when the backend isn't `db`. Per-tenant, not admin-only: export respects the caller's own `pathPrefix`/`owner_only` scope (read perm; a scoped token cannot widen itself via `?prefix=`); import validates every entry's `path` against `Claims::isPathInScope()` **before writing any row** — one out-of-scope row rejects the whole batch (write perm, `422 metadata_import_rejected` with a per-row `error_params.errors` list); an import never reassigns or wipes an existing owner. CLI mirrors: `scripts/export-metadata.php` / `scripts/import-metadata.php` (direct DB access, no JWT scope). **Core-standalone only** — not ported into the Laravel/WordPress proxy controllers.

- `GET /api/fm/chmod?disk=&path=` / `POST /api/fm/chmod {disk, path, mode}`
  - SFTP file permissions (cPanel-style). GET reads the 3-digit octal mode (read perm); POST sets it (write perm + `allow_chmod` claim, default true; octal `0?[0-7]{3}`, not recursive). SFTP-only (non-SFTP disk → 400). Proxied by both adapters (SFTP reaches proxy mode through a BYOB `sftp` disk).

- `POST /api/fm/zip {disk, paths[], name?}`
  - Streams a `.zip` of the selected files **and folders** (recursive) — constant-memory via ZipStream (each entry piped through Flysystem readStream; local/S3/R2/SFTP). Read perm + `allow_zip` + `allow_download`; folders expanded with name preserved, `owner_only` enforced (assertOwnsTree/assertOwner), `_fluxfiles`/`_variants`/`.meta.json` and (without `show_hidden`) dotfiles skipped, archive-name de-dupe. Pre-flight sums `fileSize` → `413` if over `zip_max_mb`/`zip_max_files` before any byte. Bypasses the JSON encoder (ZipStream sends its own headers, route `exit`s); a guard/size throw happens first → JSON error. Audited as `zip_download`. Proxied by both adapters.

- `POST /api/fm/extract {disk, path, dest?}`
  - Extracts a `.zip` in place (default `dest` = a folder named after the archive). Two passes: structural/path/size/quota checks run before writing; scanning and storage writes run per entry. **Not transactional:** a scan or storage failure can leave earlier successful entries in place. Write perm + `allow_extract`. Guards: **zip-slip** (absolute/`..`/drive-letter → 403 `zip_slip`), dangerous-ext (`assertSafeFilename`) + `allowed_ext`, system-path + SFTP `.git/` skip, **zip-bomb** (declared uncompressed size + count caps → 413, and the bytes actually inflated are counted against the declared size), **quota** and **max_files** before writing. Images inside get `strip_exif` when the claim is on. Proxied by both adapters.

- `GET /api/fm/content?disk=&path=` / `PUT /api/fm/content {disk, path, content}`
  - Config/code editor: read/overwrite a file's **text** content (cPanel-style — `wp-config.php`, `.env`, `nginx.conf`, `deploy.sh`). GET needs read perm + `allow_download` (binary→415, >5 MB→413; audited as `content_read` only with `audit_reads`). PUT needs write perm **+ `allow_code_edit` claim (default FALSE, opt-in)** and only edits an **existing** file (404 otherwise; no creating new executables). `assertExt` (allowed_ext policy) still applies, but the always-on dangerous-ext upload block does **not** (editing `.php`/`.sh` is the use case). Dotfiles need `show_hidden`. Disk-agnostic (local/S3/R2/SFTP). Proxied by both adapters.

- `GET /api/fm/search?disk=local&q=query&limit=50`
  - Searches file key/title/alt/caption/tags through the storage-backed index.

- `GET /api/fm/search-folders?disk=local&q=query&limit=50`
  - Searches tracked folder paths.

- `GET /api/fm/quota?disk=local`
  - Returns quota information using the JWT `max_storage` claim. Like every other
    disk-taking route, both this and `/usage` below enforce the token's `disks`
    allowlist (`403 disk_denied`) + `read` perm **at the route** — `QuotaManager`
    has no ACL of its own and `DiskManager` builds any configured disk.
- `GET /api/fm/usage?disk=local[&refresh=true]`
  - Storage usage dashboard: quota status + size/count by type (extension-based) + largest folders, computed in one `QuotaManager::getUsageBreakdown` pass (excludes `_fluxfiles/`/`_variants/`). Cached per prefix in `_fluxfiles/usage.json` for `usage_cache_ttl`s; `?refresh=true` recomputes (rate-limited 2/min). Optimize savings (`OptimizeStats`) are folded in as `optimize`. Claims: `usage_cache_ttl`/`usage_warning_threshold`/`usage_critical_threshold`/`usage_top_folders_count`/`usage_folder_depth`.
- `GET /api/fm/duplicates?disk=local&limit=50` — **free/core** duplicate finder (`FileManager::findDuplicates`)
  - Byte-identical files under the token's root: `{groups[{hash,size,count,wasted,files[{key,modified}]}], total_wasted, scanned_files, truncated}`. One recursive listing, a size pre-filter, then a streamed SHA-256 of only the size-colliding candidates, capped at 512 MB read per call (`truncated: true` past that). Read perm; shares the 2/min `usage_refresh` bucket. The UI moves extra copies to trash via `/trash`.

- `GET /api/fm/license`
  - Server's commercial edition/status (non-sensitive): `{edition, status, modules, limits, expires, days_left}`. Free MIT core → `{edition:'free'}`. Verified offline by `LicenseManager` against an embedded Ed25519 public key (`FLUXFILES_LICENSE_KEY` env).

- `POST /api/fm/optimize` — **FREE / core** (`\FluxFiles\OptimizeModule` + `\FluxFiles\PdfOptimizer`)
  - Recompresses an image to a smaller WebP (same dims, EXIF stripped) + compresses PDFs via Ghostscript (`pdf_level`; `501 pdf_unavailable` when `gs` is absent **or older than 10.05.0**, which is treated as not installed). Single + batch (`paths[]`) + on-upload `auto_optimize`; savings recorded in `_fluxfiles/` (usage dashboard). Opt-in per token via the free `allow_optimize` claim (it replaces/deletes originals → `403 optimize_forbidden` without it); needs `write` (and `delete` to replace the original). *(Was a paid module; folded into the MIT core once AVIF/WebP delivery became free in `/img`. It is not in `ModuleRegistry::$map`.)*

- `POST /api/fm/watermark {disk, path, type, text?, logo_data?, x, y, scale, opacity, color?, dest?}` — **FREE / core**
  - Burn-in watermark editor (drag-and-drop logo/text), permanent into the file bytes. Needs `write`. **Non-destructive:** snapshots the true original to `_fluxfiles/originals/` on the first burn, so re-editing reads from the clean original (no stacking) and `dest` saves a copy (extension immutable). Rejects burn-in on a preview/overlay-watermark token (`409 watermark_overlay_active`). Distinct from the serve-time **overlay** (`watermark_*` claims on `/img`, preview-only).
- `POST /api/fm/watermark/remove {disk, path}` — restores the pre-watermark original from the backup (`404 no_watermark_backup` if none), clears `meta.watermarked`.

## Paid-module routes (3-layer gate: installed → licensed → `allow_<x>` claim; `501` / `402` / `403`)

`ModuleRegistry::$map` is the source of truth for the module list (13 at core-v0.2.100).
All of these are proxied by the Laravel and WordPress adapters.

- `POST /api/fm/share {disk, path, ttl?, label?, password?, max_downloads?}` · `GET /api/fm/share/list?disk=` · `POST /api/fm/share/revoke {disk, jti}` · `GET /api/fm/share/analytics?disk=&jti=&limit=&offset=&event=` — `share` (`allow_share`)
  - Operator side of the landing above. Create returns `{token, jti, expires, label, max_downloads, has_password, url}` — the **token is returned once and never stored**, so a listed share can only be revoked, not re-linked; `url` comes from the `share_base_url` claim, else the request origin + `/public/share.html`. The record (`<store>/_fluxfiles/shares.json`) carries `store`/`owner`/`label`/`url_ttl`/`preview`/`brand`; `list`/`revoke` are owner-filtered under `owner_only`; revoking an unknown id writes nothing. Claims: `share_url_ttl`, `share_base_url`, `share_preview`, `share_brand_*` (read at create time and baked into the record, so a public request needs none). `analytics` returns the per-event log (`view`/`download`/`unlock_fail`, timestamp, IP truncated to /24 (IPv4) or /48 (IPv6), user-agent) — populated only for shares created with `share_analytics` on; `limit` (1–500, default 100) + `offset` + an optional `event` filter, owner-scoped the same way as `revoke`.

- `POST /api/fm/intake {disk, path(dir), ttl?, label?, password?, max_files?, max_mb?, allowed_ext?}` · `GET /api/fm/intake/list?disk=` · `POST /api/fm/intake/revoke {disk, jti}` · `GET /api/fm/intake/analytics?disk=&jti=&limit=&offset=&event=` — `intake` (`allow_intake`)
  - Operator side of the Upload Portals. Create mints a **write-only** portal token scoped to the folder and returns `{token, jti, expires, label, max_files, max_mb, allowed_ext, has_password, url}` — same one-shot posture as Share (never stored, revoke-only), and `url` comes from the `intake_base_url` claim, else the request origin + `/public/intake.html`. The record (`<store>/_fluxfiles/intakes.json`) carries `store`/`owner`/caps/`received`/`brand` and the webhook config for notify-on-receipt; `list` returns `has_webhook`, never the signing secret; `list`/`revoke` are owner-filtered under `owner_only` (a legacy record without `owner` fails **closed**), and revoke writes a **tombstone** so an interleaved upload's counter write can't resurrect the portal. `analytics` returns the per-event log (`received`/`rejected`, timestamp/IP/user-agent/reason/filename) — populated only for portals created with `intake_analytics` on.

- `GET /api/fm/versions?disk=local&path=` · `POST /api/fm/versions/restore {disk, path, version_id}` — `versioning` (`allow_versioning`)
  - Prior-version list/restore for a file. Storage-resident, no DB: `FileManager` calls `VersioningModule::keep()` before an overwrite (incl. a zip-extract overwrite), snapshotting bytes to `_fluxfiles/versions/<hash>/` + a `manifest.json`; retention (`versioning_max`, default 10, hard cap 100) prunes oldest. History follows a file through rename/move/restore-to-new-path (`setVersionMover`) and is erased when the file is purged from trash or its folder deleted. `list` needs `read`; `restore` needs `write` + `assertCanModifyScopedPath`, and itself snapshots the current bytes first (a restore is reversible).

- `POST /api/fm/webhooks/test` — `webhooks` (`allow_webhooks`)
  - Sends a one-off ping to the token's configured `webhook_url` (HMAC-signed with `webhook_secret` or a key derived from the server secret) and reports `{sent, url, status, error}` — unlike the silent best-effort `dispatch()` used for real events, `test` throws `400 webhook_not_configured` / `403 webhook_blocked` (SSRF-checked URL). Real events are post-response, at-most-once.

- `POST /api/fm/ai-vision {disk, path, op, dest?}` — `ai` (`allow_ai_vision`)
  - BYO-key image ops: `op` is one of `bg_remove`/`upscale`/`smart_crop`, dispatched to an operator-configured provider via `FLUXFILES_AIVISION_PROVIDER` (`removebg` or `http` for a self-hosted endpoint) / `FLUXFILES_AIVISION_KEY` / `FLUXFILES_AIVISION_ENDPOINT` — absent → `501 aivision_unconfigured`. Size-capped before reading (`aivision_too_large`); provider output must be a real raster image before it is written. Needs `write`; writes the result to `dest` (default `<name>-<op-suffix>.<ext>`). Operator UI: detail panel + context menu + action sheet.

- `POST /api/fm/ocr {disk, path, lang?}` — `ocr` (`allow_ocr`)
  - Extracts text from an image via the `tesseract` binary (checked at `/usr/bin`, `/usr/local/bin`, `/opt/homebrew/bin`, then `PATH`; run with a minimal environment) — absent → `501 ocr_unavailable`. Needs `write`. Returns `{path, chars, text}`; the operator UI can copy it or "save as caption".

- `POST /api/fm/virus/scan {disk, path}` — `virus` (`allow_virus_scan`)
  - On-demand scan of an **already stored** file (uploaded before scanning was on, or via a direct-to-bucket path no scan covers). Read-only — reports, never deletes. Returns `{path, clean, threat, scanned_at}`; a malformed verdict counts as not clean; files over 512 MB → `413`. Audited as `virus_scan`. The write-path scan (upload/import-url/`PUT /content`/each extract entry, before bytes are written, fail-closed) needs no route of its own.

- `POST /api/fm/backup {from_disk, to_disk, path?, overwrite?}` — `backup` (`allow_backup`)
  - One-way subtree sync between two disks the token can access (e.g. local/SFTP → S3/R2), reusing cross-disk copy semantics; synced copies keep owner/metadata on the destination (`FileManager::attachCopiedFile()`). Stateless/on-demand — no resident queue, meant to be driven by an external cron. Needs `read`+`write` on both disks; skips `_fluxfiles/`/`_variants/`, skips existing destination files unless `overwrite`, capped at 5000 files/run (`413 too_many` past that). Returns `{from, to, copied, skipped, errors, bytes}`.

- `POST /api/fm/c2pa {disk, path}` · `POST /api/fm/c2pa/sign {disk, path, dest?}` — `c2pa` (`allow_c2pa`)
  - Content-provenance (Content Credentials) via the `c2patool` binary — absent → `501 c2pa_unavailable`; size-capped before reading (`c2pa_too_large`). `verify` needs `read`, returns the parsed manifest summary (or `{signed:false}`). `sign` needs `write` + a configured signing manifest (`FLUXFILES_C2PA_MANIFEST`, a JSON file with the cert/key — never in a token/request; absent → `501 c2pa_unconfigured`), stages the file through `c2patool` via a fixed arg array (no shell, minimal environment) and writes to `dest` (default `<name>-signed.<ext>`), keeping the source's owner. No operator UI.

## Audit, compliance, legal hold

- `GET /api/fm/audit?limit=100&offset=0&action=&from=&to=&path=&actor=` — **free/core**
  - Activity log, scoped to the token's prefix. Requires the `audit` permission. Same-second entries list newest first (write order breaks the tie).

- `GET /api/fm/compliance/scorecard` — **free/core** (`ComplianceScorecard::build`, `docs/design/COMPLIANCE-SCORECARD-DESIGN.md`)
  - Read-only capability checklist across virus scan / C2PA / audit export / SSO / DLP / legal hold. Gated only by the `audit` permission, **not** by module install/license: every paid row simply reports `available: false` on an unlicensed server, so free-core operators still see the full checklist.

- `GET /api/fm/hold/status?disk=&path=` — **free/core**
  - Whether a path is currently on legal hold. Requires `read`; `reason`/`placed_by`/`placed_at` are included only with the `audit` permission.

- `POST /api/fm/hold {disk, path, reason}` · `POST /api/fm/hold/release {disk, hold_id, reason}` · `GET /api/fm/hold/list?disk=&include_released=` — `legal-hold` (`allow_legal_hold`; `docs/design/RETENTION-LEGAL-HOLD-DESIGN.md`)
  - Management half of Legal Hold, gated by the `audit` permission. **Enforcement is free/core and license-independent**: `FileManager::assertNoActiveHold()` blocks delete/trash/rename/move/cross-move/purge on a held path, and (since core-v0.2.94) every in-place mutation of an existing held file — overwrite upload, chunk/presign PUT/POST and tus onto an existing key, `/content` PUT, `/chmod`, watermark burn/remove, extract-overwrite, `/optimize`, metadata edits, module writes — and keeps blocking even if the module is later uninstalled or its license lapses.

- `POST /api/fm/retention/run {disk, dry_run?}` — `legal-hold` (`allow_legal_hold`), `audit` perm
  - Applies the token's `retention_rules` (`[{path, days, action: trash|delete}]`, ≤ 20 rules): files under each rule's path not modified for `days` days are trashed or deleted. `dry_run` defaults to **true** (report only). Every action goes through `trash()`/`delete()`, so `owner_only`, the `delete` perm and legal holds (**always win** — reported as `held`) apply. ≤ 500 files per call (`truncated: true` → run again). Returns `{dry_run, rules, matched, acted, held, errors, truncated, items[{path, action, age_days, result}]}`; each file acted on is audited as `retention_trash`/`retention_delete`. Nothing runs on its own — call it from the operator's scheduler.

- `POST /api/fm/fixity/check {disk}` — `audit-export` (`allow_audit_export`), `audit` perm (`FixityChecker.php`)
  - Integrity check of the token's root. The first pass stores a SHA-256 baseline in `<prefix>/_fluxfiles/fixity.json`; later passes report `changed_*`/`missing_*` files split into **explained** (a FluxFiles write/delete of that path is in the audit log since the last check) and **unexplained** (changed behind FluxFiles' back, or corrupted). Each unexplained item is audited as `fixity_mismatch`. Up to 1 GB hashed per call; `complete: false` means call again (a cursor resumes).

- `GET /api/fm/audit/verify?disk=` — `audit-export`, `audit` perm, **unscoped token only** (the log is per-disk)
  - Recomputes the `AuditChain` HMAC of every entry (live + archives) and walks the `prev` links: reports `{ok, configured, entries, chained, unchained, tampered[], gaps, forks, first_ts, last_ts, head}`. Entries are chained at write time by every backend (JSON `audit.jsonl`, core/Laravel/WordPress DB handlers via an `audit_chain_head` row lock); key = HKDF of `FLUXFILES_SECRET` (`AuditChain::configure()` in the Laravel/WP proxies). A chain can't prove the newest entries weren't cut off — anchor `head` externally.

- `GET /api/fm/audit/export?format=ndjson|csv&action=&from=&to=&path=&actor=&disk=` — `audit-export`, `audit` perm
  - Streams the **full, unpaginated** audit log as a file download (NDJSON default or CSV), same tenant scoping/filters as `/audit`, merging live `_fluxfiles/audit.jsonl` + rotated `_fluxfiles/audit/archive/*.jsonl` (capped at `MAX_EXPORT` rows). Bypasses the `{data,error}` envelope — it's a `Content-Disposition: attachment` response, downloaded via fetch+blob (never a raw `<a href>`, so the `Authorization` header can be sent and the JWT never rides the URL).

- `POST /api/fm/audit/purge {disk?, before?}` — `audit-export`, `audit` perm, **admin-only**
  - Deletes audit entries (live log + archives) older than `before` (unix ts; falls back to the token's `audit_retention_days`, else `400 audit_purge_no_cutoff`). Requires an **unscoped** token (`pathPrefix === ''`) — `audit.jsonl` is per-disk, not per-tenant. No UI surface.

Core-free primitives behind these live in `StorageMetadataHandler.php` (and the DB handlers): rotation **archives** the dropped tail to `_fluxfiles/audit/archive/audit-<ts>-<hex>.jsonl` before truncating the live file, under the same `acquireAuditLock()`; `readAuditArchive()` and `purgeAuditBefore()` are storage primitives with no Claims/license awareness — gating happens entirely at the route/module layer.

**What gets audited.** Every mutating route resolves to a named action (a CI guard fails on a new POST/PUT/DELETE route that resolves to `unknown`), plus `zip_download`, `presign_get`/`presign_put`, `access_denied` (every authenticated 403, on a disk the token can access) and the blocked-write events `virus_blocked`/`dlp_blocked`/`legal_hold_blocked`. Reads are audited only with the `audit_reads` claim (`content_read`, `stream_read`).

## Chunk Upload (S3 multipart)

Refused with `409 virus_unscannable` / `dlp_unscannable` while `allow_virus_scan` / `allow_dlp_scan` is on (browser→S3 bytes can never be scanned).

- `POST /api/fm/chunk/init` — JSON body: `disk`, `path`, `size`.
- `POST /api/fm/chunk/presign` — JSON body: `disk`, `key`, `upload_id`, `part_number`.
- `POST /api/fm/chunk/complete` — JSON body: `disk`, `key`, `upload_id`, `parts`. Validates the selected parts' sizes/ETags and enforces collision/owner/quota/count policy before completion (needs `s3:ListMultipartUploadParts`).
- `POST /api/fm/chunk/abort` — JSON body: `disk`, `key`, `upload_id`.

## Important Route Notes

- Per-tenant config is carried in **JWT claims**, not a `/config` route — the token *is* the config. **[`docs/reference/CONFIG.md`](../docs/reference/CONFIG.md) is the single source of truth for every claim + env var** (grouped: access, upload, images/WebP/AVIF, watermark, media, optimize, BYO-embed URLs, usage, import, paid-gates). Mint them in one options object: `fluxfiles_token(['user'=>…, 'claims'=>[…]])`, where `claims` is the escape hatch for any claim by its raw name (same passthrough in node/python/laravel/wordpress).
- **Keys are relative to the token `prefix`.** When a token is path-scoped (e.g. `prefix=users/42/`), every `key`/`file_key`/`dir_key`/`next_cursor`/`original_key` returned is **stripped of the prefix** (the prefix is the tenant's root, kept invisible) — e.g. on-disk `users/42/reports` is returned as `reports`. The API accepts both relative and absolute paths on input (scoping is idempotent). `url`/`permanent_url` keep the **real** (prefixed) storage path. With no prefix, keys are unchanged.
- `list`, `search`, and `search-folders` rows include a stable `created` (Unix seconds) alongside `modified`; folders carry `created` too (S3/R2 dir prefixes may lack `modified`). The UI sorts by `created || modified`.
- `show_hidden=false` (the default) hides dotfiles from `list()` **and** refuses them by name on `/content`, `/meta`, `/presign`, `/zip`, `/chmod` (judged relative to the tenant prefix).
- Mutating requests validate the `Origin` header against `FLUXFILES_ALLOWED_ORIGINS` when origins are configured.
- Route responses usually follow `{ "data": ..., "error": null }`; API exceptions include `error_code` when available. Exceptions: `/zip`, `/audit/export` (file downloads), `/stream`, `/img`, `/tus` (status + headers).
