# Metadata Storage — Travels With the User, Not Stored on the Server

## Goals

- Metadata (title, alt_text, caption, tags) is **not** stored on the server (SQLite removed)
- Metadata **travels with** the file — it lives in the same bucket/storage as the user's file
- Applies to: **Local, SFTP, S3, R2, and BYOB** disks of any of these driver types — the same two storage strategies below cover every disk FluxFiles supports.

---

## Architecture

### 1. S3 / R2 / BYOB (S3-compatible) — Metadata in Object Metadata

Only disks whose driver is `s3` (this covers AWS S3, Cloudflare R2, and any
BYOB disk configured with the `s3` driver) get true object-metadata storage.

| Component | Location | Notes |
| ---------------- | ------------------------------------ | ----------------------------------------------------- |
| **Metadata**     | S3 object metadata (x-amz-meta-*)    | title, alt_text, caption, tags — max 2KB/object       |
| **Search index** | `_fluxfiles/index.json` in the bucket | Updated when metadata is saved; used for full-text search |
| **Trash**        | `_fluxfiles/trash/<id>/` + `_fluxfiles/trash.json` manifest | Soft-delete, move-based (see Trash below)  |
| **Audit**        | `_fluxfiles/audit.jsonl`             | Appended on every event                               |
| **Audit archive** | `_fluxfiles/audit/archive/audit-<ts>-<hex>.jsonl` | Rotated-out audit tail, kept (not deleted) — see Audit retention below |
| **File hash**    | x-amz-meta-file-hash                 | Duplicate detection                                   |

**Saving metadata:** `CopyObject` (copy to self) with the new Metadata — S3 has no direct metadata-update API.

**Objects over 5 GiB:** a single `CopyObject` is capped at 5 GiB, so a header-backed field (title/alt/caption/tags, owner) of a bigger object cannot be rewritten. The save is refused with `422 metadata_object_too_large` rather than leaving the headers stale (the metadata panel and `owner_only` read only the headers). Saves that change no header (size, modified time, mime) still succeed. Multipart uploads set the owner header at `CreateMultipartUpload`, so a huge upload is owned from the start; in-place rewrites (editor save, module/watermark writes) hand the object's existing headers back on the `PutObject`.

**Reading metadata:** `HeadObject` or `GetObject` — the response contains the Metadata.

### 2. Local & SFTP disks — Sidecar file

