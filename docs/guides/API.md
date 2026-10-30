# API Reference

Full HTTP route reference for the core standalone server. The Laravel and
WordPress proxy adapters expose every route below **except** `/tus` (resumable
upload — the UI falls back to a plain upload), `/metadata/export` + `/metadata/import`
(DB-backend tooling) and the SSO bridge (`/sso/*`); WordPress serves them under
`/wp-json/fluxfiles/v1/`. The [`.claude/api-map.md`](../../.claude/api-map.md)
file carries the same information at implementation depth for anyone working
on the core itself.

Base path: `/api/fm/`

All responses follow the format: `{ "data": { ... }, "error": null }`
On error: `{ "data": null, "error": "Error message" }` with appropriate HTTP status
(plus `error_code` when available). File downloads (`/zip`, `/audit/export`),
`/stream`, `/img` and `/tus` are the exceptions.

## Public Endpoints (no main JWT)

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/fm/lang` | List available locales → `[{code, name, dir}]` |
| `GET` | `/api/fm/lang/{code}` | Get translation messages for a locale |
| `GET` | `/api/fm/share/info?token=` · `POST /api/fm/share/unlock` · `GET /api/fm/share/file?token=` | Share recipient landing (`/public/share.html`), authenticated by the share link itself — `share` module installed + licensed |
| `GET` | `/api/fm/intake/info?token=` · `POST /api/fm/intake/upload` | Upload-portal recipient page (`/public/intake.html`) — `intake` module installed + licensed |

## File Operations (JWT required)

| Method | Path | Body / Params | Description |
|--------|------|---------------|-------------|
| `GET` | `/list?disk=&path=[&limit=&cursor=&sort=&order=&type=]` | — | List directory contents. With `limit`, returns `{items, next_cursor, total}` pages. `sort` = `name`/`date`/`size`/`type` (omit for key order), `order` = `asc`/`desc`, `type` = `image`/`video`/`audio`/`document`/`other` (files only; folders always listed). Sorting and filtering apply to the whole folder before paging; folders come first. |
| `POST` | `/upload` | `multipart: disk, path, file, force_upload?` | Upload file |
| `DELETE` | `/delete` | `{disk, path}` | **Permanently** delete a file or directory (recursive) |
| `POST` | `/trash` | `{disk, path}` | Soft-delete a **file or folder** to trash (move-based, restorable; folders move the whole subtree) |
| `POST` | `/trash/restore` | `{disk, trash_id, path?}` | Restore a trashed file/folder (→ 409 if the target is occupied) |
| `GET` | `/trash/list?disk=` | — | List trash entries (scoped to the token's prefix/owner) |
| `POST` | `/trash/purge` | `{disk, trash_id}` | Permanently delete one trash item |
| `POST` | `/trash/empty` | `{disk}` | Permanently delete all visible trash items (held items are skipped → `{purged, held}`) |
| `POST` | `/rename` | `{disk, path, name}` | Rename file or directory (a file's extension is fixed — base name only) |
| `POST` | `/move` | `{disk, from, to}` | Move within same disk (file extension must not change; `allowedExt` enforced) |
| `POST` | `/copy` | `{disk, from, to}` | Copy a file within same disk (file extension must not change; `allowedExt` enforced; folders → `400 copy_dir_unsupported`) |
| `POST` | `/mkdir` | `{disk, path}` | Create directory |
| `POST` | `/cross-copy` | `{src_disk, src_path, dst_disk, dst_path}` | Copy between disks (file extension must not change; `allowedExt` enforced) |
| `POST` | `/cross-move` | `{src_disk, src_path, dst_disk, dst_path}` | Move between disks (file extension must not change; `allowedExt` enforced) |
| `POST` | `/presign` | `{disk, path, method, ttl, size?}` | Generate a presigned URL. **GET** (max 86400s) → `{url, expires_at}`. **PUT** (max 900s) → `{url, expires_at}`; `size` is required but **advisory only** — S3 cannot sign `ContentLength`, so the size/quota checks run at mint time and are not enforced on the PUT body (operators can turn PUT off with `allow_presign_put: false`). **POST** (max 900s) → `{url, fields, expires_at, max_bytes}` — a browser form upload whose policy the **bucket enforces**: body ≤ the declared `size` (`content-length-range`), exact key, and `Content-Type` pinned to the extension's type. Submit `multipart/form-data` to `url` with every `fields` entry first and the file last (field `file`). Not supported by Cloudflare R2 → `400 presign_post_unsupported` (use chunked upload). PUT and POST are refused (`409 virus_unscannable` / `dlp_unscannable`) while scanning is on. |
| `POST` | `/crop` | `{disk, path, x, y, width, height, save_path?}` | Crop image |
| `POST` | `/ai-tag` | `{disk, path}` | AI-analyze image (requires AI config) |
| `POST` | `/import-url` | `{disk, path, url, filename?}` | Server-side fetch a URL into storage (opt-in via `allow_url_import`; SSRF-guarded) |
| `POST` | `/optimize` | `{disk, path\|paths[], overwrite?}` | Recompress an image to WebP or a PDF via Ghostscript (≥ 10.05.0; else `501 pdf_unavailable`), at rest (**free/core**). Opt-in via `allow_optimize` (needs `write` + `delete`) |

## Archives, editor & permissions

| Method | Path | Body / Params | Description |
|--------|------|---------------|-------------|
| `POST` | `/zip` | `{disk, paths[], name?}` | Stream a **zip** of the selected files/folders (read + `allow_download` + `allow_zip`; pre-flight size caps). Proxied by the Laravel and WordPress adapters too |
| `POST` | `/extract` | `{disk, path, dest?}` | **Extract** a zip in place — zip-slip / zip-bomb / quota / dangerous-ext guarded, all checked before the first write (`allow_extract`). Not transactional: a per-entry scan or storage failure can leave earlier entries written |
| `GET` | `/content?disk=&path=` | — | Read a text file's content (read perm; binary → 415, > 5 MB → 413) |
| `PUT` | `/content` | `{disk, path, content}` | Overwrite a text/config file (`allow_code_edit`, default **false**; existing files only) |
| `GET` | `/chmod?disk=&path=` | — | Read an SFTP file's octal mode (SFTP disks only) |
| `POST` | `/chmod` | `{disk, path, mode}` | Set an SFTP file's mode (write + `allow_chmod`) |
| `POST` | `/watermark` | `{disk, path, type, text?, logo_data?, x, y, scale, opacity, color?, dest?}` | Burn-in watermark editor (logo/text), permanent (**free/core**). Non-destructive: snapshots the original on first burn so it can be undone |
| `POST` | `/watermark/remove` | `{disk, path}` | Restore the pre-watermark original (404 if none) |

> **Tokened media endpoints** (query-string token, no `Authorization` header — for `<img>`/`<video>`): `GET /img?token=&width=&quality=&format=` (on-demand WebP/AVIF, see [FEATURES.md](FEATURES.md#on-demand-webp--avif)) and `GET /stream?token=` (gated private media, SFTP serving). Proxied by core-standalone, Laravel, and WordPress alike.

## Metadata

| Method | Path | Body / Params | Description |
|--------|------|---------------|-------------|
| `GET` | `/meta?disk=&path=` | — | File info: size, mime, modified |
| `GET` | `/metadata?disk=&key=` | — | SEO metadata: title, alt_text, caption, tags |
| `PUT` | `/metadata` | `{disk, key, title, alt_text, caption, tags}` | Save metadata |
| `DELETE` | `/metadata` | `{disk, key}` | Delete metadata |
| `GET` | `/metadata/export?disk=&prefix=&format=ndjson\|csv` | — | Bulk export of DB-backend metadata rows (**free/core**; requires `FLUXFILES_STORAGE_BACKEND=db`, else `501`). Tenant-scoped to the caller's own prefix/owner (read perm). Core-standalone (unproxied) |
| `POST` | `/metadata/import` | `{disk, entries[]}` | Bulk import of DB-backend metadata rows (**free/core**, DB backend only; `501` otherwise). Every entry's path must be in the caller's scope or the whole batch is rejected (write perm). Core-standalone (unproxied) |

## Search, Quota, Audit

| Method | Path | Params | Description |
|--------|------|--------|-------------|
| `GET` | `/search?disk=&q=&limit=` | `limit` default 50 | Full-text search across file names + metadata |
| `GET` | `/search-folders?disk=&q=&limit=` | `limit` default 50 | Search folder names via the directory index |
| `GET` | `/quota?disk=` | — | Storage usage: used_mb, max_mb, percentage |
| `GET` | `/usage?disk=&refresh=` | — | **Usage dashboard** — quota + per-type and per-folder breakdown (file-cached; `refresh=true` recomputes, tight bucket) |
| `GET` | `/duplicates?disk=&limit=` | — | **Duplicate finder** — byte-identical files under the token's root, grouped `{hash, size, count, wasted, files[{key, modified}]}` (oldest first) + `total_wasted`, `scanned_files`, `truncated`. Size pre-filter, then a streamed SHA-256 of only same-size candidates, capped at 512 MB read per call (`truncated: true` past that). Hidden/reserved paths follow `list()`. `read` perm; shares the 2/min usage-refresh bucket. |
| `GET` | `/audit?limit=&offset=&action=&from=&to=&path=&actor=` | `limit` default 100 | Activity log, **scoped to the token's prefix**. Requires the `audit` permission (403 otherwise). |
| `GET` | `/disk/doctor?disk=&origin=` | — | **Bucket Doctor** — diagnose an S3/R2 disk (credentials, read/write/delete, presign, CORS, multipart, versioning) and return a report + IAM/CORS remediation. Requires `write`. |
| `GET` | `/license` | — | Server's commercial edition/status: `{edition, status, modules, limits, expires, days_left}`. Free MIT core → `{edition:'free'}` |
| `GET` | `/compliance/scorecard` | — | **Compliance Readiness Scorecard** (**free/core**) — read-only checklist across virus scan / C2PA / audit export / SSO / DLP / legal hold; every paid row reports `available: false` on an unlicensed server instead of erroring. Requires the `audit` permission |
| `GET` | `/hold/status?disk=&path=` | — | **Legal hold status** (**free/core**) — whether a path is currently on hold. Requires `read`; `reason`/`placed_by`/`placed_at` are only included with the `audit` permission |

## Paid Modules

Gated by a 3-layer check (module installed + licensed + a per-token `allow_*` claim) — absent/unlicensed/not-allowed answers `501`/`402`/`403` respectively. All of these are proxied by the Laravel/WordPress adapters too (see [`CONFIG.md`](../reference/CONFIG.md) for each claim's minimum core version). See [`.claude/api-map.md`](../../.claude/api-map.md) for full behavior.

| Method | Path | Body / Params | Module | Description |
|--------|------|---------------|--------|-------------|
| `POST` | `/share` | `{disk, path, ttl?, label?, password?, max_downloads?}` | `share` | Create a public share link. Token is returned once, never stored |
| `GET` | `/share/list?disk=` | — | `share` | List the caller's own share records |
| `POST` | `/share/revoke` | `{disk, jti}` | `share` | Revoke a share |
| `GET` | `/share/analytics?disk=&jti=&limit=&offset=&event=` | — | `share` | Per-event share log (view/download/unlock_fail; IPs truncated to /24 or /48), opt-in via `share_analytics` |
| `POST` | `/intake` | `{disk, path, ttl?, label?, password?, max_files?, max_mb?, allowed_ext?}` | `intake` | Create a write-only upload portal for a folder |
| `GET` | `/intake/list?disk=` | — | `intake` | List the caller's own intake portals (`has_webhook`, never the signing secret) |
| `POST` | `/intake/revoke` | `{disk, jti}` | `intake` | Revoke an upload portal |
| `GET` | `/intake/analytics?disk=&jti=&limit=&offset=&event=` | — | `intake` | Per-event portal log (received/rejected), opt-in via `intake_analytics` |
| `GET` | `/versions?disk=&path=` | — | `versioning` | List prior versions of a file |
| `POST` | `/versions/restore` | `{disk, path, version_id}` | `versioning` | Restore a prior version (snapshots current bytes first) |
| `POST` | `/webhooks/test` | — | `webhooks` | Send a one-off ping to the token's configured `webhook_url` |
| `POST` | `/ai-vision` | `{disk, path, op, dest?}` | `ai` | BYO-key image ops: `bg_remove` / `upscale` / `smart_crop` |
| `POST` | `/ocr` | `{disk, path, lang?}` | `ocr` | Extract text from an image via `tesseract`. Returns `{path, chars, text}` |
| `POST` | `/virus/scan` | `{disk, path}` | `virus` | On-demand scan of an **already stored** file (e.g. uploaded before scanning was enabled, or via a direct-to-bucket path no scan covers). Read-only — reports, never deletes. Returns `{path, clean, threat, scanned_at}`; a malformed engine verdict counts as not clean. Requires `read` + `allow_virus_scan`; files over 512 MB → `413`. Audited as `virus_scan` with the verdict as detail. Uploads themselves carry `virus_scanned: true` when the (fail-closed) upload scan ran. |
| `POST` | `/backup` | `{from_disk, to_disk, path?, overwrite?}` | `backup` | One-way subtree sync between two disks (capped 5000 files/run) |
| `POST` | `/c2pa` | `{disk, path}` | `c2pa` | Verify Content Credentials (C2PA) via `c2patool` |
| `POST` | `/c2pa/sign` | `{disk, path, dest?}` | `c2pa` | Sign a file with Content Credentials |
| `GET` | `/audit/export?format=ndjson\|csv&action=&from=&to=&path=&actor=&disk=` | — | `audit-export` | Stream the full (unpaginated) audit log as a file download — NDJSON (default) or CSV, tenant-scoped like `/audit`. Requires the `audit` permission + `allow_audit_export` |
| `GET` | `/audit/verify?disk=` | — | `audit-export` | **Tamper check** of the disk's audit log (live + archives): every entry is HMAC-chained to the previous one (key derived from `FLUXFILES_SECRET`), so an edited, inserted, reordered or middle-deleted entry is reported. Returns `{ok, configured, entries, chained, unchained, tampered[], gaps, forks, first_ts, last_ts, head}`. Legacy pre-chain entries count as `unchained` without failing; a purge of the oldest entries is not a gap. A chain can't prove the newest entries weren't cut off — copy `head` somewhere external (webhook/SIEM) to anchor it. Requires the `audit` permission, an **unscoped** token (the log is per-disk) and `allow_audit_export`. |
| `POST` | `/retention/run` | `{disk, dry_run?}` | `legal-hold` | Apply the token's `retention_rules`: files under each rule's path not modified for `days` days are moved to trash or deleted. `dry_run` defaults to **true** (report only). Returns `{dry_run, rules, matched, acted, held, errors, truncated, items[{path, action, age_days, result}]}`; ≤ 500 files per call. **Legal holds always win** (`result: held`). Every file acted on is audited as `retention_trash` / `retention_delete`. Requires `audit` + `delete`. Nothing runs on its own — call it from your scheduler. |
| `POST` | `/fixity/check` | `{disk}` | `audit-export` | **Integrity (fixity) check** of the token's root. The first pass stores a SHA-256 baseline in `<prefix>/_fluxfiles/fixity.json`; later passes report files `changed_*` / `missing_*` since the baseline, split into **explained** (a FluxFiles write/delete of that path is in the audit log since the last check) and **unexplained** (edited/removed directly on the storage, or corrupted). Each unexplained item is also audited as `fixity_mismatch`. Up to 1 GB is hashed per call; `complete: false` means run again to continue (a cursor resumes). Requires the `audit` permission + `allow_audit_export`. |
| `POST` | `/audit/purge` | `{disk?, before?}` | `audit-export` | Delete audit entries (live log + rotated archives) older than `before` (unix ts; falls back to the token's `audit_retention_days`, else `400 audit_purge_no_cutoff`). **Admin-only**: requires an unscoped token (empty `pathPrefix`) — `audit.jsonl` is per-disk, not per-tenant |
| `POST` | `/hold` | `{disk, path, reason}` | `legal-hold` | Place a legal hold on a file/folder, blocking delete/trash/rename/move and in-place modification of it (enforcement itself is free/core and stays active even if the module is uninstalled/unlicensed). Requires the `audit` permission |
| `POST` | `/hold/release` | `{disk, hold_id, reason}` | `legal-hold` | Release a hold. Requires the `audit` permission |
| `GET` | `/hold/list?disk=&include_released=` | — | `legal-hold` | List holds on the token's scope. Requires the `audit` permission |

> Besides the on-demand `/virus/scan` above, virus scanning (`virus` module) runs on the write path: when `allow_virus_scan` is on, `/upload`, `/import-url`, `/tus`, `/content` (PUT), and `/extract` scan bytes before they're written (fail-closed). DLP/PII scanning (`dlp` module) works the same way via `allow_dlp_scan` and has no endpoint of its own. `/chunk/*` and presigned PUT/POST are refused outright while either claim is on.

### SSO Bridge (paid module `sso`, pre-auth)

Not gated by a per-token claim (there's no token yet) — a server kill-switch (`FLUXFILES_SSO_ENABLED=true`) plus the module installed + licensed. Exists to put a login screen in front of the **standalone `/public` UI** when there's no host app minting tokens via `fluxfiles_token()`; not proxied by the adapters. OIDC only (no SAML), with PKCE (S256). See the public [SSO Bridge setup](ACTIVATE.md#sso-bridge-setup-standalone-ui) for the flow and configuration.

| Method | Path | Params / Body | Description |
|--------|------|----------------|-------------|
| `GET` | `/sso/login?redirect=` | — | Signs a short-lived state token, 302s to the IdP's `authorization_endpoint`. `redirect` must be a same-origin relative path. |
| `GET` | `/sso/callback?code=&state=` | — | Verifies `state`, exchanges `code` at the IdP's token endpoint, verifies the `id_token` via JWKS, resolves the identity's groups to a claims set (`FLUXFILES_SSO_CLAIMS_MAP`, first match in the map's own order), takes the user id from `FLUXFILES_SSO_USER_CLAIM` (default `sub`), mints the real access JWT, and 302s to `/public/index.html#boot=<one-time token>` — a URL **fragment**, never a query string, carrying the JWT sealed with AES-256-GCM. |
| `POST` | `/sso/exchange` | `{token}` | Trades the one-time boot token (from the `#boot=` fragment) for the real access JWT: `{data: {token, expires_at}, error: null}`. This is the only way the real JWT is ever transmitted. |

## Resumable upload (tus 1.0 — local / SFTP / any disk)

The [tus 1.0.0](https://tus.io/protocols/resumable-upload) core protocol with the `creation` and `termination` extensions — any tus client works (e.g. `tus-js-client`); the built-in UI uses it for files > 8 MB on non-S3 disks. Every request carries `Authorization: Bearer <jwt>` and `Tus-Resumable: 1.0.0`.

| Method | Path | Headers | Description |
|--------|------|---------|-------------|
| `POST` | `/tus` | `Upload-Length`, `Upload-Metadata: filename <b64>,path <b64>,disk <b64>` | Create → `201` + `Location: /api/fm/tus/<id>`. The upload rules (write perm, disk, extension/dangerous name, `max_upload`, path scope, quota, file count, name collision, `owner_only`, legal hold) are checked **here**, before any byte is staged. `Upload-Defer-Length` is not supported. |
| `HEAD` | `/tus/<id>` | — | `Upload-Offset` / `Upload-Length` — where to resume. |
| `PATCH` | `/tus/<id>` | `Upload-Offset`, `Content-Type: application/offset+octet-stream` | Append. Wrong offset → `409` (with the real one); a concurrent PATCH → `423`; bytes beyond `Upload-Length` → `413`. The final chunk runs the file through the **normal upload pipeline** (quota, virus/DLP scan, `strip_exif`, variants, ownership) and returns the result base64-JSON in `X-FluxFiles-Result`. |
| `DELETE` | `/tus/<id>` | — | Abandon. |

Uploads belong to the token that created them — same user id, tenant prefix and disk (others get `404`); unfinished ones expire after 24 h (max 20 per user). Partial bytes are staged in the server's storage dir (`<FLUXFILES_STORAGE_PATH>/tus/`) — on a multi-node deployment share that directory or use sticky sessions. PATCH/HEAD use their own rate bucket (`FLUXFILES_TUS_RATE_LIMIT`, 600/min). Core standalone only — the Laravel/WordPress proxies don't route `/tus` (the UI falls back to a plain upload there).

## Chunk Upload (S3 multipart, files > 10MB)

Refused with `409 virus_unscannable` / `dlp_unscannable` while `allow_virus_scan` / `allow_dlp_scan` is on — the parts go browser→S3 and can't be scanned.

| Method | Path | Body | Description |
|--------|------|------|-------------|
| `POST` | `/chunk/init` | `{disk, path, size}` | Initiate → `{upload_id, key, chunk_size}` |
| `POST` | `/chunk/presign` | `{disk, key, upload_id, part_number}` | Presign URL for part |
| `POST` | `/chunk/complete` | `{disk, key, upload_id, parts}` | Complete upload (part sizes/ETags and collision/owner/quota/count policy validated first) |
| `POST` | `/chunk/abort` | `{disk, key, upload_id}` | Abort upload |

## Upload Response Example

```json
{
    "data": {
        "key": "users/42/photo.jpg",
        "url": "https://bucket.r2.cloudflarestorage.com/photo.jpg",
        "name": "photo.jpg",
        "size": 245760,
        "variants": {
            "thumb":  { "url": "...", "key": "..._thumb.webp",  "width": 150, "height": 100 },
            "medium": { "url": "...", "key": "..._medium.webp", "width": 768, "height": 512 },
            "large":  { "url": "...", "key": "..._large.webp",  "width": 1920, "height": 1280 }
        },
        "ai_tags": {
            "tags": ["landscape", "mountain", "sunset"],
            "title": "Mountain sunset landscape",
            "alt_text": "A mountain range silhouetted against an orange sunset sky",
            "caption": "Beautiful sunset over mountain peaks with warm orange and purple tones."
        }
    },
    "error": null
}
```

Depending on the token, the result may also carry `exif_stripped: true` (`strip_exif`),
`virus_scanned: true` (`allow_virus_scan`) and a `poster` variant (`media_posters`).

## Duplicate Detection

With the `dedupe_uploads` claim on (default off — a byte-identical upload is then
simply kept as a copy), a file whose SHA-256 hash already exists returns:

```json
{
    "data": {
        "key": "existing/path/photo.jpg",
        "url": "...",
        "duplicate": true,
        "message": "File already exists. Use force_upload to override."
    }
}
```

Send `force_upload=true` (in form data) to upload anyway. To find duplicates that
already exist, use `GET /duplicates` above.
