# Core API Map

All routes below are implemented in `packages/core/api/index.php`.

## Public Routes

- `GET /public/index.html`: serves UI with injected locale data.
- `GET /public` and `GET /public/`: same UI route.
- `GET /api/fm/lang`: list available locales.
- `GET /api/fm/lang/{locale}`: fetch locale messages.

## Authenticated File Routes

All authenticated routes require `Authorization: Bearer <JWT>`.

- `GET /api/fm/list?disk=local&path=&limit=0&cursor=`
  - Lists files and directories. With `limit > 0`, returns `{items,next_cursor,total}`; otherwise returns the legacy flat array.

- `POST /api/fm/upload`
  - Multipart form fields: `file`, `disk`, `path`, optional `force_upload`.

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

- `GET /api/fm/search?disk=local&q=query&limit=50`
  - Searches file key/title/alt/caption/tags through the storage-backed index.

- `GET /api/fm/search-folders?disk=local&q=query&limit=50`
  - Searches tracked folder paths.

- `GET /api/fm/quota?disk=local`
  - Returns quota information using the JWT `max_storage` claim.

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

- Per-tenant config (`ai_auto_tag`, `rate_read`/`rate_write`, `variants`, upload/ext/quota limits, `owner_only`) is carried in **JWT claims**, not a `/config` route — the token *is* the config.
- `list`, `search`, and `search-folders` rows include a stable `created` (Unix seconds) alongside `modified`; folders carry `created` too (S3/R2 dir prefixes may lack `modified`). The UI sorts by `created || modified`.
- Mutating requests validate the `Origin` header against `FLUXFILES_ALLOWED_ORIGINS` when origins are configured.
- Route responses usually follow `{ "data": ..., "error": null }`; API exceptions include `error_code` when available.