Every other driver (`local`, `sftp`, and any BYOB disk that isn't `s3`) is
treated the same way by `StorageMetadataHandler`: it checks only whether the
driver equals `s3`, so an SFTP disk gets exactly the same sidecar-file
treatment as a Local one, not the S3 object-metadata path (SFTP/SSH has no
concept of arbitrary key-value object metadata).

| Component | Location | Notes |
| ---------------- | ------------------------ | ------------------------------------------------------ |
| **Metadata**     | `_fluxfiles/meta/{path}.json` | e.g. `photos/2024.jpg` → `_fluxfiles/meta/photos/2024.jpg.json` (kept inside the protected `_fluxfiles/` namespace so a user-uploaded `*.meta.json` can't collide with or overwrite it). Legacy `{path}.meta.json` sidecars are migrated on read. |
| **Search index** | `_fluxfiles/index.json`  | Cache for fast search                                  |
| **Trash**        | `_fluxfiles/trash/<id>/` + `_fluxfiles/trash.json` manifest | Soft-delete, move-based (see Trash below) |
| **Audit**        | `_fluxfiles/audit.jsonl` | Appended on every event                                |
| **Audit archive** | `_fluxfiles/audit/archive/audit-<ts>-<hex>.jsonl` | Rotated-out audit tail, kept (not deleted) — see Audit retention below |

**One SFTP-specific wrinkle:** the index-write lock (`flock`) is only taken
for the `local` driver, whose index file lives on *this* server's own
filesystem. For SFTP (and S3/R2), the disk's `root` is on a remote host, so a
local `flock` would either hit the wrong filesystem or silently no-op —
index/metadata writes still go through Flysystem exactly as described above,
just without the local advisory lock (best-effort under concurrency, same as
S3/R2).

---

## Flows

### Save metadata (S3/R2)

1. `CopyObject(bucket, key, CopySource: bucket/key, Metadata: {title, alt_text, caption, tags})`
2. Update `_fluxfiles/index.json` — add/update the entry for the key
3. (Optional) Append audit

### List files

1. `ListObjects` from storage
2. For S3: `HeadObject` per file to read Metadata (or use the index)
3. For Local/SFTP: read the `_fluxfiles/meta/{key}.json` sidecar if present (with a legacy `{key}.meta.json` fallback)
4. Listing/search skip everything under `_fluxfiles/` (trashed items are moved there, so they drop out of both automatically)

### Search

1. Load `_fluxfiles/index.json`
2. Filter in memory by the query
3. Return results (optionally with highlights)

### Trash

`DELETE /api/fm/delete` is **permanent** — it does not move anything to
trash. Soft-delete is a separate, move-based flow under `_fluxfiles/trash/`,
tracked by a `_fluxfiles/trash.json` manifest, with its own endpoints:

| Method | Endpoint | Effect |
| --- | --- | --- |
| `POST` | `/api/fm/trash` | Soft-delete: moves the file (or, for a folder, the whole subtree) into `_fluxfiles/trash/<id>/`, snapshotting its metadata into the manifest and dropping it from the active index. |
| `POST` | `/api/fm/trash/restore` | Moves an entry back to its original path (or an explicit new path), restores its metadata, and removes the trash manifest entry. |
| `GET` | `/api/fm/trash/list` | Lists trash entries visible to the caller. |
| `POST` | `/api/fm/trash/purge` | **Permanent** — deletes one trash entry's directory outright. |
| `POST` | `/api/fm/trash/empty` | **Permanent** — deletes every trash entry visible to the caller. |

- **Permission:** every trash operation (soft-delete, restore, purge, empty)
  requires the **`delete`** permission — none of them are reachable with a
  `write`-only token.
- **Scope:** each operation is checked against the caller's `pathPrefix` and,
  when `owner_only` is set, against `deleted_by === userId` — a caller can
  never see, restore, or purge another tenant's/owner's trash entry even on a
  shared disk.
- **Files and folders both supported.** Trashing/restoring a folder moves the
  entire subtree, including any WebP variants under `_variants/` for images
  inside it (the flow moves `<key>` and its known variant paths together, not
  just the raw file bytes) — so nothing is orphaned or leaked when a directory
  is trashed.
- **Trash ids are opaque hex tokens**, validated before use in any path
  (`/^[A-Za-z0-9_-]+$/`) so a tampered manifest (BYOB) can never be turned into
  a path-traversal payload for restore/purge.
- The UI soft-deletes everything through `/api/fm/trash` — there is no "hard
  delete" affordance in the standard UI flow; permanent delete exists for
  API/automation use and for the purge/empty trash actions above.

### Audit retention (archive-before-truncate)

`_fluxfiles/audit.jsonl` rotates once it exceeds 5MB or 5000 lines. Rotation
is **not** destructive: the dropped tail is written first to
`_fluxfiles/audit/archive/audit-<timestamp>-<random-hex>.jsonl` under the same
lock, then trimmed from the live file — so historical entries are archived,
never silently lost. This is a free/core correctness behavior, not gated by
any module. A paid `audit-export` module layers `GET /api/fm/audit/export`
(NDJSON/CSV, reads live + every archive file) and `POST /api/fm/audit/purge`
(admin-only, actually deletes old entries/archives) on top of these storage
primitives — export/purge are opt-in and separate from the always-on free
rotation above.

---

## Runtime files on disk (`_fluxfiles/`, `rate_limit.json`)

FluxFiles creates a few internal runtime files under your storage root (for the local
disk: `packages/core/storage/uploads/`). These files are **not user content** and are
**not meant to be edited by hand**. None of them may be web-served — the deny rules are
in [`DEPLOYMENT.md`](../guides/DEPLOYMENT.md).

### `_fluxfiles/` directory

Hidden internal folder used for indexes and logs (one per disk root, or per tenant prefix for prefix-scoped bookkeeping). The API/UI blocks system paths (`_fluxfiles/`, `_variants/`) from normal list/rename/move/delete operations.

- **`_fluxfiles/index.json`**: Metadata index for files (title/alt/caption/tags) stored alongside objects. Used by `GET /api/fm/search` to perform fast search across the disk.
- **`_fluxfiles/dirs.json`**: Directory index (list of folder paths). Used by `GET /api/fm/search-folders` so searches like `test2` can match folder names without scanning the whole storage.
- **`_fluxfiles/meta/`** (local/SFTP disks): per-file metadata sidecars. S3/R2 keep this in object metadata instead.
- **`_fluxfiles/audit.jsonl`**: Append-only, HMAC-chained audit log (JSON Lines). When it grows too large the oldest lines are moved to **`_fluxfiles/audit/archive/`** (never silently dropped).
- **`_fluxfiles/trash.json`** + **`_fluxfiles/trash/`**: soft-deleted files and their manifest.
- **`_fluxfiles/originals/`**: pre-watermark originals (so a burn-in can be undone).
- **`_fluxfiles/usage.json`**: cached usage-dashboard breakdown.
- Paid-module state, when used: `shares.json`, `intakes.json`, `versions/`, `holds.json`, `fixity.json`.
- **`_fluxfiles/index.lock`** (local disks only): Lock file for safely updating indexes on local filesystem. Not used for S3/R2.

If you delete `_fluxfiles/`, FluxFiles will generally recreate it as needed, but you may temporarily lose search results / audit history until indexes are rebuilt by new operations (or by the `bin/fluxfiles index` CLI) — and you lose trash, version history and legal holds outright.

### `storage/rate_limit.json` (and other server-local state)

`storage/` here is `packages/core/storage/`, or wherever `FLUXFILES_STORAGE_PATH`
points.

File-backed state of the rate limiter (per-user read/write limits set by
`FLUXFILES_RATE_LIMIT_READ` / `FLUXFILES_RATE_LIMIT_WRITE`). The counters are
sharded one small file per user + action under **`storage/rate_limit.d/`**;
`storage/rate_limit.json` is only an epoch marker that every shard records.

- Deleting `rate_limit.json` resets every limit at once (it is recreated); handy in
  development and tests.
- In production, **do not expose `rate_limit.json` or `rate_limit.d/` publicly**
  (deny access at your web server).

`storage/tus/` holds partial resumable uploads (swept after 24 h) — server-local runtime
state that must never be served.

> Everything above describes the **default `FLUXFILES_STORAGE_BACKEND=json`** mode. Set
> `FLUXFILES_STORAGE_BACKEND=db` to move metadata, search/folder index, audit log, trash
> manifest, legal holds, quota, and the rate limiter into your own self-hosted
> MySQL/PostgreSQL/SQLite instead — none of those `_fluxfiles/*.json` files,
> `rate_limit.json` or `rate_limit.d/` are created or used in that mode. File bytes always stay on your
> configured disk either way. See
> [`DB-STORAGE-MIGRATION-DESIGN.md`](../design/DB-STORAGE-MIGRATION-DESIGN.md).

---

## Status

- **Applied:** metadata, trash, audit (incl. archive), and search all live in the user's storage (S3 object metadata / sidecar / index.json / audit.jsonl / audit archive) — for **every** disk type FluxFiles supports, including SFTP.
- **No more SQLite** — everything travels with the user's storage, by default
- **Except when opted in:** an operator can set `FLUXFILES_STORAGE_BACKEND=db` to move bookkeeping (metadata/search/audit/trash/rate-limits) into their own relational DB instead — see `docs/design/DB-STORAGE-MIGRATION-DESIGN.md`. Default remains JSON/storage-resident; nothing here changes unless that env var is set.
