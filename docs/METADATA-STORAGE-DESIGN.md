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

## Status

- **Applied:** metadata, trash, audit (incl. archive), and search all live in the user's storage (S3 object metadata / sidecar / index.json / audit.jsonl / audit archive) — for **every** disk type FluxFiles supports, including SFTP.
- **No more SQLite** — everything travels with the user's storage, by default
- **Except when opted in:** an operator can set `FLUXFILES_STORAGE_BACKEND=db` to move bookkeeping (metadata/search/audit/trash/rate-limits) into their own relational DB instead — see `docs/DB-STORAGE-MIGRATION-DESIGN.md`. Default remains JSON/storage-resident; nothing here changes unless that env var is set.
