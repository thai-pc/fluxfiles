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

## Archives, editor & permissions

| Method | Path | Body / Params | Description |
|--------|------|---------------|-------------|
| `POST` | `/zip` | `{disk, paths[], name?}` | Stream a **zip** of the selected files/folders (read + `allow_download` + `allow_zip`; pre-flight size caps). Core-standalone (unproxied) |
| `POST` | `/extract` | `{disk, path, dest?}` | **Extract** a zip in place — atomic, zip-slip / zip-bomb / quota / dangerous-ext guarded (`allow_extract`) |
| `GET` | `/content?disk=&path=` | — | Read a text file's content (read perm; binary → 415, > 5 MB → 413) |
| `PUT` | `/content` | `{disk, path, content}` | Overwrite a text/config file (`allow_code_edit`, default **false**; existing files only) |
| `GET` | `/chmod?disk=&path=` | — | Read an SFTP file's octal mode (SFTP disks only) |
| `POST` | `/chmod` | `{disk, path, mode}` | Set an SFTP file's mode (write + `allow_chmod`) |

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
