# DB-Backed Storage State — Reversing the JSON-Only Design

> **This reverses `docs/METADATA-STORAGE-DESIGN.md`.** That doc's headline was
> "No more SQLite — everything travels with the user's storage." This doc
> keeps the *files themselves* (bytes) fully storage-resident and BYOB-neutral
> — that part is **not** reversed — but moves the *bookkeeping* (per-file
> metadata, search index, folder index, audit log, trash manifest, quota
> tracking) into the operator's own self-hosted relational DB. The reversal
> was decided in conversation with the user; this doc designs **how**, not
> **whether**. One line of justification (expanded in §1): `index.json` is a
> linear-scan-on-every-search flat file, S3 object metadata is capped at 2KB
> and costs a `CopyObject` per edit, and audit/trash/quota all want filtered,
> ordered, aggregated queries that a JSON blob structurally cannot give you
> without loading the whole thing into memory every time. None of that was
> wrong to avoid when the file count was small; it stops being free once
> tenants have tens of thousands of files.

## 0. Executive summary

**Moving to DB:** per-file metadata (title/alt/caption/tags/hash/dims),
search index, folder index, audit log, trash manifest, quota tracking,
**and the rate limiter** (`rate_limits`, §3.7 — reversed from this doc's
original draft, which had proposed keeping it file-based; the user's
requirement is zero JSON files left, full stop, so it moves too. See §3.7 for
why ".env" doesn't work for this one and what the DB design actually is.).

**Not moving — explicit exclusions:**
- **React, Vue, SDK (`@fluxfiles/sdk`), Node (`@fluxfiles/node`), CKEditor4,
  TinyMCE, Summernote — zero changes.** Every one of these only talks to
  FluxFiles core's HTTP API (postMessage/iframe, or JWT + `fetch`). The
  storage backend behind `list()`/`search()`/`/audit`/`/trash` is fully
  opaque to them; this design does not touch `packages/{react,vue,sdk,node,
  ckeditor4,tinymce,summernote}` at all. Stated here explicitly so scope
  doesn't creep into those packages during implementation.
- **Not a paid module.** This is an operator-wide infrastructure choice (like
  choosing local vs S3 disks), not a per-tenant feature — so it is gated by a
  **server env var**, not a JWT claim, and never touches `ModuleRegistry`,
  licensing, or `packages/pack-modules.php`. No new SKU.

**Files bytes are untouched.** Local/S3/R2/SFTP storage, BYOB, Flysystem — all
unchanged. Only the JSON sidecars/index files (`_fluxfiles/index.json`,
`dirs.json`, `trash.json`, `audit.jsonl`) and S3 object-metadata calls are
replaced by DB rows, and only when the operator opts in.

**Backward compatible, opt-in, reversible.** Default behavior for every
existing self-hosted install is unchanged (`FLUXFILES_STORAGE_BACKEND=json`
default). See §9 for the migration/cutover/rollback story.

---

## 1. Architecture fit

FluxFiles' stateless/JWT/storage-resident grain is about **per-tenant**
config and **per-request** authorization living in the token, and file
*bytes* living in the user's own storage — never a FluxFiles-vendor-hosted
central database. Nothing in that grain says the operator's own bookkeeping
metadata must be a flat JSON file specifically; it says metadata must not
become **the server's private, opaque state that the user's own storage
doesn't reflect**. A self-hosted DB the operator already runs (their own
Postgres/MySQL/SQLite, not a FluxFiles SaaS backend) is exactly as
"operator-owned" as their own S3 bucket — this was clarified in conversation
and is **not** a privacy/compliance regression by itself.

What *does* change, and is the one real new risk (see §11): today, S3 object
metadata is scoped by the object key itself — you can only read metadata for
an object you can already `HeadObject`/`GetObject`, so access control was a
side effect of storage access control. A relational DB has no such built-in
boundary: a single DB instance can (and, per the multi-tenant deployment this
change specifically targets, **will**) hold rows for many different
JWT-scoped tenants — different `owner`, different `pathPrefix`, potentially
different disks — behind one FluxFiles install. Every query must reconstruct
the exact scoping `Claims`/`FileManager` already enforce. This is a **new bug
class** (a missing `WHERE owner = ?` leaks cross-tenant metadata) that the
old design structurally couldn't have. §11 gives concrete guidance.

**What stays exactly as it is:**
- `Claims`/`FileManager` remain the single place authorization is decided
  (disk access, `owner_only`, path-prefix scoping, permissions). The
  metadata/audit/trash repository layer **never** independently decides what
  a tenant may see — it only executes the already-scoped query FileManager
  hands it. This is true today for the JSON handler and must remain true for
  the DB handler (§11).
