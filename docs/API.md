# API Reference

Full HTTP route reference for the core standalone server. For adapter-specific
route proxying (which of these each framework wrapper exposes), see the
relevant package README; the [`.claude/api-map.md`](../.claude/api-map.md)
file carries the same information at implementation depth for anyone working
on the core itself.

Base path: `/api/fm/`

All responses follow the format: `{ "data": { ... }, "error": null }`
On error: `{ "data": null, "error": "Error message" }` with appropriate HTTP status.

## Public Endpoints (no auth)

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/fm/lang` | List available locales → `[{code, name, dir}]` |
| `GET` | `/api/fm/lang/{code}` | Get translation messages for a locale |

## File Operations (JWT required)

| Method | Path | Body / Params | Description |
|--------|------|---------------|-------------|
| `GET` | `/list?disk=&path=` | — | List directory contents |
| `POST` | `/upload` | `multipart: disk, path, file, force_upload?` | Upload file |
| `DELETE` | `/delete` | `{disk, path}` | **Permanently** delete a file or directory (recursive) |
| `POST` | `/trash` | `{disk, path}` | Soft-delete a **file or folder** to trash (move-based, restorable; folders move the whole subtree) |
| `POST` | `/trash/restore` | `{disk, trash_id, path?}` | Restore a trashed file/folder (→ 409 if the target is occupied) |
| `GET` | `/trash/list?disk=` | — | List trash entries (scoped to the token's prefix/owner) |
| `POST` | `/trash/purge` | `{disk, trash_id}` | Permanently delete one trash item |
| `POST` | `/trash/empty` | `{disk}` | Permanently delete all visible trash items |
| `POST` | `/rename` | `{disk, path, name}` | Rename file or directory (a file's extension is fixed — base name only) |
| `POST` | `/move` | `{disk, from, to}` | Move within same disk (file extension must not change; `allowedExt` enforced) |
| `POST` | `/copy` | `{disk, from, to}` | Copy within same disk (file extension must not change; `allowedExt` enforced) |
| `POST` | `/mkdir` | `{disk, path}` | Create directory |
| `POST` | `/cross-copy` | `{src_disk, src_path, dst_disk, dst_path}` | Copy between disks (file extension must not change; `allowedExt` enforced) |
| `POST` | `/cross-move` | `{src_disk, src_path, dst_disk, dst_path}` | Move between disks (file extension must not change; `allowedExt` enforced) |
| `POST` | `/presign` | `{disk, path, method, ttl, size?}` | Generate presigned URL (GET or PUT, max 86400s). `size` is required for PUT. |
| `POST` | `/crop` | `{disk, path, x, y, width, height, save_path?}` | Crop image |
| `POST` | `/ai-tag` | `{disk, path}` | AI-analyze image (requires AI config) |
| `POST` | `/import-url` | `{disk, path, url, filename?}` | Server-side fetch a URL into storage (opt-in via `allow_url_import`; SSRF-guarded) |
| `POST` | `/optimize` | `{disk, path\|paths[], overwrite?}` | Recompress an image to WebP or a PDF via Ghostscript, at rest (**free/core**). Opt-in via `allow_optimize` (needs `write` + `delete`) |

## Archives, editor & permissions

| Method | Path | Body / Params | Description |
|--------|------|---------------|-------------|
| `POST` | `/zip` | `{disk, paths[], name?}` | Stream a **zip** of the selected files/folders (read + `allow_download` + `allow_zip`; pre-flight size caps). Core-standalone (unproxied) |
| `POST` | `/extract` | `{disk, path, dest?}` | **Extract** a zip in place — atomic, zip-slip / zip-bomb / quota / dangerous-ext guarded (`allow_extract`) |
| `GET` | `/content?disk=&path=` | — | Read a text file's content (read perm; binary → 415, > 5 MB → 413) |
| `PUT` | `/content` | `{disk, path, content}` | Overwrite a text/config file (`allow_code_edit`, default **false**; existing files only) |
| `GET` | `/chmod?disk=&path=` | — | Read an SFTP file's octal mode (SFTP disks only) |
| `POST` | `/chmod` | `{disk, path, mode}` | Set an SFTP file's mode (write + `allow_chmod`) |
| `POST` | `/watermark` | `{disk, path, type, text?, logo_data?, x, y, scale, opacity, color?, dest?}` | Burn-in watermark editor (logo/text), permanent (**free/core**). Non-destructive: snapshots the original on first burn so it can be undone |
| `POST` | `/watermark/remove` | `{disk, path}` | Restore the pre-watermark original (404 if none) |
| `POST` | `/terminal` | `{disk, cmd, cwd?, confirm?}` | Stateless SSH command-runner, **SFTP disks only** (**free/core**). Opt-in via `allow_terminal` (default off) + `write`. Dangerous commands need `confirm` |

> **Tokened media endpoints** (query-string token, no `Authorization` header — for `<img>`/`<video>`): `GET /img?token=&width=&quality=&format=` (on-demand WebP/AVIF, see [FEATURES.md](FEATURES.md#on-demand-webp--avif)) and `GET /stream?token=` (gated private media). Both are core-standalone / Docker features.

## Metadata

| Method | Path | Body / Params | Description |
|--------|------|---------------|-------------|
| `GET` | `/meta?disk=&path=` | — | File info: size, mime, modified |
| `GET` | `/metadata?disk=&key=` | — | SEO metadata: title, alt_text, caption, tags |
| `PUT` | `/metadata` | `{disk, key, title, alt_text, caption, tags}` | Save metadata |
| `DELETE` | `/metadata` | `{disk, key}` | Delete metadata |

## Search, Quota, Audit

| Method | Path | Params | Description |
|--------|------|--------|-------------|
| `GET` | `/search?disk=&q=&limit=` | `limit` default 50 | Full-text search across file names + metadata |
| `GET` | `/search-folders?disk=&q=&limit=` | `limit` default 50 | Search folder names via the directory index |
| `GET` | `/quota?disk=` | — | Storage usage: used_mb, max_mb, percentage |
| `GET` | `/usage?disk=&refresh=` | — | **Usage dashboard** — quota + per-type and per-folder breakdown (file-cached; `refresh=true` recomputes, tight bucket) |
| `GET` | `/audit?limit=&offset=&action=&from=&to=&path=&actor=` | `limit` default 100 | Activity log, **scoped to the token's prefix**. Requires the `audit` permission (403 otherwise). |
| `GET` | `/disk/doctor?disk=&origin=` | — | **Bucket Doctor** — diagnose an S3/R2 disk (credentials, read/write/delete, presign, CORS, multipart, versioning) and return a report + IAM/CORS remediation. Requires `write`. |
| `GET` | `/license` | — | Server's commercial edition/status: `{edition, status, modules, limits, expires, days_left}`. Free MIT core → `{edition:'free'}` |

## Paid Modules

Gated by a 3-layer check (module installed + licensed + a per-token `allow_*` claim) — absent/unlicensed/not-allowed answers `501`/`402`/`403` respectively. All of these are **core-standalone** (not proxied by the Laravel/WordPress adapters) unless noted. See [`.claude/api-map.md`](../.claude/api-map.md) for full behavior.

| Method | Path | Body / Params | Module | Description |
|--------|------|---------------|--------|-------------|
| `POST` | `/share` | `{disk, path, ttl?, label?, password?, max_downloads?}` | `share` | Create a public share link. Token is returned once, never stored |
| `GET` | `/share/list?disk=` | — | `share` | List the caller's own share records |
| `POST` | `/share/revoke` | `{disk, jti}` | `share` | Revoke a share |
| `POST` | `/intake` | `{disk, path, ttl?, label?, password?, max_files?, max_mb?, allowed_ext?}` | `intake` | Create a write-only upload portal for a folder |
| `GET` | `/intake/list?disk=` | — | `intake` | List the caller's own intake portals |
| `POST` | `/intake/revoke` | `{disk, jti}` | `intake` | Revoke an upload portal |
| `GET` | `/versions?disk=&path=` | — | `versioning` | List prior versions of a file |
| `POST` | `/versions/restore` | `{disk, path, version_id}` | `versioning` | Restore a prior version (snapshots current bytes first) |
| `POST` | `/webhooks/test` | — | `webhooks` | Send a one-off ping to the token's configured `webhook_url` |
| `POST` | `/ai-vision` | `{disk, path, op, dest?}` | `ai` | BYO-key image ops: `bg_remove` / `upscale` / `smart_crop` |
| `POST` | `/ocr` | `{disk, path, lang?}` | `ocr` | Extract text from an image via `tesseract`. Returns `{path, chars, text}` |
| `POST` | `/backup` | `{from_disk, to_disk, path?, overwrite?}` | `backup` | One-way subtree sync between two disks (capped 5000 files/run) |
| `POST` | `/c2pa` | `{disk, path}` | `c2pa` | Verify Content Credentials (C2PA) via `c2patool` |
| `POST` | `/c2pa/sign` | `{disk, path, dest?}` | `c2pa` | Sign a file with Content Credentials |
| `GET` | `/audit/export?format=ndjson\|csv&action=&from=&to=&path=&actor=&disk=` | — | `audit-export` | Stream the full (unpaginated) audit log as a file download — NDJSON (default) or CSV, tenant-scoped like `/audit`. Requires the `audit` permission + `allow_audit_export` |
| `POST` | `/audit/purge` | `{disk?, before?}` | `audit-export` | Delete audit entries (live log + rotated archives) older than `before` (unix ts; falls back to the token's `audit_retention_days`, else `400 audit_purge_no_cutoff`). **Admin-only**: requires an unscoped token (empty `pathPrefix`) — `audit.jsonl` is per-disk, not per-tenant |

> Virus scanning (`virus` module) has no dedicated endpoint — when `allow_virus_scan` is on, `/upload`, `/import-url`, `/content` (PUT), and `/extract` scan bytes before they're written.

### SSO Bridge (paid module `sso`, pre-auth)

Not gated by a per-token claim (there's no token yet) — a server kill-switch (`FLUXFILES_SSO_ENABLED=true`) plus the module installed + licensed. Exists to put a login screen in front of the **standalone `/public` UI** when there's no host app minting tokens via `fluxfiles_token()`. OIDC only in v1 (no SAML). See [`packages/sso/README.md`](../packages/sso/README.md) for the full flow and env vars.

| Method | Path | Params / Body | Description |
|--------|------|----------------|-------------|
| `GET` | `/sso/login?redirect=` | — | Signs a short-lived state token, 302s to the IdP's `authorization_endpoint`. `redirect` must be a same-origin relative path. |
| `GET` | `/sso/callback?code=&state=` | — | Verifies `state`, exchanges `code` at the IdP's token endpoint, verifies the `id_token` via JWKS, resolves the identity's groups to a claims set (`FLUXFILES_SSO_CLAIMS_MAP`), mints the real access JWT, and 302s to `/public/index.html#boot=<one-time token>` — a URL **fragment**, never a query string, so the real JWT is never logged or sent to the server. |
| `POST` | `/sso/exchange` | `{token}` | Trades the one-time boot token (from the `#boot=` fragment) for the real access JWT: `{data: {token, expires_at}, error: null}`. This is the only way the real JWT is ever transmitted. |

## Chunk Upload (S3 multipart, files > 10MB)

| Method | Path | Body | Description |
|--------|------|------|-------------|
| `POST` | `/chunk/init` | `{disk, path, size}` | Initiate → `{upload_id, key, chunk_size}` |
| `POST` | `/chunk/presign` | `{disk, key, upload_id, part_number}` | Presign URL for part |
| `POST` | `/chunk/complete` | `{disk, key, upload_id, parts}` | Complete upload |
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

## Duplicate Detection

If a file with the same SHA-256 hash exists, upload returns:

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

Send `force_upload=true` (in form data) to upload anyway.
