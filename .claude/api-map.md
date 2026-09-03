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

- `GET /api/fm/sso/login?redirect=` · `GET /api/fm/sso/callback?code=&state=` · `POST /api/fm/sso/exchange {token}`: the **SSO Bridge** (paid module `sso`), a login screen for the **standalone `/public` UI** when no host app mints tokens via `fluxfiles_token()`. OIDC only in v1. Registered pre-auth like the share landing, but gated differently — there's no `Claims` yet, so `ModuleRegistry::requireServer($id, $license)` (installed + licensed only, layers 1+2) is used instead of `require()`, and layer 3 is an explicit `FLUXFILES_SSO_ENABLED === 'true'` env check in each route (`403 sso_disabled` otherwise) rather than a per-token claim (`SsoModule::claim()` returns `''`). `login` validates `redirect` is a same-origin relative path (open-redirect guard), signs an `SsoStateToken` (`t=sso_state`, 600s, carries the OIDC `nonce`), 302s to the IdP's `authorization_endpoint`. `callback` verifies `state`, exchanges `code` at the `token_endpoint`, verifies the `id_token` via JWKS (`iss`/`aud`/`nonce` checked manually), resolves the identity's groups to a claims set (`FLUXFILES_SSO_CLAIMS_MAP`, dot-path `FLUXFILES_SSO_GROUPS_CLAIM`, first match wins, fail-closed `403 sso_no_mapping`), mints the real access JWT via `fluxfiles_token()`, wraps it in an `SsoBootToken` (`t=sso_boot`, 60s, carries the signed JWT), and 302s to `/public/index.html#boot=<boot-token>` — a URL **fragment**, never a query string, so the real JWT is never written to server access/Referer logs. `exchange` verifies the boot token and returns `{data: {token, expires_at}, error: null}` — the only place the real JWT is ever transmitted, and only over a `POST` body. `window.__FM_SSO__ = {enabled, loginUrl}` is injected into `/public/index.html` only when SSO is enabled + installed, so the UI's "Sign in with SSO" button is absent by default. Issuer/JWKS/token-endpoint URLs are operator `.env` config, not user input — no `SsrfGuard` needed (same posture as `FLUXFILES_AIVISION_ENDPOINT`).

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

- `GET /api/fm/metadata/export?disk=&prefix=&format=ndjson|csv` / `POST /api/fm/metadata/import {disk, entries[]}`
  - Bulk backup/restore of `file_metadata` rows for the DB storage backend (`FLUXFILES_STORAGE_BACKEND=db`, `docs/DB-STORAGE-MIGRATION-DESIGN.md` §7). **Free/core, not a paid module** — unlike the neighboring `audit/export`/`audit/purge` entries below. `501` when the backend isn't `db`. Per-tenant, not admin-only: export respects the caller's own `pathPrefix`/`owner_only` scope (read perm; a scoped token cannot widen itself via `?prefix=`); import validates every entry's `path` against `Claims::isPathInScope()` **before writing any row** — one out-of-scope row rejects the whole batch (write perm, `422 metadata_import_rejected` with a per-row `error_params.errors` list). CLI mirrors: `scripts/export-metadata.php` / `scripts/import-metadata.php` (direct DB access, no JWT scope). Core-standalone only — not ported into the Laravel/WordPress proxy controllers (DB backend is a core-only storage mode).