- Per-tenant JWT claims are unaffected. Storage backend selection is
  server-wide config (env var), like `FLUXFILES_STORAGE_PATH`, not
  `prefix`/`disks` — **no new JWT claims are introduced by this feature**
  (confirmed in §10; this also means zero changes to
  `tests/unit/test-config-doc.php`'s expectations).
- BYOB, disk drivers, Flysystem, presigning — completely untouched. The DB
  stores *pointers and metadata about* objects, never object bytes and never
  storage credentials.

---

## 2. Precursor refactor — widening `MetadataRepositoryInterface`

This has to happen before (or as part of) the DB backend lands, because
without it a DB-backed handler cannot actually be swapped in.

**The finding:** `FileManager`'s constructor takes the interface
(`MetadataRepositoryInterface $meta`), but 19 call sites in
`packages/core/api/FileManager.php` guard trash/directory-index/hash
operations with `instanceof StorageMetadataHandler` (the **concrete class**),
not the interface — e.g. `restore()`:

```php
if (!($this->meta instanceof StorageMetadataHandler)) {
    throw new ApiException('Trash is not available for this storage', 400, 'trash_unavailable');
}
```

Separately, `packages/core/api/index.php`'s route handlers
(`handleGetMetadata`, `handleSaveMetadata`, `handleDeleteMetadata`, the audit
routes) and **both** `packages/laravel/src/Http/Controllers/
FluxFilesController.php` and `packages/wordpress/includes/FluxFilesApi.php`
type-hint their `$metaRepo` property as the concrete `StorageMetadataHandler`
class, not the interface.

**Consequence if left unfixed:** a `DbMetadataHandler implements
MetadataRepositoryInterface` would fail every one of those `instanceof`
checks, silently disabling trash (`400 trash_unavailable`) and breaking the
metadata/audit routes' type-hints entirely (`TypeError`). This is not
optional plumbing — it *is* the seam the DB backend plugs into.

**Decision:** extend `MetadataRepositoryInterface` (same name — it already
means "the metadata repository," widening it doesn't change what it
conceptually is) to declare every method currently reached only via the
concrete class:

```php
interface MetadataRepositoryInterface
{
    // existing: get, save, delete, deleteChildren, renameChildren, getBulk,
    // search, saveHash, findByHash, syncToS3Tags, countChildren

    // NEW — directory/folder index
    public function trackDir(string $disk, string $dirKey): void;
    public function trackParents(string $disk, string $key): void;
    public function dirsCreated(string $disk): array;
    public function renameDirPrefix(string $disk, string $oldPrefix, string $newPrefix): int;
    public function deleteDirPrefix(string $disk, string $prefix): int;
    public function searchFolders(string $disk, string $query, int $limit = 50, string $pathPrefix = '', bool $includeHidden = false): array;

    // NEW — trash (soft-delete manifest)
    public function allTrash(string $disk): array;
    public function getTrash(string $disk, string $id): ?array;
    public function addTrash(string $disk, string $id, array $entry): void;
    public function removeTrash(string $disk, string $id): void;

    // NEW — audit
    public function audit(string $disk, string $action, array $context = []): void;
    public function readAudit(string $disk, ?string $userId = null): array;
    public function readAuditArchive(string $disk): array;   // json backend: rotated files; db backend: always []
    public function purgeAuditBefore(string $disk, int $beforeTs): array;

    // NEW — used by list()/upload paths
    public function indexFile(string $disk, string $key, array $data, bool $overwrite = false): bool;
}
```

Then: change every `instanceof StorageMetadataHandler` guard in
`FileManager.php` to `instanceof MetadataRepositoryInterface` (i.e. delete
the guard — the interface now always has the method), and change the
concrete `StorageMetadataHandler` type-hints in `index.php`'s metadata/audit
route handlers and both adapters' `$metaRepo` properties/constructors to the
interface type. `StorageMetadataHandler` itself needs no behavior change — it
already implements all of these methods; only its declared contract widens.

This refactor is what makes §3/§5/§6's new handlers real, swappable
implementations rather than parallel code paths bolted on beside the
`instanceof` checks. It ships as the same core release as the DB backend, not
a separate one (splitting it would ship an interface with no second
implementer, which is pointless churn).

---

## 3. Schema design

Four tables. No `search_index`/`dirs` tables **as JSON-shaped mirrors** —
per the guiding decision, folder listing/search become SQL queries against
`file_metadata`, except for one narrow case (empty folders) that needs its
own tiny table — see below.

### 3.1 `file_metadata`

One row per real file (not per metadata edit — same "row per object" model
`index.json` already uses, just relational).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint / integer PK (autoincrement/serial) | Internal surrogate key. |
| `disk` | varchar(64) | Disk name (`local`, `s3`, `r2`, a BYOB disk name, …). |
| `owner` | varchar(191), nullable | `uploaded_by` — the uploader's `Claims::$userId`. Source of truth for `owner_only`. |
| `path` | varchar(1024) | Full, **unscoped** storage key (includes any tenant `prefix` — same key Flysystem uses). |
| `path_hash` | char(64) | `hash('sha256', "{$disk}|{$path}")`. The actual **unique key** — see §3.4 for why raw `path` is a poor index column on some engines. |
| `title`, `alt_text`, `caption`, `tags` | text, nullable | Same fields as today's sidecar/S3-metadata/index entry. |
| `mime` | varchar(127), nullable | |
| `size` | bigint unsigned, nullable | Bytes. |
| `width`, `height` | int, nullable | Images only. |
| `file_hash` | char(64), nullable | SHA-256 hex, for dedup (`findByHash`). |
| `watermarked` | boolean, default false | Mirrors today's `meta.watermarked` flag. |
| `object_uuid` | char(36), nullable | S3/R2 only — the breadcrumb UUID (§8). Null for local/SFTP. |
| `created_at` | bigint (unix seconds) | Immutable first-seen time (matches `index.json`'s `created` — existing wins on re-save). |
| `modified_at` | bigint (unix seconds) | Updated on every `save()`. |
| `extra` | JSON / text, nullable | Free-form bag for any future field `save()` merges that doesn't have its own column yet — mirrors the sidecar's tolerance for arbitrary keys without a migration for every new one. |

**Indexes:**
- `UNIQUE (disk, path_hash)` — the actual write/lookup key.
- `INDEX (disk, owner)` — `owner_only` scoping and dedup-by-owner (`findByHash`'s `$ownerUserId` filter).
- `INDEX (disk, file_hash)` — dedup lookup.
- `INDEX (disk, path)` (or a prefix index — see §3.4) — prefix-scoped listing/search (`WHERE path = ? OR path LIKE ?`).

Note on **variants**: today, `_variants/*.webp` files are **never** tracked
in `index.json` at all — `QuotaManager` discovers their bytes by a live
recursive storage walk. This design does not change that (see §3.3) — no
`file_metadata` row is created for a variant. `file_metadata` is exclusively
"real," user-visible files, same population as `index.json` today.

### 3.2 `directories` (folder index)

Needed because an **empty folder** (created via `mkdir`, containing zero
files) has no `file_metadata` row to imply its existence — `dirs.json`
exists today for exactly this reason (folder-created timestamps that survive
even when the folder never gets a file, and S3/R2 "directories" that are
prefix conventions with no real object at all). This table stays a thin,
literal mirror of `dirs.json` rather than being derived from
`file_metadata`, because derivation can't produce an empty folder.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `disk` | varchar(64) | |
| `path` | varchar(1024) | Folder key, no trailing slash (matches `dirs.json` keys). |
| `path_hash` | char(64) | Same hashing scheme as `file_metadata`. |
| `created_at` | bigint, nullable | First-seen time. |

`UNIQUE (disk, path_hash)`, `INDEX (disk, path)` (prefix search).

Folders are **not** owner-scoped today (`dirs.json` is disk-global) — this
design keeps that behavior; adding an `owner` column here is a listed open
question (§14.4) rather than a silent scope change.

### 3.3 Quota — no new table

**Decision: no `quota`/`usage` table.** Two different things currently
called "quota" need different treatment:

1. **Enforcement** (`QuotaManager::assertQuota`/`getUsage`, checked before
   every write that has a `max_storage`/`max_files` claim) **must count real
   bytes on disk**, including `_variants/`, `_fluxfiles/audit.jsonl`,
   `_fluxfiles/trash/*` — i.e. actual storage consumption. Since variants are
   (deliberately, see §3.1) never rows in `file_metadata`, a `SUM(size) FROM
   file_metadata` would **silently undercount** real usage — upload a lot of
   images, generate a lot of untracked WebP variants, and a DB-only quota
   check would let a tenant blow past their real disk usage while the DB
   says they're under quota. That is a quota-bypass bug, not a rounding
   error, so it is **not acceptable** to trade accuracy for query speed on
   the enforcement path. `QuotaManager::assertQuota()`/`getUsage()` stay
   **exactly as they are today** — a live recursive storage walk via
   Flysystem `listContents()` — regardless of the metadata backend. This is
   a case where accepting existing perf characteristics is correct, not lazy.

2. **The `/api/fm/usage` dashboard breakdown** (`file_count`/`by_type`/
   `by_folder` — display-only figures, already excluding `_fluxfiles/`/
   `_variants/` even today) is a good fit for a DB fast-path when
   `backend=db`: one `SELECT ... GROUP BY` over `file_metadata` replaces a
   full recursive `listContents()` walk, which matters a lot on large trees.
   `QuotaManager::getUsageBreakdown()` gains an optional fast path (used only
   when a DB handler is present) for these display fields; `raw_total` (the
   quota-meter figure) still comes from the accurate live walk, matching #1.

This keeps the DB an accelerator for what it's good at (aggregation/display)
without becoming the source of truth for the one number that, if wrong,
means a tenant silently exceeds a hard cap.

### 3.4 A note on the `path` index

MySQL/InnoDB has historically limited index key length (767 bytes for
`utf8mb4` on older configurations, larger with `innodb_large_prefix`, but
still finite) — a `varchar(1024)` `utf8mb4` column can exceed that as a
direct index. Postgres/SQLite have no such practical limit. To keep the
schema portable across all three target engines without per-engine DDL
branching on this one column:

- The **authoritative** unique key is `(disk, path_hash)` — a fixed-width
  `char(64)`, trivially indexable on every engine.
- A secondary `(disk, path)` index (or, on MySQL, a **prefix** index —
  `INDEX (disk, path(191))`) supports prefix-scoped `LIKE 'prefix/%'`
  queries. On MySQL this index degrades to "narrow the candidate set by the
  first 191 bytes, then filter the rest," which is still a large win over a
  full table scan for the realistic case (prefixes rarely collide in their
  first 191 bytes) and is the same trade-off any MySQL schema with long text
  keys makes.
- This is explicitly a "recommend, don't over-engineer" guidance line, not a
  hard mandate — an operator running Postgres/SQLite gets a full, unbounded
  index for free either way.

### 3.5 `trash`

| Column | Type | Notes |
|---|---|---|
| `id` | varchar(32) | The existing hex trash id (`bin2hex(random_bytes(8))`) — kept as the primary key alongside `disk` (a trash id is generated per-disk, collisions across disks are not a concern but keeping `disk` in the key costs nothing). |
| `disk` | varchar(64) | Part of the composite PK: `(disk, id)`. |
| `owner` | varchar(191), nullable | `deleted_by`. |
| `original_key` | varchar(1024) | |
| `basename` | varchar(255) | |
| `is_dir` | boolean, default false | |
| `size` | bigint, default 0 | |
| `deleted_at` | bigint (unix) | |
| `variants` | JSON, nullable | List of variant size names moved with a single-file trash entry. |
| `meta` | JSON, nullable | Metadata snapshot at delete time (single file). |
| `files` | JSON, nullable | `[{rel, meta}]` — directory trash only. |
| `dirs` | JSON, nullable | `[rel, ...]` — directory trash only (empty subdirectories). |

`PRIMARY KEY (disk, id)`, `INDEX (disk, owner)`, `INDEX (disk, deleted_at)`
(for `trash/empty` and future retention tooling).

### 3.6 `audit_log`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK, autoincrement | Row order is a reasonable tiebreaker for same-second entries (JSON's append order served this role before). |
| `disk` | varchar(64) | Audit is per-disk today (`audit.jsonl` lives on one disk), unchanged. |
| `owner` | varchar(191), nullable | `context.user_id` — who performed the action. |
| `action` | varchar(64) | e.g. `upload`, `rename`, `delete`, `ai_tag`. |
| `file_key` | varchar(1024), nullable | `context.file_key`. |
| `ip` | varchar(45), nullable | IPv4/IPv6. |
| `user_agent` | text, nullable | |
| `detail` | JSON / text, nullable | `context.detail` — arbitrary extra context, same tolerance as `extra` on `file_metadata`. |
| `created_at` | bigint (unix seconds) | |

`INDEX (disk, owner, created_at)`, `INDEX (disk, created_at)`,
`INDEX (disk, action, created_at)` — the export route
(`GET /api/fm/audit/export?action=&from=&to=&path=&actor=&disk=`) filters on
exactly these dimensions; a DB backend turns that route from a full-log
linear scan into an indexed range query.

**A DB backend eliminates the rotation/archive mechanism entirely** —
`_fluxfiles/audit/archive/audit-<ts>-<hex>.jsonl` was purely a mitigation for
JSON files growing unbounded (5MB/5000-line threshold). A DB table has no
such practical cap; `readAuditArchive()` on the DB handler simply returns
`[]` always (there is nothing to archive — everything lives in one table),
and `purgeAuditBefore()` becomes a single `DELETE FROM audit_log WHERE disk
= ? AND created_at < ?` with no separate archive-file bookkeeping. This is a
genuine simplification, not a parity gap — the JSON backend keeps its
rotation/archive behavior unchanged (it's backend-specific, not a shared
contract requirement).

### 3.7 `rate_limits` (reversed from the original exclusion)

**Why `.env` does not work here, stated plainly:** `.env` is static
configuration — read once (or per-request, but always from the same
unchanging file on disk) and never intended to be mutated at runtime. A rate
limiter's entire job is to mutate state on *every single API call* and read
it back milliseconds later for the *next* call from the same identifier.
Writing that to `.env` would mean rewriting a config file on every request —
no atomicity, guaranteed corruption under concurrent PHP-FPM workers, and
every deploy tool that watches `.env` for changes would misfire. `.env` is
the right place for the *limit thresholds* (`FLUXFILES_RATE_LIMIT_READ`/
`FLUXFILES_RATE_LIMIT_WRITE` — already env-driven today) but the wrong place
for the *per-identifier state*. So the real choice is DB vs. keep-file, not
DB vs. `.env`.

**Correction from an earlier draft of this section:** the first version of
this table described a *fixed-window counter* and claimed it "matches
current behavior exactly." That claim was wrong and has been replaced.
`RateLimiterFileStorage::check()` (`packages/core/api/RateLimiterFileStorage.php`)
is a **sliding-window log**: it stores every request's raw Unix timestamp in
an array keyed by `$userId . ':' . $actionType`, filters out entries older
than `now - windowSeconds` on every call, rejects once the *fresh* count
`>= limit`, otherwise appends `now`. A fixed-window counter (bucket time into
discrete `floor(now/window)` slots, increment an int) is a **different,
weaker algorithm** — it allows up to `2×limit` requests to cross a window
boundary (e.g. 10 requests at `t=59s` of one window, 10 more at `t=61s` of
the next, both windows individually under the limit). Shipping that as a
silent swap would be a real behavior regression on every rate-limited route,
including the pre-auth share/intake/SSO endpoints in `PublicLinks.php`. The
design below is the corrected, actually-equivalent sliding-log
implementation.

**What the call sites actually look like** (read directly, not assumed):
`RateLimiterFileStorage::check(string $identifier, string $actionType)`
resolves its limit as `$actionType === 'read' ? $readLimit : $writeLimit`,
where `$readLimit`/`$writeLimit` are fixed per *instance* (passed into the
constructor). The key stored is `$identifier . ':' . $actionType`. In
practice there are far more than "two buckets":
- `index.php` (authenticated): `read`/`write` (keyed by `$claims->userId`,
  per-tenant `rate_read`/`rate_write` claim overrides), plus two
  purpose-built instances with their own tighter limits — `import` (URL
  import, default 10/min) and `usage_refresh` (forced usage recompute, hard
  2/min).
- `PublicLinks.php` (pre-auth, no `Claims` object exists yet): every single
  call passes the literal string `'read'` as `$actionType` — `ff_share_rate_limit()`
  (`view`→`share:<jti>`, `unlock`→ two buckets `share_unlock:<jti>:<ip>` and
  `share_unlock_all:<jti>`), `ff_intake_rate_limit()` (mirrors share:
  `intake:<jti>`, `intake_upload:<jti>:<ip>`, `intake_upload_all:<jti>`), and
  `ff_sso_rate_limit()` (`sso_login:<ip>`, `sso_callback:<ip>`,
  `sso_exchange:<ip>`). **The real differentiation lives entirely in the
  `$identifier` string's prefix, not in `$actionType`/`bucket`** — this was
  understated in the first draft, which implied `bucket` was a meaningful
  small enum. It isn't; it's carried through unchanged from whatever the call
  site passes, exactly like today.
- The share/intake identifiers are built from the token's **unverified**
  JWT payload (`ff_share_token_jti()` — deliberately: it's only a bucket key,
  and tampering it invalidates the signature anyway, so it can't be used to
  escape the bucket with a working token, per the existing code comment).
  This matters for §11's security note below.

**Schema — a hits table, not a counter table** (required for exact
sliding-log parity):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK, autoincrement | |
| `identifier` | varchar(191) | Exactly the `$identifier` argument passed to `check()` today (already a compound string for the pre-auth flows, e.g. `share_unlock:<jti>:<ip>` — no reinterpretation, just stored as-is). |
| `bucket` | varchar(16) | Exactly the `$actionType` argument (`read`/`write`/`import`/`usage_refresh` — almost always literal `read` for pre-auth flows, per above). |
| `ts` | bigint (unix seconds) | One row per request, matching the file backend's one-timestamp-per-array-entry model. |

`INDEX (identifier, bucket, ts)` — every query filters on all three.

**Check algorithm (race-safe, per-key granularity — not a global lock):**
run inside one transaction per `check()` call:

```sql
BEGIN;
DELETE FROM rate_limits WHERE identifier = ? AND bucket = ? AND ts <= ?;      -- windowStart, self-prunes exactly like the JSON backend
SELECT COUNT(*) FROM rate_limits WHERE identifier = ? AND bucket = ? FOR UPDATE;  -- MySQL/Postgres: locks this key's rows so a concurrent check() for the SAME identifier+bucket blocks here, not a race
-- if count >= limit: ROLLBACK; throw the 429 (rate_limited), same as today
INSERT INTO rate_limits (identifier, bucket, ts) VALUES (?, ?, ?);
COMMIT;
```

This is deliberately **not** the "atomic upsert, then separate SELECT to
decide" pattern the first draft used — that ordering is a genuine TOCTOU
race (two concurrent requests can both read `count < limit` before either
commits, both proceed, and the limit is exceeded by up to N-1 requests under
N-way concurrency). Locking the row set for `(identifier, bucket)` *before*
counting closes that: `SELECT ... FOR UPDATE` (Postgres, MySQL/InnoDB) makes
a concurrent `check()` for the same key wait for the first transaction to
commit or roll back, so the second transaction's `DELETE`+`COUNT` always
sees the first one's effect. Different `(identifier, bucket)` keys never
contend with each other — strictly finer-grained than today's single
whole-file `flock()`, which serializes *every* identifier against every
other one. **SQLite** has no real row-level locking regardless of `FOR
UPDATE` syntax — a `BEGIN IMMEDIATE` transaction here gets SQLite's normal
single-writer exclusivity for the statement's duration, which is correct
(no race) and *no worse* than today's global file lock, just not
per-key-scoped the way MySQL/Postgres are.

**Cleanup beyond the per-check `DELETE`:** the per-check delete only prunes
rows for the *one* `(identifier, bucket)` key being checked right now, so an
identifier that stops being used (an abandoned share link, a one-off IP)
leaves its rows behind indefinitely — the file backend has the same
property (a key untouched since its last write is never revisited either,
so it also lingers until the *next* check of that exact key). A lazy,
1%-of-requests probabilistic global sweep (`DELETE FROM rate_limits WHERE ts
< ?` with no identifier filter, cheap given the `ts` index) piggybacked on
any `check()` call bounds this without a cron job. See §11 for why this
table's row-bloat risk is a materially different shape than the other
tables in this design.

**Interface:** a new `RateLimiterStorageInterface` (separate from
`MetadataRepositoryInterface` — `RateLimiterFileStorage` is a standalone
class with no relationship to `StorageMetadataHandler` today, and there is
no reason to entangle them) with one method, matching
`RateLimiterFileStorage`'s existing signature exactly:

```php
interface RateLimiterStorageInterface
{
    public function check(string $identifier, string $actionType): void; // throws ApiException(429, 'rate_limited')
}
```

`RateLimiterFileStorage` and the new `RateLimiterDbStorage` both implement
it, each taking `$limit`/`$writeLimit`/`$windowSeconds` in its own
**constructor**, not in `check()`. Verified directly against every real call
site (`index.php:359,374,381`; `PublicLinks.php:465,502,531`): all of them
already build a *fresh* `RateLimiterFileStorage($path, $limit, $limit, 60)`
per request — including `import` and `usage_refresh`, the two cases an
earlier draft of this section cited as needing a per-call limit — and then
call `->check($identifier, $actionType)` with **no** limit/window arguments
at all. That pattern is exactly what a 2-argument `check()` supports: the
call site already re-constructs the limiter with whatever threshold applies
to *this* request before calling `check()`, so `RateLimiterDbStorage($limit,
$writeLimit, $windowSeconds)` can be swapped in with the identical
call-site shape, one-for-one, with no widened interface needed.
>
> An earlier draft of this section proposed adding `$limit`/`$windowSeconds`
> as `check()` parameters instead, reasoning that "several call sites
> already build a differently-limited instance per request" — that's true,
> but it argues for the opposite conclusion: a differently-limited
> **instance** is already how every call site expresses a per-request
> limit, via the constructor. Widening `check()` itself would just duplicate
> that value in two places for no behavioral gain, and would make
> `RateLimiterDbStorage` deviate from `RateLimiterFileStorage`'s existing,
> already-adequate signature for no reason. Fixed above.

**Left as a deliberate v1 boundary, not solved here:** a pluggable
cache-backed limiter (APCu/Redis, sorted-set or `INCR`+`EXPIRE`, the
textbook fit for this exact workload) is real and would out-perform a hits
table at high QPS — but it introduces an external dependency this migration
doesn't otherwise need, so it's scoped out of v1 behind the same
`RateLimiterStorageInterface` seam for a later follow-up.

---

## 4. DB abstraction layer — core standalone

**No ORM.** Core's only hard runtime dependency today is `firebase/php-jwt`;
pulling in Doctrine/Eloquent-standalone for a package whose whole pitch is
"drop in, zero-build, minimal backend" would be a heavier addition than the
feature justifies. Plain **PDO** with a thin internal layer:

```
packages/core/api/Db/
  Connection.php          — lazy-connect PDO wrapper (DSN + user + pass from env)
  Dialect.php             — interface: upsert(), autoIncrementDdl(), jsonType(), boolLiteral(), quoteIdent()
  SqliteDialect.php
  MysqlDialect.php
  PgsqlDialect.php
  DbMetadataHandler.php   — implements MetadataRepositoryInterface (§2), built on Connection + Dialect
  MigrationRunner.php
  JsonToDbMigrator.php    — adapter-agnostic (§9), used by core CLI + Laravel artisan + WP-CLI
  MetadataExporter.php    — §7
  MetadataImporter.php    — §7
packages/core/db/migrations/
  0001_create_file_metadata.sql
  0002_create_directories.sql
  0003_create_trash.sql
  0004_create_audit_log.sql
```

**Why a `Dialect` strategy, not per-engine branching inside
`DbMetadataHandler`:** the three engines differ in a handful of specific
places — upsert syntax (`INSERT ... ON CONFLICT DO UPDATE` / `INSERT ... ON
DUPLICATE KEY UPDATE` / SQLite's own `ON CONFLICT`), autoincrement DDL
syntax, and (marginally) JSON column typing. Isolating those differences
behind one small interface keeps `DbMetadataHandler`'s actual query logic
(which is 95% identical across engines) from forking into three parallel
implementations that could silently drift.

**Migration runner:** a `_fluxfiles_migrations` tracking table
(`filename varchar(255) PK, applied_at bigint`) records which numbered
migration files have run. `MigrationRunner::migrate(Connection $db, string
$migrationsDir)` applies pending files in filename order. SQLite/Postgres
wrap each file in a transaction; MySQL DDL auto-commits (the runner tolerates
that — it records the tracking row immediately after each file so a run
interrupted mid-way is safely resumable, it just can't roll back a single
partially-applied MySQL DDL statement, which is a MySQL limitation, not a
runner bug).

**Not auto-run in production request path.** `FLUXFILES_DB_AUTO_MIGRATE`
(default `false`) gates whether `index.php`'s boot calls
`MigrationRunner::migrate()` automatically — convenient for local dev, wrong
for production (nobody wants a DDL statement firing on a random request under
load). Production runs `php scripts/fluxfiles-migrate.php` explicitly during
deploy.

**Connection lifecycle:** one PDO connection per request, lazily created on
first query — so choosing `backend=json` never opens a DB handle, and the
`db` backend pays one connection cost per request (acceptable; this mirrors
how every PHP-per-request framework already works, no pooling needed for a
stateless request model).

---

## 5. Laravel integration

> **Implemented** (config/migration/handler/controller-wiring/composer floor
> + tests). The `fluxfiles:migrate-json-to-db` command below is **deferred**
> until §9's `\FluxFiles\Db\JsonToDbMigrator` actually exists — everything
> else in this section shipped as described. `LaravelDbMetadataHandler`'s
> constructor takes `DiskManager` as its first argument
> (`__construct(DiskManager $diskManager, ?string $connection = null)`), not
> shown in the §5 code snippet below, because `countChildren()` walks live
> Flysystem storage rather than querying the DB (same as core's handler) and
> needs a disk to walk.

- `packages/laravel/config/fluxfiles.php` gains:
  ```php
  'storage_backend'  => env('FLUXFILES_STORAGE_BACKEND', 'json'),
  'db_connection'    => env('FLUXFILES_DB_CONNECTION', null), // null = Laravel's default connection
  ```
  `db_connection` lets the operator point FluxFiles' tables at a **separate**
  DB/credentials from their own app tables (defined as a named connection in
  `config/database.php`) — not required, but a reasonable operator want.

- **Native Laravel migrations**, not core's hand-rolled runner — this is the
  natural fit since a Laravel app already has migrations/`artisan
  migrate`/`RefreshDatabase` test tooling:
  `packages/laravel/database/migrations/2026_XX_XX_create_fluxfiles_tables.php`,
  published via `php artisan vendor:publish --tag=fluxfiles-migrations`, using
  the Schema Builder. Table names are **`fluxfiles_`-prefixed**
  (`fluxfiles_file_metadata`, `fluxfiles_directories`, `fluxfiles_trash`,
  `fluxfiles_audit_log`) to avoid any collision with the host app's own
  tables — same prefixing discipline WordPress uses (§6).

- **New class, not core's `DbMetadataHandler`:**
  `packages/laravel/src/LaravelDbMetadataHandler.php implements
  MetadataRepositoryInterface`, built on Laravel's Query Builder
  (`DB::connection($name)->table('fluxfiles_file_metadata')`). Reusing core's
  raw-PDO handler here would fight the framework — Laravel's connection
  pooling, transactions, and test database swapping (`RefreshDatabase`,
  in-memory SQLite for tests) are exactly what an Eloquent/Query-Builder-based
  handler gets for free, and what re-wrapping a separate PDO connection would
  throw away.

- `FluxFilesController::__construct()`'s `$this->metaRepo` becomes
  `MetadataRepositoryInterface`-typed (per §2), chosen once:
  ```php
  $this->metaRepo = config('fluxfiles.storage_backend') === 'db'
      ? new LaravelDbMetadataHandler(config('fluxfiles.db_connection'))
      : new StorageMetadataHandler($this->diskManager);
  ```

- `php artisan fluxfiles:migrate-json-to-db {--dry-run} {--verify} {--disk=} {--prefix=}`
  wraps the SAME adapter-agnostic `\FluxFiles\Db\JsonToDbMigrator` class core's
  CLI script uses (§9) — it just constructs a `LaravelDbMetadataHandler` as
  the destination instead of core's own `DbMetadataHandler`.

- **Composer floor:** `packages/laravel/composer.json`'s `fluxfiles/fluxfiles`
  constraint must be raised to the first core tag that ships the widened
  `MetadataRepositoryInterface` (§2) — the adapter's controller now
  implements that interface directly, so an older core with the narrower
  interface would be a real (if currently silent, since PHP doesn't error on
  an interface implementing "extra" methods not required by an older core)
  mismatch worth pinning correctly per CLAUDE.md's adapter↔core floor rule.

---

## 6. WordPress integration

> **Implemented** (schema/admin-setting/handler/API-wiring + tests). The
> `wp fluxfiles migrate-json-to-db` command below is **deferred** until §9's
> `\FluxFiles\Db\JsonToDbMigrator` actually exists — everything else in this
> section shipped as described. `WpDbMetadataHandler`'s constructor takes only
> `DiskManager` (`__construct(DiskManager $diskManager)`, no connection-name
> parameter, unlike Laravel's `LaravelDbMetadataHandler`) since WordPress
> always reuses the single `$wpdb` connection — there is no per-tenant
> connection override to thread through.

- `FluxFilesPlugin::activate()` (already hooked via
  `register_activation_hook`) gains a call to a new
  `FluxFilesDbSchema::install(\wpdb $wpdb)` that runs `dbDelta()` against
  `{$wpdb->prefix}fluxfiles_file_metadata` / `_directories` / `_trash` /
  `_audit_log` — respecting the site's own table prefix (multisite: each site
  in a network gets its **own** tables via its own `$wpdb->prefix`, matching
  how WordPress's own core tables are isolated per site; a network-wide
  shared-FluxFiles-DB mode is explicitly **out of scope**, §14.3).
  `dbDelta()` is idempotent and diff-aware by design — it **is** the WP
  migration runner, so no separate `_fluxfiles_migrations` tracking table is
  needed on this adapter (unlike core standalone/Laravel).

- New setting under **Settings → FluxFiles**: "Storage backend" (dropdown:
  JSON files / Database), stored as the `fluxfiles_storage_backend` option
  (default `json`). When `db`, `FluxFilesApi`'s `$this->metaRepo` becomes a
  new `WpDbMetadataHandler implements MetadataRepositoryInterface`
  (`packages/wordpress/includes/WpDbMetadataHandler.php`) built on `$wpdb`
  directly (`$wpdb->prepare()` + `$wpdb->get_results()`/`get_row()` — no
  Eloquent available in a bare WP plugin).

- **No separate DSN for WordPress.** The DB-backed handler always reuses
  WordPress's own database connection (`$wpdb`) — simplest possible operator
  experience (zero new credentials to configure), at the cost of not
  supporting an isolated DB for FluxFiles tables on WP specifically. Flagged
  as an explicit v1 non-goal (§14.3), not an oversight.

- `wp fluxfiles migrate-json-to-db --dry-run --verify --disk=<name>
  --prefix=<p>` (registered via `WP_CLI::add_command` when `WP_CLI` is
  defined) — same `\FluxFiles\Db\JsonToDbMigrator` core class, constructing a
  `WpDbMetadataHandler` as the destination.

- **Composer floor:** same reasoning as Laravel (§5) — `packages/wordpress/
  composer.json`'s core floor bump.

---

## 7. Export / Import tooling (S3-metadata mitigation, part A)

**Free/core, not gated by any module** — this is data-portability safety
net, not a premium feature; withholding it from the free tier would be
actively hostile to the operators most exposed to the risk it mitigates.

- `GET /api/fm/metadata/export?disk=&prefix=&format=ndjson|csv` (operator
  auth, needs `read` permission, respects the caller's own `pathPrefix`/
  `owner_only` scope exactly like every other route — **not** admin-only like
  `/audit/purge`, since this is non-destructive and self-service; see the
  open question in §14.2). Streams every `file_metadata` row under
  `disk`(+optional `prefix`, further narrowed by the caller's own scope) as
  NDJSON (one JSON object per line, matching the audit-export convention
  already established) or CSV:
  ```json
  {"disk":"s3","path":"users/42/photo.jpg","title":"…","alt_text":"…","caption":"…","tags":"…","mime":"image/jpeg","size":184320,"width":1600,"height":1200,"file_hash":"…","owner":"user-42","created_at":1755600000,"modified_at":1755600100,"object_uuid":"3f9a1c2b-…"}
  ```

- `POST /api/fm/metadata/import` — JSON body `{disk, entries: [...]}` (same
  row shape as export). **All-or-nothing per request, inside one DB
  transaction** — unlike `extract`'s atomic-but-file-oriented semantics, a
  *partial* metadata import (some rows applied, some silently rejected)
  would leave a tenant's search/tags in an inconsistent, hard-to-notice state,
  which is worse than a clean rollback-and-retry-with-fixed-input. Every
  row's `path` is validated against `Claims::isPathInScope()` before any
  write; one out-of-scope or malformed row aborts the whole batch with a
  per-row error list (`{row: 12, error: "path_out_of_scope"}`), never a
  partial commit.

- CLI mirrors: `php scripts/export-metadata.php --disk=local
  --prefix=users/42 --out=backup.ndjson` /
  `php scripts/import-metadata.php --disk=local --in=backup.ndjson`, both
  thin wrappers over the same `MetadataExporter`/`MetadataImporter` classes
  the HTTP routes call — one implementation, two entry points, matching the
  pattern §9's migrator already uses.

This tool's format is the **full DB row shape**, not a reconstruction of the
old S3-object-metadata shape — it is a genuine backup/restore tool for the DB
backend, independent of which cloud-native tool an operator might also be
using on the raw bucket.

---

## 8. S3/R2 breadcrumb (mitigation, part B)

**The risk being mitigated:** the one real advantage of the old design —
metadata embedded directly in the S3 object survives `aws s3 sync`, CRR, or
any third-party backup tool with zero FluxFiles-aware coordination — is lost
the moment metadata moves fully into a DB the raw tool has never heard of. An
operator who migrates/backs up via raw S3 tooling without also
migrating/restoring the DB silently loses all title/alt/caption/tags/hash
data (the files themselves are fine — only the bookkeeping is orphaned).

**Mitigation, two parts** (export/import above is part A; this is part B):

- On an S3/R2 disk, the **first** time a file is saved under `backend=db`
  (first upload, or first metadata write for a pre-existing file), write a
  single new object-metadata key: **`x-amz-meta-fluxfiles-id`** = a freshly
  generated UUIDv4, via the same `CopyObject` mechanism already used today —
  but **only this one small breadcrumb**, never the full title/alt/caption/
  tags. The UUID is also stored in `file_metadata.object_uuid` (§3.1),
  immutable thereafter.
- **Cost is strictly lower than today's design**, not an addition: today
  every metadata *edit* costs a `CopyObject` (full metadata replace); the
  breadcrumb costs one `CopyObject` **once per file's lifetime** (first
  write only — the UUID never changes, so there's nothing to re-copy on
  subsequent edits).
- Gated by `FLUXFILES_DB_S3_BREADCRUMB` (default `true`, §10) — an operator
  who wants zero extra `CopyObject` calls and accepts the full raw-tooling
  migration risk can turn it off.
- **Repair flow:** `php scripts/repair-s3-metadata.php --disk=r2 [--apply]`
  works **without** the DB at all (must, since the DB might be exactly
  what's lost/stale): it runs a raw `ListObjectsV2` + `HeadObject` per object
  to read `x-amz-meta-fluxfiles-id`, cross-references against
  `file_metadata.object_uuid`, and reports:
  ```json
  { "orphaned_objects": [...], "orphaned_rows": [...], "moved": [{"uuid":"…","old_path":"…","new_path":"…"}] }
  ```
  Without `--apply` this is read-only (a report for the operator to review).
  `--apply` re-points `file_metadata.path` for any row whose UUID positively
  matches an object now living at a different key (i.e. it was `mv`'d by a
  raw tool outside FluxFiles) — it never guesses; an object whose breadcrumb
  is missing or unmatched is left alone and reported as orphaned, not
  auto-adopted.
- **Honest limits, stated up front:** cross-region replication and most
  sync tools **do** preserve custom `x-amz-meta-*` keys by default, so the
  common case (CRR, `aws s3 sync`, most backup tools) is covered. A tool that
  deliberately strips object metadata on copy (some CDN cache-warming
  re-uploads, some third-party migration tools with a "clean copy" mode) is
  **not** — this is a best-effort recovery aid, not a guarantee.
- **No equivalent needed for Local/SFTP.** Object metadata is an S3/R2-only
  concept; the raw-tooling risk there is `rsync`/`cp`, which preserves paths
  1:1 — there's no rename/move ambiguity to repair, only a simple "does the
  path in the DB still exist on disk" check, which is a much smaller problem
  and not designed here (it falls out of `--verify`, §9).

---

## 9. Migration script (`json` → `db`)

```
php scripts/migrate-json-to-db.php --disk=local [--prefix=] [--dry-run] [--verify] [--yes]
```

**Adapter-agnostic core service:** `\FluxFiles\Db\JsonToDbMigrator`
(`packages/core/api/Db/JsonToDbMigrator.php`), constructed with a
`DiskManager`, a source `StorageMetadataHandler` (**always** the JSON
handler, reading `_fluxfiles/*.json`/`*.jsonl` directly, regardless of what
the *live* backend setting currently is), and a destination
`MetadataRepositoryInterface` (whatever handler the caller wants — core's own
`DbMetadataHandler`, Laravel's `LaravelDbMetadataHandler`, or WordPress's
`WpDbMetadataHandler`). This is why Laravel's artisan command and WP's WP-CLI
command exist at all (per the task's decision 6) — they can't shell out to
run `php scripts/migrate-json-to-db.php` conveniently inside their own
deploy model, so they construct their own destination handler and hand it to
this **same** class instead of reimplementing the migration logic.

**Steps** (all read-only against source JSON, all idempotent upserts against
the destination):
1. `_fluxfiles/index.json` → upsert `file_metadata` (keyed by `disk` +
   `path_hash`; compares `modified_at` so a re-run only updates rows the JSON
   side actually changed since).
2. `_fluxfiles/dirs.json` → upsert `directories`.
3. `_fluxfiles/trash.json` → upsert `trash`.
4. `_fluxfiles/audit.jsonl` + every `_fluxfiles/audit/archive/*.jsonl` → bulk
   insert into `audit_log`, deduped by a content hash of
   `sha256(ts . action . json_encode(context))` (audit entries have no
   natural id in the JSON world, so this hash is the idempotency key — a
   re-run recomputes the same hash for the same line and skips it).
5. Local-disk metadata sidecars (`_fluxfiles/meta/**/*.json`) not already
   reflected in `index.json` — defensive fallback only; `save()` always
   updates the index today, so this should normally find nothing, but it's
   cheap insurance against a hand-edited or historically-drifted install.
6. **`rate_limit.json` is deliberately NOT migrated — explicit decision, not
   an oversight.** Every row it could produce is a raw request timestamp
   inside a ≤60s sliding window (§3.7); by the time an operator runs this
   script (a one-time offline cutover step, not a live hot-swap), every
   entry has either already expired or is seconds from expiring, so
   "migrating" it would carry over at most a few stale, about-to-be-pruned
   rows for zero practical benefit. The DB backend simply starts every
   identifier's counter at zero on first use after cutover — worst case, a
   handful of callers each get one extra full window's worth of allowance
   immediately after the switch, which is a one-time, self-correcting,
   inherently low-stakes effect (this is a rate *limiter*, not a ledger).
   `rate_limit.json` itself is left untouched on disk (harmless — nothing
   reads it once the backend switches) rather than deleted by the script.

**`--dry-run`:** performs steps 1–5 read-only (step 6 is a no-op by design,
per above), prints `{would_insert, would_update, would_skip}` per table,
writes nothing to the destination.

**`--verify`:** re-reads both sides and diffs them — key-set difference plus
field-by-field comparison on the intersection — reporting any mismatch. Used
both immediately after a real migration run **and** as a standing drift
check later, since decision 6 explicitly keeps the JSON files around after
cutover (an operator who somehow still writes to the JSON side, or ran the
migration once and made more JSON-side edits before actually flipping
`FLUXFILES_STORAGE_BACKEND`, gets an explicit signal instead of silent
staleness).

**Idempotency:** every upsert is keyed by `(disk, path_hash)` /
`(disk, id)` / the audit content hash — safe to run the whole script any
number of times.

**Cutover flow:**
1. `--dry-run` to preview counts.
2. Run for real — writes the DB, **never touches the source JSON**.
3. `--verify` to confirm parity.
4. Operator flips `FLUXFILES_STORAGE_BACKEND=db` (core `.env`) / the Laravel
   config value / the WP admin setting — a **separate, explicit config
   change**, deployed independently of the migration run, so a bad migration
   can never auto-activate itself.
5. **Only after** a burn-in period confirming the app behaves correctly on
   `db` does the operator *optionally* delete the source JSON files — a
   manual, undocumented-as-default step. The migrator **never deletes
   anything**, ever, regardless of flags.

**Rollback story:** flip `FLUXFILES_STORAGE_BACKEND` back to `json`. Since
the migrator never mutated or deleted the source JSON, this is a same-second,
zero-data-loss rollback for everything written **before** cutover. The one
hard boundary, called out loudly in the upgrade guide (§13): any
writes/edits/uploads that happened **while** `backend=db` was live are **not**
reflected back into the JSON files — rolling back after real production usage
on `db` loses those specific changes. Rollback is safe only during the
pre-production burn-in window, not as a general "undo" after real traffic.

---

## 10. `docs/CONFIG.md` additions

**JWT claims: none.** Storage backend selection is server-wide, not
per-tenant — same reasoning as `FLUXFILES_STORAGE_PATH` never being a claim.
Stated explicitly (per the doc's own established convention of calling out a
"no new claims" outcome rather than silently omitting the section) so no one
wonders whether this was missed. `tests/unit/test-config-doc.php` needs no
changes.

**New server env vars — add to CONFIG.md §3:**

| Env var | Default | Notes |
|---|---|---|
| `FLUXFILES_STORAGE_BACKEND` | `json` | `json` (unchanged default behavior) or `db` — selects whether metadata/search/folder-index/audit/trash/rate-limits live in `_fluxfiles/*.json` (incl. `rate_limit.json`) or the configured database; one switch drives both `MetadataRepositoryInterface` and `RateLimiterStorageInterface` (§3.7). Quota **enforcement** always uses a live storage scan regardless of this setting (§3.3). |
| `FLUXFILES_DB_DSN` | — | PDO DSN for the `db` backend, e.g. `sqlite:/var/www/fluxfiles/storage/fluxfiles.sqlite3`, `mysql:host=127.0.0.1;dbname=fluxfiles;charset=utf8mb4`, `pgsql:host=127.0.0.1;dbname=fluxfiles`. Required when `FLUXFILES_STORAGE_BACKEND=db` (core standalone only — Laravel/WordPress use their own framework connection instead, see below). |
| `FLUXFILES_DB_USER` / `FLUXFILES_DB_PASSWORD` | — | Credentials for `FLUXFILES_DB_DSN` (ignored for SQLite). |
| `FLUXFILES_DB_AUTO_MIGRATE` | `false` | `true` runs pending core migrations automatically on boot (dev convenience only). Production should run `php scripts/fluxfiles-migrate.php` explicitly during deploy. |
| `FLUXFILES_DB_S3_BREADCRUMB` | `true` | On an S3/R2 disk with `backend=db`, write the `x-amz-meta-fluxfiles-id` breadcrumb (§8) on first save. `false` skips the extra `CopyObject` entirely, accepting the full raw-tooling migration risk. |

Laravel surfaces `storage_backend`/`db_connection` through
`config/fluxfiles.php` (§5) rather than duplicate env-var rows in CONFIG.md's
shared table — `FLUXFILES_STORAGE_BACKEND` is still the underlying env var
name Laravel's `env()` call reads, consistent with how `FLUXFILES_AI_PROVIDER`
etc. already work today. Laravel does **not** use `FLUXFILES_DB_DSN` — it
reuses Laravel's own `config/database.php` connection (§5).

WordPress surfaces the same choice as a Settings → FluxFiles dropdown
(`fluxfiles_storage_backend` option, §6) and never uses `FLUXFILES_DB_DSN`
either — it always reuses `$wpdb`.

---

## 11. Security review checklist

1. **Tenant scoping is the #1 review item.** Every DB query must filter by
   `disk` and, per the calling `Claims`, `owner` (when `owner_only`) and path
   prefix — exactly the same invariants `FileManager` enforces today. The
   repository layer (`DbMetadataHandler`/`LaravelDbMetadataHandler`/
   `WpDbMetadataHandler`) must **never independently derive scope from
   request input** — it only ever receives an already-scoped `$disk`/`$key`/
   `$pathPrefix`/`$ownerUserId` from `FileManager`, and must apply every one
   of those parameters as a SQL predicate. This is the same trust
   relationship the JSON handler already has with `FileManager` — what
   changes is the failure mode: a bug here used to mean "wrong file path"
   (loud, usually a 404), and now can mean "a forgotten `WHERE owner = ?`
   returns another tenant's rows" (silent, a real data leak). Treat every new
   query as security-relevant, not just the write paths.
2. **`search()`/`getBulk()`/`findByHash()` must filter in SQL, not
   post-filter in PHP.** Fetching an unscoped result set and filtering it in
   application code after the fact is both a performance regression (defeats
   the whole point of an indexed query) and a bug risk (an early `return`,
   a missed `continue`, or a refactor that reorders the filter after a
   `LIMIT` reintroduces the leak). The scoping predicates belong in the
   `WHERE` clause.
3. **100% parameterized queries.** No string concatenation of `path`, `tags`,
   or the search query term into SQL — ever, including inside `LIKE`
   patterns. Escape `%`/`_` in the **bound value**, not the query string,
   before using it in a `LIKE` pattern (a literal `%` in a filename or tag is
   legitimate user data, not an attack — it must not become a wildcard, but
   it also must never be used to build a query string).
4. **NUL-byte / control-character defense in depth.** `Claims::scopePath()`
   already strips `\0` from paths before FileManager ever sees them; the
   repository layer re-validates at its own boundary too (belt-and-suspenders,
   matching the codebase's existing style — see `Claims::scopePath`'s own
   comments).
5. **`owner` values are exact-match, never normalized/case-folded.** They
   must equal `Claims::$userId` (the JWT `sub`) byte-for-byte — no
   lowercasing, trimming beyond what `Claims` itself already does, or
   collation-based equality that could conflate two distinct tenant ids on a
   case-insensitive collation (a real MySQL footgun: `utf8mb4_general_ci`
   would treat `User-42` and `user-42` as equal in a `WHERE owner = ?`).
   Use a case-sensitive/binary collation for the `owner` (and `disk`, `path`)
   columns explicitly in the migration DDL.
6. **`disk` is always a required filter**, never optional/omittable, even
   though a shared DB *could* technically span multiple disks in one query —
   there is no legitimate caller today that wants results across disks in a
   single query, so the schema/queries should make that structurally
   impossible rather than merely "not currently used."
7. **Export/import (§7) apply the same per-row scope check as every other
   route.** A scoped token exporting "its own" metadata must not be able to
   round-trip a `path` outside its `pathPrefix` through the NDJSON it later
   imports — `Claims::isPathInScope()` is checked on both export (only rows
   the caller could already see are emitted — this falls out of using the
   same scoped query FileManager already builds) and import (every row's
   `path` re-validated before any write).
8. **DB credentials are server env only.** `FLUXFILES_DB_DSN`/`_USER`/
   `_PASSWORD` are never logged, never returned by `/api/fm/license` or any
   diagnostic route — same "never print secrets" rule as everything else in
   `.env`.
9. **No SSRF concern.** Unlike BYOB disk configs or `webhook_url`, the DB DSN
   is always operator `.env`/framework config, never derived from a JWT claim
   or any user input — same trust tier as `FLUXFILES_AIVISION_ENDPOINT`/
   `FLUXFILES_SSO_OIDC_ISSUER`. No `SsrfGuard` involvement needed.
10. **New DoS surface: unindexed search on a huge table.** A `LIKE
    '%term%'` (leading wildcard) search can't use a B-tree index at all —
    fine at JSON-file scale (a few thousand rows scanned in memory was
    already the status quo), but on a very large `file_metadata` table this
    is a slow-query risk that didn't meaningfully exist before. Mitigate for
    v1 by always pushing `LIMIT` into the SQL (already true — `search()`'s
    `$limit` parameter) and documenting that tenants north of ~100k files
    per disk should plan for DB-native full-text search (SQLite FTS5 /
    Postgres `tsvector` / MySQL `FULLTEXT`) as a v2 follow-up (§14.1) — not
    required for v1 behavioral parity, but worth flagging now so it isn't a
    surprise later.
11. **`rate_limits` (§3.7) is a different risk shape than every other table
    above, and the checklist items 1–10 don't automatically cover it.** It
    has no `disk`/`owner` concept — items 1, 2, and 6 don't apply to it at
    all. What does apply:
    - `identifier` is, for every pre-auth route (`share_unlock:*`,
      `intake_upload:*`, `sso_*`), a string an **anonymous, unauthenticated
      caller directly controls the shape of** — it's built from a token's
      *unverified* JWT payload (`jti`) plus `REMOTE_ADDR`, both attacker-
      influenced. Today's JSON file self-prunes every key on every write
      regardless of how many distinct keys have ever existed, so an attacker
      minting thousands of fake `jti`s costs the *file* nothing extra beyond
      the pruning work already happening. A DB `hits` table with a real
      B-tree index is more susceptible to sustained row growth from that
      same behavior — this is a genuinely new risk category the other
      tables don't have (their keys are `disk`+`path`, not attacker-chosen
      free text). The 1%-probabilistic sweep (§3.7) is the mitigation;
      pressure-test it under a synthetic high-cardinality-identifier flood
      before shipping, don't just assume it's adequate.
    - Item 3 (parameterized queries) and item 8 (credentials never logged)
      still apply unchanged.
    - The `check-then-lock-then-count` transaction (§3.7) must actually use
      `SELECT ... FOR UPDATE` (or SQLite's `BEGIN IMMEDIATE`) — a reviewer
      should specifically verify the implementation didn't quietly drop the
      lock/transaction wrapping under refactoring pressure, since the failure
      mode (a re-introduced TOCTOU race) is silent: it only shows up as an
      exceeded rate limit under real concurrent load, never as a test failure
      in a single-threaded test run.

---

## 12. Testing plan

- **Contract tests, run against every backend:** a shared
  `MetadataRepositoryContractTest` (or equivalent PHP test harness) exercises
  get/save/delete/search/trash/audit/dedup against *any*
  `MetadataRepositoryInterface` implementation, asserting identical
  observable behavior. Run it against:
  - `StorageMetadataHandler` (JSON) — proves the contract itself is accurate
    to current behavior (a regression guard for §2's interface widening).
  - `DbMetadataHandler` on **SQLite** — `packages/core/tests/unit/
    test-db-metadata-sqlite.php`, always runs in CI (filesystem-only, no
    external service, same tier as today's fast unit tests).
  - `DbMetadataHandler` on **MySQL** / **Postgres** —
    `test-db-metadata-mysql.php` / `test-db-metadata-pgsql.php`, env-gated
    like `test-s3-live.php`. Add `mysql:8` and `postgres:16` service
    containers to `.github/workflows/test.yml`, following the same precedent
    the `atmoz/sftp` container set for `selfboot-e2e` (SFTP had no CI
    coverage until a service container was added for it — same move here for
    the two server-DB engines).
- **Differential test:** run the same operation sequence (upload → tag →
  search → move → trash → restore → delete → audit-read) against a JSON
  handler and a DB handler in the same test, asserting identical final
  observable state — the strongest regression guard that "swap the backend"
  is actually invisible to callers.
- **Migration script tests**
  (`packages/core/tests/unit/test-migrate-json-to-db.php`): seed a temp
  storage dir with representative `_fluxfiles/*.json`/`*.jsonl` fixtures →
  `--dry-run` (assert counts, assert zero DB writes) → run for real (assert
  DB rows match) → run again (assert idempotent — zero new rows) →
  `--verify` (assert clean diff) → hand-edit one JSON entry → `--verify`
  again (assert it now reports the specific drifted row).
- **Adapter smoke updates:** `packages/laravel/tests/test-*-smoke.php` and
  `packages/wordpress/tests/test-*-smoke.php` each get a `db`-backend
  variant alongside the existing `json`-backend smoke (Laravel: an
  in-memory SQLite connection; WordPress: the existing `$wpdb` stub extended
  to back the new tables) — cheap enough to run in the fast smoke tier, not
  deferred to e2e only.
- **e2e re-run, as a matrix leg, not a rewrite.** The point of this design is
  behavioral parity, so the *same* `tests/e2e/test-api.sh` +
  `*-http.php` suite that already exercises metadata/search/trash/audit
  routes should run **unmodified** a second time with
  `FLUXFILES_STORAGE_BACKEND=db` (SQLite) set — add a
  `storage-backend: [json, db]` matrix dimension to the existing CI job(s)
  rather than duplicating test files. A test that only passes on one backend
  and not the other is itself a parity bug worth catching this way.
- **`packages/core/tests/apps/` real-adapter e2e:** one pass each with
  Laravel and WordPress configured for `db` backend, to catch adapter-specific
  wiring (the config option / the activation hook / dbDelta) end-to-end, not
  only at the unit/smoke tier.
- **S3 breadcrumb + repair tool test:** a live-S3-gated test (mirrors
  `test-s3-live.php`'s env-gating pattern) — upload a file, capture the
  breadcrumb UUID, simulate a "raw tool" move (a plain `CopyObject`+
  `DeleteObject` sequence outside `FileManager::rename()`), run the repair
  tool, assert it detects the drift and (with `--apply`) correctly re-points
  `file_metadata.path`.
- **No change expected** to `tests/unit/test-config-doc.php` itself (§10: no
  new claims) — worth stating explicitly in the implementation PR description
  rather than silently having a test suite run untouched.
- **`RateLimiterStorageInterface` contract test
  (`packages/core/tests/unit/test-rate-limiter-db.php`), run against
  `RateLimiterFileStorage` and `RateLimiterDbStorage` (SQLite) both:**
  - **Sliding-window parity**, the specific regression this section's
    correction exists to prevent: N requests spread evenly across a window
    boundary must behave identically on both backends — assert the DB
    backend does **not** allow the `2×limit`-at-the-boundary burst a
    fixed-window implementation would (mock/advance time within the test
    rather than sleeping real seconds).
  - **Concurrency test**: fire `limit + 5` `check()` calls concurrently
    (real parallel processes/threads, not sequential) for the *same*
    identifier+bucket, assert exactly `limit` succeed and the rest throw
    `429 rate_limited` — this is the test that would catch a regressed
    TOCTOU race (§11, item 11) if the lock/transaction wrapping is ever
    accidentally dropped; a sequential test cannot exercise this.
  - **Cross-identifier independence**: concurrent `check()` calls for
    *different* identifiers must not block on each other (assert wall-clock
    time stays low) — proves the per-key locking claim in §3.7, not a global
    serialization.
  - **Cleanup sweep**: seed rows well past their window, run enough
    `check()` calls to statistically trigger the 1% sweep, assert stale rows
    are gone and live rows are untouched.

---

## 13. Rollout / versioning note

This is a large, clearly-flagged addition to core's storage layer, but **not**
a forced breaking change for existing installs — `FLUXFILES_STORAGE_BACKEND`
defaults to `json`, so every current self-hosted install sees **zero**
behavior change on upgrade unless the operator explicitly opts in. The
"breaking" part is narrower and more specific:

- **`MetadataRepositoryInterface` gains new required methods (§2).** Any
  third party who has implemented that interface directly (not just used
  `StorageMetadataHandler`) has a genuine BC break, even though no shipped,
  default behavior changes for anyone else. Call this out explicitly and
  prominently in `CHANGELOG.md` — "if you implement
  `MetadataRepositoryInterface` directly, you must add N new methods" — as
  its own bullet, not folded into a routine patch note.
- Given the size of the surface (a new `Db/` subsystem, three new adapter
  handlers, a migration script, an export/import route pair, a repair tool),
  this should ship as a single, deliberately visible core release — a
  version-number jump that *signals* "big feature" even though core isn't
  strictly semver and is versioned by tag per CLAUDE.md's convention, not by
  a major/minor contract.
- A dedicated upgrade guide (either a new `docs/UPGRADE-DB-BACKEND.md`, or a
  clearly-marked section appended to this doc later) should walk an operator
  through: (1) the precursor interface check for anyone with a custom
  `MetadataRepositoryInterface` implementer, (2) §9's dry-run → migrate →
  verify → cutover → (optional) JSON cleanup flow, (3) the rollback boundary
  called out in §9's last paragraph.
- **Three packages, three releases, floor bumps on both adapters.** Core
  ships the new tables/classes/routes; `packages/laravel` and
  `packages/wordpress` each ship their own migrations/handler/config once
  core is available. Per CLAUDE.md's adapter↔core floor rule, **both**
  adapters' `composer.json` floors must be raised to the first core tag
  whose `index.php`/`MetadataRepositoryInterface` actually contains the
  widened contract these adapters now implement against — this is exactly
  the class of mistake the existing `[[adapter-core-version-constraint]]`
  lesson (a Laravel `sanitizeVariants` 500) warns about, and it can't be
  CI-guarded automatically the way `scripts/check-adapter-core-floor.sh`
  guards other cases, since this is a same-repo, same-release-cycle change —
  check it by hand at release time.
- **No paid-module considerations at all.** Nothing added to
  `ModuleRegistry::$map`, no license gate, no `packages/pack-modules.php`
  build entry — this ships entirely as MIT-licensed core + the two
  already-open-source first-party adapters.
- **`RateLimiterStorageInterface` (§3.7) is a second, smaller BC-break
  surface, distinct from `MetadataRepositoryInterface`'s.** Anyone who
  constructs `RateLimiterFileStorage` directly (rather than going through
  whatever thin factory this design introduces) is unaffected — the class
  itself keeps working unchanged as the `json`-backend implementation. The
  break, if any, is only for a third party who type-hinted against
  `RateLimiterFileStorage` the concrete class somewhere expecting to receive
  it — same shape as the metadata BC note above, just a much smaller
  blast radius since this class has no adapters currently depending on it
  by name. Mention in `CHANGELOG.md`, one line, not a headline item.
- **Behavioral, not just structural, change under real concurrent load.**
  Everywhere else in this migration the risk is "did we preserve the same
  data," but the rate limiter additionally changes *how* concurrent requests
  are serialized (whole-file `flock()` today → per-`(identifier, bucket)`
  row locking on `db`, per §3.7). This is a strict concurrency improvement,
  not a regression, but it does mean a burst of concurrent requests from
  *different* users that used to be silently rate-limited by lock contention
  alone (an accidental side effect of the global file lock, never a
  documented guarantee) will no longer be. Worth one sentence in the upgrade
  guide so an operator who was unknowingly relying on that side effect isn't
  surprised.

---

## 14. Open questions

Everything the task's decisions already settled is treated as settled above
(not re-litigated). These are the genuinely open items:

1. **v2 search relevance/FTS engine.** LIKE-based search (unchanged from
   today, §11.10) is fine for v1 parity but won't scale indefinitely.
   Deciding the long-term direction (SQLite FTS5 / Postgres `tsvector` /
   accepting MySQL as LIKE-only forever) is worth doing before any tenant
   actually hits the wall, not after.
2. **Export/import route scope (§7):** designed here as normal per-tenant
   scope (any token with `read`, respecting its own `pathPrefix`), unlike
   `/audit/purge`'s admin-only/unscoped-token requirement. This is a
   judgment call (export/import is non-destructive and inherently
   self-service, unlike a cross-tenant-capable purge) — worth a second look
   before shipping, not a settled decision the way the task's numbered
   points are.
3. **WordPress: no separate DSN (§6).** Decided against for v1 in favor of
   always reusing `$wpdb` — revisit if a WP-hosting-platform customer
   specifically asks for an isolated FluxFiles DB on WP.
4. **Should `directories` (§3.2) get an `owner` column?** Today's folder
   index is disk-global, not owner-scoped, matching current `dirs.json`
   behavior exactly — this is preserved as-is here. Flagged only because a
   relational schema is more expensive to alter later than a JSON shape
   change would have been, in case owner-scoped folder visibility ever
   becomes a real ask.
5. **Long-term dialect surface.** Three engines (SQLite/MySQL/Postgres) is
   what the task specified for v1; whether MySQL support is worth its
   ongoing dialect-maintenance cost versus standardizing on SQLite+Postgres
   is a fair question for later — not blocking, not decided here.
6. ~~Does a DB backend change the authorization/permission model at all?~~
   **RESOLVED (2026-08-31), not by this migration:** authz stays exactly as
   it is today — 100% stateless. `Claims.php` decodes a self-contained,
   HMAC-signed JWT per request (perm/disk/path prefix/owner-only/quota/
   extension); no server-side table is ever consulted to decide "is this
   request allowed" — the token *is* the permission grant. `file_metadata`/
   `audit_log`/etc. are read only *after* a request is already authorized,
   never to decide authorization itself. This migration does not touch
   `Claims`/JWT verification at all.

   **Deferred as its own future spec (not started, not scoped, not decided
   whether to build):** a DB *could* later support a materially different,
   additional model on top of the JWT — e.g. a `permissions`/`acl` table so
   access can be revoked or changed instantly without reissuing tokens, or
   per-file/per-folder ACL entries shared with specific users beyond today's
   path-prefix scoping. That would be a capability-token →
   server-checked-ACL architecture change, not a storage-backend swap. It
   gets its own `planner`/`spec-writer` pass if/when the user wants to
   pursue it — explicitly out of scope here so it doesn't creep into this
   migration's implementation.

---

## Exact `docs/CONFIG.md` edits required

- **§2 (JWT claims): no changes.** No new claim names.
- **§3 (Server env vars): add** `FLUXFILES_STORAGE_BACKEND`,
  `FLUXFILES_DB_DSN`, `FLUXFILES_DB_USER` / `FLUXFILES_DB_PASSWORD`,
  `FLUXFILES_DB_AUTO_MIGRATE`, `FLUXFILES_DB_S3_BREADCRUMB` — exact rows in
  §10 above, ready to paste into the table.
- `tests/unit/test-config-doc.php`: no changes needed (it only checks
  `Claims.php`'s `$payload->` reads, and none are added).

## Status

**§2 (interface widening), §3/§3.7 (schema, incl. the DB-backed rate
limiter), §4 (core standalone DB abstraction layer), §5 (Laravel
integration), and §6 (WordPress integration) are implemented** — §2-§4 in
commits `707db11`/`cafcc84`, tagged `core-v0.2.79`; §5 as the Laravel
adapter's `db` storage-backend option (`LaravelDbMetadataHandler`, native
Schema Builder migration, `packages/laravel/tests/test-laravel-db-metadata.php`);
§6 as the WordPress plugin's `db` storage-backend option
(`FluxFilesDbSchema` + `dbDelta()`, the "Storage backend" admin setting,
`WpDbMetadataHandler`, `packages/wordpress/tests/test-wp-smoke.php`'s
spy-based unit coverage, and `packages/wordpress/tests/test-wp-db-metadata-mysql.php`'s
real-MySQL round-trip suite).

**§9 (`JsonToDbMigrator` + `migrate-json-to-db` commands) is implemented** —
`\FluxFiles\Db\JsonToDbMigrator` (core), plus the narrow
`\FluxFiles\Db\MigrationImportInterface` (`insertAuditEntries`,
`existingAuditContentHashes`, `insertDirectoriesPreservingTimestamp`)
implemented by all three DB handlers, backed by a new nullable
`content_hash CHAR(64)` + `UNIQUE(disk, content_hash)` column on the audit
table in all three packages (core migration `0006`, Laravel migration
`2026_09_02_000000_add_audit_log_content_hash`, WordPress
`FluxFilesDbSchema::VERSION` `1.1.0`). Exposed as `php
packages/core/scripts/migrate-json-to-db.php`, Laravel's
`php artisan fluxfiles:migrate-json-to-db`, and WordPress's
`wp fluxfiles migrate-json-to-db` — all three support `--disk`, `--prefix`
(file/folder/trash only — the audit log always migrates whole-disk),
`--dry-run`, `--verify`, and `--yes`. Covered by
`packages/core/tests/unit/test-migrate-json-to-db.php` plus wiring
assertions in the Laravel/WordPress smoke suites. Manually smoke-tested end
to end (dry-run → real → verify → re-run → abort path → missing-schema path)
against a real, large dev-local disk (693 files, 434 dirs, 48 trash entries)
with source-file byte-hashes confirmed unchanged throughout — this surfaced
and fixed a real idempotency gap: `migrateFileMetadata()`'s change detection
treated any source row with no `modified` field as changed on *every* run
(perpetual `update`, never `skip`), because the comparison required both a
source and destination timestamp to declare a match. Fixed to skip once
already migrated when the source has no `modified` to compare — `verify()`'s
full field-by-field diff (not timestamp-based) remains the actual drift
safety net either way, so this only affects `migrate()`'s bucket counts and
write volume on re-runs, never correctness.

> **Adapter↔core floor note — resolved.** `LaravelDbMetadataHandler` and
> `WpDbMetadataHandler` implement the core-defined `MigrationImportInterface`,
> which `core-v0.2.79` predates. `scripts/check-adapter-core-floor.sh` caught
> the stale `^0.2.79` floor in CI (a fatal "Interface not found" building
> core at that tag). Fixed by cutting `core-v0.2.80` at the commit that
> includes §3-§9 of this doc plus the ACL role-preset fix, and bumping both
> `packages/laravel/composer.json` and `packages/wordpress/composer.json` to
> `^0.2.80`.

**§7 (export/import tooling) is implemented** — `\FluxFiles\Db\MetadataExporter`
(generator-based `rows()` + format-agnostic `streamTo()`, never buffers a full
disk) and `\FluxFiles\Db\MetadataImporter` (two-pass: validates every entry's
`path` against the caller's scope *before* writing any row, then writes the
whole batch inside one `Connection::beginExclusive()`/`commit()` transaction —
true all-or-nothing), both in `packages/core/api/Db/`, working directly
against the SQL table rather than through `MetadataRepositoryInterface` since
the export row shape (incl. `object_uuid`) is DB-specific. Exposed as
`GET /api/fm/metadata/export?disk=&prefix=&format=ndjson|csv` (read perm,
respects the caller's own `pathPrefix`/`owner_only` — per-tenant, not
admin-only) and `POST /api/fm/metadata/import {disk, entries[]}` (write perm,
`422 metadata_import_rejected` with a per-row error list on any out-of-scope
entry), gated `501` when `FLUXFILES_STORAGE_BACKEND` isn't `db`. CLI mirrors
`scripts/export-metadata.php` / `scripts/import-metadata.php` are thin
wrappers over the same two classes — one implementation, two entry points,
matching §9's migrator. Covered by
`packages/core/tests/unit/test-metadata-export-import.php` (9 tests,
including an export→import round trip and a regression test proving an
earlier in-scope row is *not* written when a later row in the same batch
fails validation). New error codes (`metadata_export_unavailable`,
`metadata_import_unavailable`, `metadata_import_rejected`) are translated in
all 16 `lang/*.json` locales. Not ported into the Laravel/WordPress proxy
controllers: `MetadataExporter`/`MetadataImporter` work directly against
core's own `\FluxFiles\Db\Connection` SQL layer (raw table access, not
`MetadataRepositoryInterface`), but Laravel's and WordPress's own `db` backend
options (§5/§6) run on `LaravelDbMetadataHandler`/`WpDbMetadataHandler` over
each framework's own connection (Eloquent / `$wpdb`) instead of
`\FluxFiles\Db\Connection`. A proxy port would be a second,
framework-flavored export/import implementation, not a passthrough — no such
port exists yet. Both proxy smoke suites list `metadata/export`/
`metadata/import` under their intentionally-unproxied allowlist with this
same rationale.

**§8 (S3/R2 breadcrumb) is implemented** —
`DbMetadataHandler::maybeWriteS3Breadcrumb()` (`packages/core/api/Db/DbMetadataHandler.php`)
stamps a fresh UUIDv4 onto the raw S3/R2 object as `x-amz-meta-fluxfiles-id`
the first time a file is saved via `save()` or `indexFile()`, reusing the same
`CopyObject` mechanism the JSON backend's `saveToS3()` already uses for
metadata edits, refined to read-merge-write (`HeadObject` first, preserving
the object's existing `Metadata` and `ContentType`) so the breadcrumb write
can never silently wipe other object metadata. The UUID is persisted to
`file_metadata.object_uuid` (the column already existed from `0001`) and is
immutable thereafter — a row that already has a UUID short-circuits before
any S3 call, so the cost is one `CopyObject` per file's *lifetime*, strictly
less than the JSON backend's one-per-edit. Detects S3/R2 via the same
`driver === 's3'` check used everywhere else in the codebase; a non-S3 disk
(local/SFTP) is a no-op. The whole path is best-effort: any S3 failure
(network, permissions, missing object) is caught and swallowed, returning
`null`, so a breadcrumb write can never block or fail a metadata save.
Gated by `FLUXFILES_DB_S3_BREADCRUMB` (default `true`, documented in
`docs/CONFIG.md`'s server env vars table).

The repair side is `\FluxFiles\Db\S3MetadataRepairer`
(`packages/core/api/Db/S3MetadataRepairer.php`), split along a deliberate
testability seam: `dbRows()` (SQL-only, uuid⇒path from `file_metadata`),
`scanBucket()` (raw `ListObjectsV2` + `HeadObject`, no DB dependency — usable
even with the DB lost or stale), `reconcile()` (a pure function, zero I/O,
diffing the two maps into `moved`/`orphaned_objects`/`orphaned_rows`), and
`apply()` (re-points `path`/`path_hash` for every `moved` entry inside one
transaction, deleting any row already occupying the destination
`(disk, path_hash)` first — mirroring `renameChildren()`'s
delete-conflicting-destination-row convention, since `path_hash` carries a
`UNIQUE(disk, path_hash)` index). Exposed as
`php scripts/repair-s3-metadata.php --disk=<name> [--apply] [--yes]` — a
read-only report by default, `--apply` re-points only exact UUID matches,
never guessing. No equivalent tool exists (or is needed) for Local/SFTP,
since `rsync`/`cp` preserve paths 1:1. Covered by
`packages/core/tests/unit/test-s3-metadata-repairer.php` (9 tests: full
`reconcile()` coverage including the moved/orphaned-object/orphaned-row mix,
`dbRows()` scoped-by-disk filtering, and `apply()` including the
destination-collision regression case) plus four tests added to
`test-db-metadata-sqlite.php` proving the breadcrumb is a no-op on a
non-S3 disk and is never overwritten once set. `scanBucket()` itself needs a
live bucket and is intentionally left uncovered here, consistent with the
project's existing separately-gated `tests/e2e/test-s3-live.php` pattern.
