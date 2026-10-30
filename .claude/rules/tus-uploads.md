---
paths:
  - "packages/core/api/TusServer.php"
  - "packages/core/api/ChunkUploader.php"
---

**Resumable uploads (tus 1.0)** (`POST/HEAD/PATCH/DELETE /api/fm/tus`, `TusServer.php`, free/core): lifts PHP's `upload_max_filesize` ceiling on local/SFTP disks and resumes after a dropped connection. Every upload rule (perm, disk, ext, `max_upload`, scope, quota, `max_files`, collision, `owner_only`, legal hold) is checked at **creation**, before any byte is staged; an upload is bound to the creating token's `sub` + `prefix` + disk. The last chunk runs the normal upload pipeline. Partial bytes live in `<FLUXFILES_STORAGE_PATH>/tus/` (server-local, swept after 24 h — **multi-node needs a shared dir or sticky sessions**). **Core-only — not proxied** by Laravel/WordPress; the UI falls back to a plain upload there. Own bucket `FLUXFILES_TUS_RATE_LIMIT` (600/min).

**Presigned uploads:** `POST /api/fm/presign` with `method: "PUT"` can't bind the body size (advisory only, 15-min TTL cap); `method: "POST"` returns an S3 form-upload policy the **bucket** enforces (`content-length-range`, exact key, pinned `Content-Type`) — R2 has no POST Object (`400 presign_post_unsupported`). `allow_presign_put: false` refuses PUT. Both are refused while virus/DLP scanning is on.