- `GET /api/fm/chmod?disk=&path=` / `POST /api/fm/chmod {disk, path, mode}`
  - SFTP file permissions (cPanel-style). GET reads the 3-digit octal mode (read perm); POST sets it (write perm + `allow_chmod` claim, default true; octal `0?[0-7]{3}`, not recursive). SFTP-only (non-SFTP disk → 400). Core-standalone (the Laravel/WP proxies don't expose SFTP as a disk driver at all, unrelated to why `/stream`/`/img` were once unproxied).
- `POST /api/fm/zip {disk, paths[], name?}`
  - Streams a `.zip` of the selected files **and folders** (recursive) — constant-memory via ZipStream (each entry piped through Flysystem readStream; local/S3/R2/SFTP). Read perm + `allow_zip` + `allow_download`; folders expanded with name preserved, `owner_only` enforced (assertOwnsTree/assertOwner), `_fluxfiles`/`_variants`/`.meta.json` skipped, archive-name de-dupe. Pre-flight sums `fileSize` → `413` if over `zip_max_mb`/`zip_max_files` before any byte. Bypasses the JSON encoder (ZipStream sends its own headers, route `exit`s); a guard/size throw happens first → JSON error. Binary streaming, but unlike `stream`/`img` this one has no proxy port yet → **core-standalone** only.
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

- `GET /api/fm/versions?disk=local&path=` · `POST /api/fm/versions/restore {disk, path, version_id}` — **paid** (`versioning`, 3-layer gate incl. `allow_versioning`)
  - Prior-version list/restore for a file. Storage-resident, no DB: `FileManager` calls `VersioningModule::keep()` before an overwrite (wired only when installed + licensed + `allow_versioning`), snapshotting bytes to `_fluxfiles/versions/<hash>/` + a `manifest.json`; retention (`versioning_max`, default 10, hard cap 100) prunes oldest. `list` needs `read`; `restore` needs `write` + `assertCanModifyScopedPath`, and itself snapshots the current bytes first (a restore is reversible). Core-standalone — not proxied by the adapters.

- `POST /api/fm/webhooks/test` — **paid** (`webhooks`, 3-layer gate incl. `allow_webhooks`)
  - Sends a one-off ping to the token's configured `webhook_url` (HMAC-signed with `webhook_secret` or the server secret) and reports `{sent, url, status, error}` — unlike the silent best-effort `dispatch()` used for real events, `test` throws `400 webhook_not_configured` / `403 webhook_blocked` (SSRF-checked URL) so an operator gets a clear signal while wiring up an endpoint. Core-standalone.

- `POST /api/fm/ai-vision {disk, path, op, dest?}` — **paid** (`ai`, 3-layer gate incl. `allow_ai_vision`)
  - BYO-key image ops: `op` is one of `bg_remove`/`upscale`/`smart_crop`, dispatched to an operator-configured provider (remove.bg/Clipdrop/Replicate/OpenAI-compatible vision endpoint) via `FLUXFILES_AIVISION_PROVIDER`/`FLUXFILES_AIVISION_KEY`/`FLUXFILES_AIVISION_ENDPOINT` env vars — absent → `501 aivision_unconfigured`. Needs `write`; writes the result to `dest` (default `<name>-<op-suffix>.<ext>`). Core-standalone.

- `POST /api/fm/ocr {disk, path, lang?}` — **paid** (`ocr`, 3-layer gate incl. `allow_ocr`)
  - Extracts text from an image via the `tesseract` binary (checked at `/usr/bin`, `/usr/local/bin`, `/opt/homebrew/bin`, then `PATH`) — absent → `501 ocr_unavailable`. Needs `write`. Returns `{path, chars, text}`; the caller is expected to persist it into metadata/search if wanted. Core-standalone.

- `POST /api/fm/backup {from_disk, to_disk, path?, overwrite?}` — **paid** (`backup`, 3-layer gate incl. `allow_backup`)
  - One-way subtree sync between two disks the token can access (e.g. local/SFTP → S3/R2), reusing cross-disk copy semantics. Stateless/on-demand — no resident queue, meant to be driven by an external cron. Needs `read`+`write` on both disks; skips `_fluxfiles/`/`_variants/`, skips existing destination files unless `overwrite`, capped at 5000 files/run (`413 too_many` past that). Returns `{from, to, copied, skipped, errors, bytes}`. Core-standalone.

- `POST /api/fm/c2pa {disk, path}` · `POST /api/fm/c2pa/sign {disk, path, dest?}` — **paid** (`c2pa`, 3-layer gate incl. `allow_c2pa`)
  - Content-provenance (Content Credentials) via the `c2patool` binary — absent → `501 c2pa_unavailable`. `verify` needs `read`, returns the parsed manifest summary (or `{signed:false}`). `sign` needs `write` + a configured signing manifest (`FLUXFILES_C2PA_MANIFEST`, a JSON file with the cert/key — never in a token/request; absent → `501 c2pa_unconfigured`), stages the file through `c2patool` via a fixed arg array (no shell) and writes to `dest` (default `<name>-signed.<ext>`). Core-standalone.

- `POST /api/fm/watermark {disk, path, type, text?, logo_data?, x, y, scale, opacity, color?, dest?}` — **FREE / core**
  - Burn-in watermark editor (drag-and-drop logo/text), permanent into the file bytes. Needs `write`. **Non-destructive:** snapshots the true original to `_fluxfiles/originals/` on the first burn, so re-editing reads from the clean original (no stacking) and `dest` saves a copy (extension immutable). Rejects burn-in on a preview/overlay-watermark token (`409 watermark_overlay_active`). Distinct from the serve-time **overlay** (`watermark_*` claims on `/img`, preview-only). Proxied by Laravel + WordPress.
- `POST /api/fm/watermark/remove {disk, path}` — restores the pre-watermark original from the backup (`404 no_watermark_backup` if none), clears `meta.watermarked`. Proxied by Laravel + WordPress.

- `POST /api/fm/terminal {disk, cmd, cwd?, confirm?}` — **FREE / core**, SFTP disks only
  - Stateless SSH **command-runner** (one `exec` per request over phpseclib; cwd threaded back). Hard-gated: server kill-switch `FLUXFILES_TERMINAL_DISABLED`, the `allow_terminal` claim (default off), `write` perm, an SFTP disk that grants a shell, a dangerous-command double-confirm (`409 terminal_confirm_required`). Proxied by the Laravel/WordPress adapters too (requires core ≥ 0.2.46) — the gate forwards unconditionally in both. **Free PTY upgrade:** the `terminal_pty_url` claim makes the UI embed a self-hosted ttyd/gotty/wetty instead — no server-side endpoint change.

- `POST /api/fm/git-deploy {disk}` — **FREE / core**, SFTP disks only, `GitDeploy.php`
  - One-click Git deploy — a fixed-command-shape **subset** of the terminal above, not a second arbitrary-shell door. Repo path/branch/hooks-enabled are **operator claims** (`git_deploy_path`/`git_deploy_branch`/`git_deploy_hooks`) baked into the JWT at mint time, never read from the request body — the body carries only the (already-scoped) `disk` selector. Assembles `git fetch --prune && reset --hard origin/<branch>` (branch claim set) or `git pull --ff-only` (branch claim empty) from `escapeshellarg()`'d claim values only. Hooks neutered by default (`core.hooksPath=/dev/null`), opt-in via `git_deploy_hooks`. Concurrency serialized by an `mkdir`-based lock directory inside the repo on the SFTP disk itself (`409 git_deploy_in_progress`; a lock older than 5 min is reclaimed as abandoned). Hard-gated: server kill-switch `FLUXFILES_GIT_DEPLOY_DISABLED`, the dedicated `allow_git_deploy` claim (default off, never bundled with `allow_sftp`/`allow_terminal`), `write` perm, its own tighter rate bucket (`FLUXFILES_GIT_DEPLOY_RATE_LIMIT`, default 5/min), a longer deploy-specific timeout (`FLUXFILES_GIT_DEPLOY_TIMEOUT`, default 120s) and output cap (2 MB) than the terminal's. FluxFiles never touches git remote credentials — the sync always uses the repo's own pre-configured `origin` already set up on the VPS. See `docs/GIT-DEPLOY-SECURITY-REVIEW.md` for the threat model. Non-atomic deploy onto a live webroot is a documented, not solved, trade-off (§4.8 of the review). Not yet proxied by Laravel/WordPress.

- `GET /api/fm/audit?limit=100&offset=0`
  - Lists current user's audit entries.

- `GET /api/fm/audit/export?format=ndjson|csv&action=&from=&to=&path=&actor=&disk=` — paid module `audit-export`, requires the `audit` permission
  - Streams the **full, unpaginated** audit log as a file download (NDJSON default or CSV), same tenant scoping/filters as `/audit`, merging live `_fluxfiles/audit.jsonl` + rotated `_fluxfiles/audit/archive/*.jsonl` (capped at `MAX_EXPORT` rows). Bypasses the `{data,error}` envelope — it's a `Content-Disposition: attachment` response, downloaded via fetch+blob (never a raw `<a href>`, so the `Authorization` header can be sent and the JWT never rides the URL). Core-standalone (not proxied by Laravel/WordPress).

- `POST /api/fm/audit/purge {disk?, before?}` — paid module `audit-export`, **admin-only**
  - Deletes audit entries (live log + archives) older than `before` (unix ts; falls back to the token's `audit_retention_days`, else `400 audit_purge_no_cutoff`). Requires an **unscoped** token (`pathPrefix === ''`) — `audit.jsonl` is per-disk, not per-tenant, so a scoped token could otherwise purge another tenant's entries on a shared disk. No UI surface (API-only, like `allow_backup`).

Core-free primitives backing both routes live in `StorageMetadataHandler.php`: rotation now **archives** the dropped tail to `_fluxfiles/audit/archive/audit-<ts>-<hex>.jsonl` before truncating the live file (previously silently discarded), under the same `acquireAuditLock()`; `readAuditArchive()` and `purgeAuditBefore()` are storage primitives with no Claims/license awareness — gating happens entirely at the route/module layer.

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

- Per-tenant config is carried in **JWT claims**, not a `/config` route — the token *is* the config. **[`docs/CONFIG.md`](../docs/CONFIG.md) is the single source of truth for all 85 claims + env vars** (grouped: access, upload, images/WebP/AVIF, watermark, media, optimize, terminal, git-deploy, usage, import, paid-gates). Mint them in one options object: `fluxfiles_token(['user'=>…, 'claims'=>[…]])`, where `claims` is the escape hatch for any claim by its raw name (same passthrough in node/laravel/wordpress).
- **Keys are relative to the token `prefix`.** When a token is path-scoped (e.g. `prefix=users/42/`), every `key`/`file_key`/`dir_key`/`next_cursor`/`original_key` returned is **stripped of the prefix** (the prefix is the tenant's root, kept invisible) — e.g. on-disk `users/42/reports` is returned as `reports`. The API accepts both relative and absolute paths on input (scoping is idempotent). `url`/`permanent_url` keep the **real** (prefixed) storage path. With no prefix, keys are unchanged.
- `list`, `search`, and `search-folders` rows include a stable `created` (Unix seconds) alongside `modified`; folders carry `created` too (S3/R2 dir prefixes may lack `modified`). The UI sorts by `created || modified`.
- Mutating requests validate the `Origin` header against `FLUXFILES_ALLOWED_ORIGINS` when origins are configured.
- Route responses usually follow `{ "data": ..., "error": null }`; API exceptions include `error_code` when available.
