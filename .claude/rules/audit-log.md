---
paths:
  - "packages/core/api/AuditChain.php"
  - "packages/core/api/AuditLogStorage.php"
  - "packages/core/api/FixityChecker.php"
  - "packages/audit-export/**"
---

**Audit log is archive-before-truncate and tamper-evident; export/verify/purge/fixity are a separate paid layer.** `StorageMetadataHandler::audit()`'s rotation writes the dropped tail to `_fluxfiles/audit/archive/audit-<ts>-<hex>.jsonl` first, under the same lock — a free/core correctness fix. Every entry is HMAC-chained to the previous one (`AuditChain`, key = HKDF of `FLUXFILES_SECRET`) on every backend (JSON, core/Laravel/WordPress DB handlers with an `audit_chain_head` row lock). `readAuditArchive()`/`purgeAuditBefore()` are the storage primitives (no Claims/license awareness); `GET /api/fm/audit/export` (NDJSON/CSV, full unpaginated dump), `GET /api/fm/audit/verify` (chain check, unscoped token), `POST /api/fm/fixity/check` (SHA-256 baseline in the tenant's `_fluxfiles/fixity.json`, unexplained changes audited as `fixity_mismatch`) and `POST /api/fm/audit/purge` (admin-only: requires an unscoped token, since `audit.jsonl` is per-disk not per-tenant) are gated by the `audit-export` module + `allow_audit_export` claim. Every mutating route resolves to a named audit action (CI guard), plus `zip_download`, `presign_*`, `access_denied`; reads only with `audit_reads`.
