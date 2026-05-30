# Metadata Storage — Travels With the User, Not Stored on the Server

## Goals

- Metadata (title, alt_text, caption, tags) is **not** stored on the server (SQLite removed)
- Metadata **travels with** the file — it lives in the same bucket/storage as the user's file
- Applies to: BYOB, S3, R2, Local

---

## Architecture

### 1. S3 / R2 / BYOB — Metadata in Object Metadata

| Component | Location | Notes |
| ---------------- | ------------------------------------ | ----------------------------------------------------- |
| **Metadata**     | S3 object metadata (x-amz-meta-*)    | title, alt_text, caption, tags — max 2KB/object       |
| **Search index** | `_fluxfiles/index.json` in the bucket | Updated when metadata is saved; used for full-text search |
| **Trash**        | —                                    | (No trash/restore/purge API in core today)            |
| **Audit**        | `_fluxfiles/audit.jsonl`             | Appended on every event                               |
| **File hash**    | x-amz-meta-file-hash                 | Duplicate detection                                   |

**Saving metadata:** `CopyObject` (copy to self) with the new Metadata — S3 has no direct metadata-update API.

**Reading metadata:** `HeadObject` or `GetObject` — the response contains the Metadata.

### 2. Local disk — Sidecar file

| Component | Location | Notes |
| ---------------- | ------------------------ | ------------------------------------------------------ |
| **Metadata**     | `_fluxfiles/meta/{path}.json` | e.g. `photos/2024.jpg` → `_fluxfiles/meta/photos/2024.jpg.json` (kept inside the protected `_fluxfiles/` namespace so a user-uploaded `*.meta.json` can't collide with or overwrite it). Legacy `{path}.meta.json` sidecars are migrated on read. |
| **Search index** | `_fluxfiles/index.json`  | Cache for fast search                                  |
| **Trash**        | —                        | (No trash/restore/purge API in core today)             |
| **Audit**        | `_fluxfiles/audit.jsonl` | Appended on every event                                |

---

## Flows

### Save metadata (S3/R2)

1. `CopyObject(bucket, key, CopySource: bucket/key, Metadata: {title, alt_text, caption, tags})`
2. Update `_fluxfiles/index.json` — add/update the entry for the key
3. (Optional) Append audit

### List files

1. `ListObjects` from storage
2. For S3: `HeadObject` per file to read Metadata (or use the index)
3. For Local: read the `_fluxfiles/meta/{key}.json` sidecar if present (with a legacy `{key}.meta.json` fallback)
4. Filter trash (S3: drop the `_trash/` prefix, Local: drop `_trash/`)

### Search

1. Load `_fluxfiles/index.json`
2. Filter in memory by the query
3. Return results (optionally with highlights)

### Trash

Core currently has `DELETE /api/fm/delete` (permanent delete). There are no trash/restore/purge endpoints.

---

## Status

- **Applied:** metadata, trash, audit, and search all live in the user's storage (S3 tags / sidecar / index.json / audit.jsonl)
- **No more SQLite** — everything travels with the user's storage
