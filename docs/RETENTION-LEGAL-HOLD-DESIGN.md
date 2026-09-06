# Legal Hold — Design Spec

Status: **Design only, not yet implemented.** Paid capability, Enterprise-only
add-on (never a standalone SKU). Written using `docs/GIT-DEPLOY-SECURITY-REVIEW.md`
as the style/depth reference (explicit "solved" vs "documented, not solved"
framing) and `_fluxfiles/trash.json` / `_fluxfiles/audit.jsonl` as the storage
precedent (`packages/core/api/StorageMetadataHandler.php`).

---

## 0. Naming

- **Module id:** `legal-hold` (kebab-case, matches `audit-export`/`sso`/`c2pa`).
- **Class:** `\FluxFiles\LegalHold\LegalHoldModule implements ModuleInterface`,
  in a new gitignored private package `packages/legal-hold/` (own repo, own
  `vX.Y.Z` tags — not `core-v…`), registered in `ModuleRegistry::$map`.
- **Claim:** `allow_legal_hold` (bool, default `false`) — `LegalHoldModule::claim()`.
- **Permission bucket:** reuses the existing `audit` perm (see §2 for why —
  no new perm string).

**Why this sells only inside the Enterprise Compliance Bundle, never alone.**
This is a **commercial packaging decision, not a code-level linkage** — same
as `audit-export`+`sso` today. `ModuleRegistry::require('legal-hold', …)` is a
perfectly ordinary 3-layer gate; nothing in code checks "is `audit-export`
also licensed?" before allowing `legal-hold`. The bundling is enforced by
whoever issues the license (the `modules[]` array baked into the signed
license file / the Polar checkout product), documented in the gitignored
`docs/COMMERCIAL-STRATEGY.md` next to the existing "Enterprise Compliance
Bundle = audit-export + sso" note — this spec adds `legal-hold` (and, per the
task framing, `virus`+`c2pa`) to that same bundle definition. Rationale for
never selling it alone: a legal hold that isn't backed by an audit trail
(`audit-export`) and enterprise access control (`sso`) is a much weaker
defensibility story in litigation — the whole point of the feature is "we can
prove nobody could have deleted this," which is undercut if the surrounding
compliance tooling isn't also in place. This mirrors the project's own rule:
*"compliance sells as one bundle, never piecemeal."*

---

## 1. Problem & who pays

**Persona:** the Enterprise / regulated-industry operator who has already
bought (or is buying) the Compliance Bundle (audit-export + sso, extended
here with virus + c2pa per the task framing) — typically a legal/compliance
officer or IT admin at a company facing (or anticipating) litigation,
a regulatory records-retention obligation, or an internal investigation, who
needs to say with confidence: *"this specific file/folder could not have been
deleted between date X and date Y, by anyone, including our own admins."*

**Free or paid:** paid, Enterprise-only, bundled — never a standalone SKU
(per the explicit instruction and the project's compliance-bundle-only rule).
It is not sellable alone the way Share/Intake are (à la carte heroes) — a
legal hold has no value to an operator who hasn't already bought into the
compliance story.

**Why this doesn't fit "BYO-embed over build-and-sell":** there's no OSS
drop-in for "a JWT-scoped file manager's own delete/trash/purge/rename/move
endpoints refuse to execute against a specific path." This has to be built
into `FileManager` itself; it can't be delegated to a self-hosted third-party
tool the way terminal/PDF/office/e-sign are.

---

## 2. Architecture fit — stateless, storage-resident, and a key split

**No central DB.** A holds manifest, `_fluxfiles/holds.json`, follows the
exact same shape/locking pattern as `_fluxfiles/trash.json`: one JSON object,
id → entry, read/written under `StorageMetadataHandler::acquireIndexLock()`
(same lock file as the metadata index and trash — a legal hold is
infrequent, so sharing the index lock rather than adding a fourth lock file
is fine, unlike audit's own dedicated lock for its much higher write volume).

**The critical architectural decision — split enforcement from management.**

| | Where it lives | Gated by |
|---|---|---|
| **Enforcement** — "does an active hold block this delete/trash/rename/move/purge?" | **Free/core**, inside `FileManager` (`assertNoActiveHold()`) and `MetadataRepositoryInterface` (`holdBlocking()`/`holdCovering()`) | Nothing — no claim, no license, no module `class_exists` check. Always runs. |
| **Visibility** — "is this file on hold, and why?" (`/list` enrichment, `GET /hold/status`) | **Free/core** | `read` perm only (extended detail — reason/placed_by — needs `audit` perm too, for privacy, not licensing) |
| **Management** — placing a new hold, releasing one, bulk-listing all holds on a disk | **Paid module** (`LegalHoldModule`), called from `index.php` routes | `audit` perm + `ModuleRegistry::require('legal-hold', …)` (installed + licensed + `allow_legal_hold`) |

**Why enforcement must be free/core and license-independent.** If blocking a
delete required `ModuleRegistry::require('legal-hold', …)` to succeed, then a
lapsed license, an uninstalled module, or a claim quietly dropped from a
re-minted token would silently **stop enforcing an already-placed hold** —
the worst possible failure mode for a compliance feature, and a genuine
liability story ("the court-ordered hold stopped working because a SaaS
invoice lapsed"). So once a hold exists in `holds.json`, it blocks
`delete`/`trash`/`rename`/`move`/`cross-move`/`trash/purge`/`trash/empty`
**unconditionally**, the same way `readAuditArchive()`/`purgeAuditBefore()`
are pure storage primitives with "no Claims/license awareness" per their own
docblocks. Only *creating or releasing* a hold is paid-gated. This also means
Laravel/WordPress proxy parity for **enforcement** is automatic and requires
**zero proxy code changes** — see §7.

---

## 3. JWT claims

Add one claim to `docs/CONFIG.md` §2.13 (paid-module gates table):

| Claim | Type | Default | Module |
|---|---|---|---|
| `allow_legal_hold` | bool | `false` | Legal Hold (Enterprise). Gates `POST /api/fm/hold` (place) and `POST /api/fm/hold/release`, and `GET /api/fm/hold/list` (bulk enumeration). Does **not** gate enforcement (blocking delete/trash/rename/move/purge of an already-placed hold) or the free per-file status check (`GET /api/fm/hold/status`, `/list`'s `on_hold` field) — those run unconditionally so an existing hold keeps blocking deletes even if the license lapses or this claim is later omitted from a re-minted token. Requires the `audit` permission (no new perm bucket — see design doc §2). |

No other new claims. (`legal_hold_max_active` is deliberately **not** a
claim — see §5, it's a system-integrity cap, not a per-tenant business knob,
same reasoning as `versioning_max`'s hard cap of 100 vs. the per-tenant soft
default.)

New env var, `docs/CONFIG.md` §3:

| Env var | Default | Notes |
|---|---|---|
| `FLUXFILES_LEGAL_HOLD_MAX_ACTIVE` | `1000` | Hard cap on simultaneously **active** (non-released) holds per disk, to bound `holds.json` growth and the per-`list()` enforcement/enrichment cost. Placing a hold past the cap → `403 legal_hold_max_active`. Released holds don't count against it (see §9 open question on unbounded historical growth). |

Why reuse `audit` instead of a new perm bucket: `audit` is already this
codebase's "admin/compliance action" bucket (`admin`/`superadmin` role
presets grant `['read','write','delete','audit']`; `/audit/export` and
`/audit/purge` already gate on `hasPerm('audit')`). Inventing a dedicated
perm (e.g. `legal_hold`) would mean touching all four ACL role-preset
builders (`embed.php`, node, Laravel, WordPress — see
`docs/ACL-ROLE-PRESETS-DESIGN.md`) for a capability that, in practice, is
always going to be granted to the same admin/compliance persona that already
needs `audit` to review the trail a hold produces. **Trade-off accepted
explicitly:** an operator cannot mint a token that can place legal holds but
*not* see the audit log, or vice versa — they're bundled under one perm. If a
customer ever needs that separation, a dedicated `compliance` perm bucket is
the natural v2, not a workaround here.

---

## 4. Endpoints

### 4.1 Paid (gated: `audit` perm + `ModuleRegistry::require('legal-hold', …)`)

**`POST /api/fm/hold`** — place a hold.
Body: `{disk, path, reason}`. `reason` is **required**, 1–1000 chars after
trim (`400 legal_hold_reason_required` if empty) — this is the field that
makes the audit trail defensible ("why was this held").
- Path must be in the caller's own scope (`Claims::isPathInScope`), else
  `403 legal_hold_scope` — a prefix-scoped admin can only hold paths inside
  their own tenant, even though (§2) an *already-placed* hold then blocks
  everyone regardless of their own scope.
- Active-hold cap check (`countActiveHolds(disk) >= FLUXFILES_LEGAL_HOLD_MAX_ACTIVE`
  → `403 legal_hold_max_active`).
- If an **active** hold already exists at the exact same path → `409
  legal_hold_already_active` with the existing `hold_id` (no silent
  duplicate; an admin who wants a second, independent reason recorded should
  review/release the existing hold first — see §9 for the v2 alternative of
  supporting N concurrent holds per path).
- Response: `{data: {hold_id, disk, path, is_dir, reason, placed_by, placed_at}, error: null}`.

**`POST /api/fm/hold/release`** — release a hold.
Body: `{disk, hold_id, reason}`. Release `reason` is **also required** — "why
was this lifted" matters as much as why it was placed, for the same
defensibility story.
- `404 legal_hold_not_found` if the id doesn't exist; `409
  legal_hold_already_released` if it's already released.
- Scope check against the hold's *own* recorded path (same `isPathInScope`
  rule as placement) — a prefix-scoped admin can only release holds inside
  their own tenant.
- The entry is **not deleted**, only marked released (`released_at`,
  `released_by`, `release_reason`) — mirrors Intake's revoke-writes-a-
  tombstone pattern. A "was this file ever held, and when was it lifted"
  question must still be answerable long after release.
- Response: `{data: {released: true, hold_id}, error: null}`.

**`GET /api/fm/hold/list?disk=&include_released=0|1`** — bulk management view.
Filtered by `isPathInScope(entry.path)` (see §6 for the cross-tenant
visibility decision); default returns only active holds; `include_released=1`
adds released ones (history review). Sorted `placed_at` desc.
Response: `{data: [{hold_id, disk, path, is_dir, reason, placed_by, placed_at, released_at, released_by, release_reason}], error: null}`.

### 4.2 Free/core (no module gate — see §2)

**`GET /api/fm/hold/status?disk=&path=`** — single-path check, `read` perm.
Returns `{on_hold: bool, hold_id?, placed_at?, placed_by?}` always; adds
`reason` only when the caller has the `audit` perm (privacy — see §6). Uses
**ancestor-or-self** matching (`holdCovering()`, §5) — "is this exact item
covered by a hold on it or one of its ancestors."

**`GET /api/fm/list` enrichment** — every file/folder row gains `on_hold: bool`
unconditionally (so a plain user's UI can render *some* indicator without
learning why — see §6), and, only for callers with the `audit` perm,
`hold_id`/`hold_reason`/`hold_placed_by`/`hold_placed_at`. Computed via
`holdCovering()`. **Performance guard:** `list()` loads the disk's active
holds once per call (already how trash/audit visibility works) and skips the
whole per-item enrichment loop entirely when there are zero active holds on
the disk — the overwhelmingly common case for any tenant not actively using
this feature, so the cost is not paid by non-Enterprise operators at all.

### 4.3 Ordering note for `resolveAuditAction()`

`index.php`'s audit-action map does first-`strpos`-match-wins over the URI
(`/trash/purge` is listed before `/trash` for the same reason). Add, in this
order:
```
'/hold/release' => 'legal_hold_release',
'/hold'         => 'legal_hold_place',
```
And special-case the audit `detail` field (mirroring the existing
`terminal`/`git_deploy` special cases at `index.php` ~L441) to carry the
`reason` from the request body, since the generic "log the touched path"
default would otherwise leave the single most important compliance fact —
*why* — out of the audit trail:
```php
$auditDetail = ... existing cases ...
    : (in_array($auditAction, ['legal_hold_place', 'legal_hold_release'], true)
        ? (string) ($body['reason'] ?? '')
        : null);
```

### 4.4 Blocked-attempt auditing (new — mirrors the `virus_blocked` precedent)

The audit log normally only records **successful** writes. `virus_detected`
is the one existing exception (`index.php`'s catch block audits a *blocked*
upload as `virus_blocked`, because the security event a scanner exists to
produce would otherwise never be recorded). A blocked deletion attempt on a
held file is exactly the same shape of event and just as important for
litigation: *someone tried to delete this while it was under hold.* Add a
second exception in the same catch block: when an `ApiException` carries
`error_code === 'legal_hold_active'`, log `legal_hold_blocked` with the
disk/path and the blocking `hold_id` in `error_params`, even though the
request itself returned 403.

---

## 5. Storage layout

`_fluxfiles/holds.json`, one per disk, same shape/lock family as
`_fluxfiles/trash.json`:

```json
{
  "a1b2c3d4e5f60718": {
    "path": "contracts/acme-msa.pdf",
    "is_dir": false,
    "disk": "local",
    "reason": "Pending litigation — Acme Corp v. Operator, Case No. 26-CV-1234",
    "placed_by": "compliance-officer-7",
    "placed_at": 1798675200,
    "released_at": null,
    "released_by": null,
    "release_reason": null
  }
}
```

`hold_id` is `bin2hex(random_bytes(8))` (same shape/entropy as trash ids),
validated `^[A-Za-z0-9_-]+$` on every route that accepts one — trash ids get
this same validation because they're interpolated into a filesystem path;
hold ids aren't, but the same validation is kept anyway as cheap defense in
depth and to bound audit-log/error-param size.

**New `MetadataRepositoryInterface` methods** (implemented by both
`StorageMetadataHandler` — JSON — and `Db\DbMetadataHandler` — the
`FLUXFILES_STORAGE_BACKEND=db` mode; see §7 for why both are required, not
JSON-first-then-later):

```php
public function allHolds(string $disk): array;                 // id => entry
public function getHold(string $disk, string $id): ?array;
public function addHold(string $disk, string $id, array $entry): void;
public function releaseHold(string $disk, string $id, array $releaseInfo): void; // sets released_*, never removes the entry
public function countActiveHolds(string $disk): int;            // for the cap check

/** Ancestor-or-self only. "Is $scopedPath itself covered by a hold on it
 *  or one of its ancestor folders?" Used for status/list enrichment. */
public function holdCovering(string $disk, string $scopedPath): ?array;

/** Full bidirectional overlap: ancestor-or-self OR descendant. "Would an
 *  operation on $scopedPath touch anything under an active hold?" Used by
 *  FileManager's mutating-operation guard. */
public function holdBlocking(string $disk, string $scopedPath): ?array;
```

The overlap rule for both is a plain path-prefix comparison against every
**active** (`released_at === null`) entry: hold `H` and target `P` overlap
when `H.path === P`, or `H.path` is a prefix of `P` (`P` starts with
`H.path . '/'`), or (for `holdBlocking` only) `P` is a prefix of `H.path`.

**`FileManager` changes** (free/core): a new private guard,

```php
private function assertNoActiveHold(string $disk, string $scopedPath): void
{
    $hold = $this->meta->holdBlocking($disk, $scopedPath);
    if ($hold !== null) {
        $params = ['hold_id' => $hold['hold_id'] ?? null];
        if ($this->claims->hasPerm('audit')) {
            $params['reason'] = $hold['reason'] ?? null;
            $params['placed_by'] = $hold['placed_by'] ?? null;
            $params['placed_at'] = $hold['placed_at'] ?? null;
        }
        throw new ApiException('This item is under legal hold and cannot be modified', 403, 'legal_hold_active', $params);
    }
}
```

called at the top of `delete()`, `trash()` (and its `trashDirectory()`
helper), `rename()`, `move()`, `crossMove()` (source-side path only —
moving a file **into** an existing hold's subtree is fine, see §6), and
`purgeTrash()` (checked against the trash entry's stored `original_key`, not
the internal `_fluxfiles/trash/<id>/…` path — a hold is defined in terms of
the tenant-visible path, so it must still bind after the file has been
soft-deleted). `emptyTrash()` does **not** abort wholesale on one held entry
— it skips held entries and purges the rest, returning `{purged: N, held: M}`
so the caller can see some were withheld (a deliberate, visible trade-off,
not a silent partial success).

`list()` calls `holdCovering()` per item as described in §4.2.

---

## 6. Security

**Folder holds and new files added later — the explicit design choice the
task asked for.** A hold is a **live path-prefix rule, re-checked at
delete-time**, not a point-in-time snapshot of the file ids that existed when
the hold was placed. Consequence: a file uploaded into an already-held folder
*next week* is automatically covered — `holdBlocking()`/`holdCovering()`
match on the live path, they never consult a fixed member list. **Trade-off,
stated plainly:** an operator cannot say "hold everything under `contracts/`
as of today, but let new uploads there continue freely" — that middle ground
doesn't exist in v1. If that's ever needed, the workaround is to route new
uploads to a sibling folder outside the held prefix. The alternative design
(snapshot the file list at hold time) was rejected because it's the wrong
default for litigation holds specifically — the whole point of holding a
folder is usually "everything relevant to this matter, including whatever
shows up later," and a snapshot-based hold that a party could evade by simply
adding files to the held folder (which then *wouldn't* be covered) is a worse
compliance story than the inflexibility above.

**Rename/move of a held path — blocked, and this is a security requirement,
not just a nicety.** Two independent reasons: (1) chain-of-custody — a legal
hold is supposed to preserve a record at a stable, identifiable path;
relocating it (even without touching a single byte) can be construed as
tampering, and breaks any external citation to that exact path an opposing
party or auditor may already hold. (2) **Trivial evasion.** `holdBlocking()`
matches on path strings. If `rename`/`move` were left unblocked, anyone with
`write` could defeat a hold instantly: `rename` the held file to any other
name, and it no longer matches the held path at all — the hold would
silently stop applying to the (still perfectly intact) file. Blocking
rename/move of the source path closes this off completely; it is not
optional hardening, it's required for the "even an admin token hitting the
normal endpoints must be refused" property the feature exists to guarantee.

**Copy is deliberately *not* blocked.** `copy`/`cross-copy` never remove or
relocate the source — the held record stays exactly where it was, at the
same path, unmodified. A copy elsewhere is a new, independent file; it is not
itself placed under the hold (holding the copy too, automatically, was
considered and rejected — it would silently proliferate holds across
unrelated paths with no `reason` trail explaining why the copy is held).

**Cross-tenant visibility — the explicit answer to "can tenant A's admin see
tenant B's file is under hold."** `holds.json` is per-**disk**, like
`trash.json`/`index.json`, not per-tenant — many tenants on a shared disk can
have entries in the same file. But unlike `audit.jsonl` (which needed an
*unscoped* token for purge because log lines have no natural per-line
ownership filter), hold entries carry a `path`, so they filter through the
same `isPathInScope()` machinery `listTrash()` already uses:
- **Placing/releasing a hold requires the target path to be inside the
  caller's own token scope** (`403 legal_hold_scope` otherwise) — a
  prefix-scoped admin cannot place, release, or even discover (via
  `/hold/list`) a hold on a path outside their own prefix. In practice they
  can't even *reach* another tenant's paths through any FluxFiles endpoint
  (scoping is enforced well before hold-checking runs), so this isn't a real
  information-leak vector either way.
- **Only a genuinely unscoped token** (empty `prefix`, e.g. an agency
  `superadmin` — see `docs/ACL-ROLE-PRESETS-DESIGN.md`) sees and manages
  holds **across all tenants** sharing that disk via `/hold/list`.
- **Enforcement**, by contrast, is deliberately **not** scope-filtered: a
  hold placed by tenant A's own (narrowly-scoped) admin still blocks a delete
  attempted later by a *broader* unscoped superadmin token touching that same
  path — a hold must bind regardless of who's now trying to remove the file,
  otherwise a wider-scoped token would be a built-in bypass.

**Privacy of the compliance detail.** The *fact* that a path is held is
visible to anyone who can see it in a listing (`on_hold: true`, unconditional
— so a regular collaborator whose delete gets refused isn't left confused by
an opaque failure). The *reason*, *who placed it*, and *when* are visible
only to callers with the `audit` perm — a hold's reason (`"pending
litigation — Employee X wrongful termination claim"`) is itself sensitive
information that shouldn't be exposed to every collaborator who happens to
have read access to the folder.

**Error codes needing i18n (×16 `lang/*.json` files):**
`legal_hold_active` (403), `legal_hold_not_found` (404),
`legal_hold_already_released` (409), `legal_hold_already_active` (409),
`legal_hold_reason_required` (400), `legal_hold_scope` (403),
`legal_hold_max_active` (403). **Not needed:** `allow_legal_hold_forbidden` —
`fm.js`'s error-rendering already has a generic fallback for any code
matching `^allow_[a-z0-9_]+_forbidden$` → `error.module_forbidden` with a
`{module}` placeholder (see `fm.js` ~L608), the same way `allow_ai_vision_forbidden`/
`allow_audit_export_forbidden`/etc. need no dedicated key today.

**Rate limiting / caps.** No dedicated rate bucket — place/release are POST
routes, so they already hit the generic write bucket like the rest of the
non-terminal/non-git-deploy surface. `reason`/`release_reason` capped at
1000 chars (bounds `holds.json` and audit-log size). `countActiveHolds()`
cap (`FLUXFILES_LEGAL_HOLD_MAX_ACTIVE`, default 1000) bounds both the
manifest's growth and the per-`list()` enforcement/enrichment cost (see §4.2's
zero-active-holds fast path for the common case).

**SSRF / signing / HMAC:** not applicable — no outbound requests, no
new token type. This is an ordinary `Authorization: Bearer`-authenticated
admin action, not a public/portal link.

**§ "documented, not solved" — two limitations stated explicitly, not
silently implied away:**

1. **Out-of-band, storage-level deletion.** A legal hold can only enforce at
   the **FluxFiles API layer**. It cannot stop:
   - an operator (or anyone with direct access) `rm`-ing the file on a local
     disk or over raw SFTP outside FluxFiles;
   - an S3/R2 **lifecycle expiration rule** deleting the object on schedule;
   - direct deletion via the cloud console/CLI/SDK against the bucket;
   - for the `FLUXFILES_STORAGE_BACKEND=db` mode, a raw `DELETE` against the
     underlying database bypassing `MetadataRepositoryInterface` entirely
     (this only affects the *metadata*/hold record, not file bytes, but a
     tampered/deleted hold row would stop enforcement working).
   FluxFiles has no control-plane access to a BYOB bucket's lifecycle rules
   or to the host OS's filesystem permissions — it is fundamentally
   out-of-scope for an application-layer feature to prevent this. **Recommend,
   don't build:** operators who need true immutability should pair a
   FluxFiles legal hold with the storage provider's own protection (S3
   Object Lock + Versioning + MFA Delete, R2's equivalent, or `chattr +i` /
   filesystem ACLs for local disks) — document this in the eventual
   user-facing docs for the feature, worded as plainly as the git-deploy
   review's "non-atomic deploy onto a live webroot is documented, not
   solved" (§4.8 there).
2. **In-place content mutation is not blocked in v1.** A legal hold in this
   design only blocks **removal and relocation** (delete/trash/rename/
   move/cross-move/purge/empty) — it does **not** block overwriting a held
   file's *bytes in place* via `upload_collision=overwrite`, `/api/fm/optimize`
   (replaces the original), `/api/fm/watermark` (burns in permanently),
   `/api/fm/content` `PUT` (config/code editor), `/api/fm/chmod`, or an
   `/api/fm/extract` landing on top of it. A token with plain `write` can
   still alter a held record's content without triggering
   `legal_hold_active`. This is a real gap a litigation-hold buyer would
   reasonably ask about, and it is *not* silently swept aside: it was left
   out of v1 deliberately because the task's endpoint list centers on
   deletion, and extending `holdBlocking()`'s check into every write/overwrite
   path is a materially larger surface (nearly every mutating route in
   `FileManager`) that deserves its own review rather than being bolted on
   here. **Recommended v2:** thread the same `assertNoActiveHold()` guard
   into `upload()` (only the overwrite/collision-replace branch),
   `putContent()`, `OptimizeModule`'s replace path, and the watermark
   burn-in — this spec explicitly recommends it as the natural next
   increment, not a "won't fix."

---

## 7. Package layout

**Free/core (`packages/core/`):**
- `api/Claims.php` — `allowLegalHold` property + `isAllowed('allow_legal_hold')` case.
- `api/MetadataRepositoryInterface.php` — 7 new method signatures (§5).
- `api/StorageMetadataHandler.php` — implements them against `_fluxfiles/holds.json`
  (same `acquireIndexLock`/`releaseIndexLock` discipline as trash).
- `api/Db/DbMetadataHandler.php` — implements the same 7 methods against a new
  `legal_holds` table, following `docs/DB-STORAGE-MIGRATION-DESIGN.md`'s
  established pattern (mirrors how trash/audit already got DB-backend
  counterparts). **This must ship day one, not be deferred** — the Enterprise
  buyer this feature targets is exactly the kind of operator likely to also
  run `FLUXFILES_STORAGE_BACKEND=db`, and shipping JSON-only first would
  repeat the retrofit churn already visible elsewhere in this codebase's
  history.
- `api/Db/JsonToDbMigrator.php` — migrate `_fluxfiles/holds.json` → the new
  table, alongside its existing trash/audit migration.
- `api/FileManager.php` — `assertNoActiveHold()` guard (§5), wired into
  `delete()`/`trash()`/`trashDirectory()`/`rename()`/`move()`/`crossMove()`/
  `purgeTrash()`/`emptyTrash()`; `list()`'s per-item `on_hold` (+admin-only
  detail) enrichment via `holdCovering()`.
- `api/ModuleRegistry.php` — register `'legal-hold' => '\\FluxFiles\\LegalHold\\LegalHoldModule'`.
- `api/index.php` — 3 paid routes (`POST /hold`, `POST /hold/release`,
  `GET /hold/list`, gated exactly like `/audit/purge`'s existing shape:
  `hasPerm('audit')` then `ModuleRegistry::require('legal-hold', $license, $claims)`
  then delegate to the module); 1 free route (`GET /hold/status`, `read` perm
  only, calls `holdCovering()` directly, no module dependency);
  `resolveAuditAction()` map + `reason`-carrying audit-detail special case
  (§4.3); the `legal_hold_blocked` catch-block addition (§4.4).
- `assets/fm.js` / `public/index.html` — UI, see §8.
- `lang/*.json` (all 16) — new error keys (§6) + UI copy keys
  (`legal_hold.title`/`.place`/`.release`/`.reason_placeholder`/
  `.placed_by`/`.since`/`.confirm_release`/`.badge_tooltip_admin`/
  `.badge_tooltip_user`, etc.).
- `docs/CONFIG.md` — `allow_legal_hold` claim (§2.13) + `FLUXFILES_LEGAL_HOLD_MAX_ACTIVE`
  env var (§3). Required for `tests/unit/test-config-doc.php` to keep passing.

**Paid module package (new, gitignored, `packages/legal-hold/`):**
- `src/LegalHoldModule.php` — `id()`/`claim()` per `ModuleInterface`, plus the
  actual business methods `place()`/`release()`/`list()`, taking
  `Claims`+`DiskManager`+`MetadataRepositoryInterface` directly (mirrors
  `AuditExportModule::export()`/`::purge()` — it does **not** route through
  `FileManager`; it needs `Claims::scopePath()`/`isPathInScope()` — both
  already public — plus a small self-contained "reject `_fluxfiles/`-prefixed
  paths" guard and a `fileExists`/`directoryExists` check via `DiskManager`).
- `composer.json` floor = the first core tag whose `index.php` actually
  **calls** `ModuleRegistry::require('legal-hold', …)` — per the project's
  standing rule that the floor must be "which core may this install
  against," not just "which core added the scaffolding" (this bit the
  `virus` module once).
- Own repo, own `v1.0.0`-style tags, packaged via `php scripts/pack-modules.php`
  into the update-server catalogue like the other 10 modules.

**Laravel proxy (`packages/laravel/`):**
- **Enforcement needs zero new code.** `FluxFilesController`'s existing
  `trash()`/`delete()`/`rename()`/`move()`/`crossMove()`/`trashPurge()`/
  `emptyTrash()` methods already call the shared core `FileManager` class
  (`composer require`'d, not duplicated) — once those gain
  `assertNoActiveHold()`, Laravel inherits the block automatically. This is
  a deliberate contrast with terminal/git-deploy, which needed full
  proxy-side duplication because those live outside `FileManager` entirely;
  legal hold's enforcement piggybacks on methods the proxy already calls.
- **Management needs 4 new thin controller methods** (`hold`/`holdRelease`/
  `holdList`/`holdStatus`), built exactly like `auditExport`/`auditPurge`
  were: own `Claims` object from the Laravel request, `ModuleRegistry::require('legal-hold', …)`,
  delegate to the module.
- `FluxFilesManager.php` forwards `allow_legal_hold` unconditionally, like
  `allow_audit_export`.
- `routes/fluxfiles.php` — 4 new routes.
- `composer.json` — bump the `fluxfiles/fluxfiles` floor to the version that
  ships `assertNoActiveHold()` + the new interface methods.

**WordPress proxy (`packages/wordpress/`):** same shape —
`FluxFilesApi::handleHold()`/`handleHoldRelease()`/`handleHoldList()`/
`handleHoldStatus()`, `FluxFilesPlugin.php` claim forwarding, `composer.json`
floor bump. Enforcement is likewise automatic (WordPress's proxy also builds
its own `FileManager` from the same composer-required core package).

---

## 8. UI — admin-gated, `proGate()` three-state pattern

Mirrors the AI Vision UI's shape (detail-panel button + context-menu +
action-sheet entries) and the `proGate('allow_x', 'module')` three-state
convention already used for Share/Intake/Versioning/Audit-export/AI-Vision.

```js
get legalHoldGate() { return this.proGate('allow_legal_hold', 'legal-hold'); },
```

**Visibility gate:** the entire "Legal Hold" affordance (badge tooltip detail,
place/release controls) is only rendered for callers with the `audit` perm
(`this._hasPerm('audit')`) — a legal hold is an admin/compliance action, not
a general file operation, same posture as the Audit Export panel ("no
toolbar entry of its own... surfaced only inside the Activity Log panel").

**Row-level indicator (list view), for *everyone*:** a small lock icon badge
on any file/folder row where `on_hold === true` (from the free, unconditional
`/list` enrichment — §4.2), regardless of perm. Tooltip text differs by
audience:
- Non-admin (no `audit` perm): fixed, non-sensitive localized string —
  *"This item is protected and cannot be deleted or moved."* No reason, no
  identity.
- Admin (`audit` perm): the same lock badge, tooltip expands to *"On legal
  hold since {date} — placed by {placed_by}"* with the `reason` shown on
  click/expand.

**Detail panel section** (visible only with `audit` perm), three states:

1. **No hold.** If `legalHoldGate === 'on'`: a small "Place legal hold…"
   button. If `'locked'`: the same inline Pro-teaser pill pattern
   `auditExportGate`'s locked state already uses (no separate toolbar entry —
   consistent with how Audit Export's teaser is rendered inline rather than
   as its own button). If `'hidden'`: render nothing (operator deliberately
   withheld it, or server unlicensed-and-framed).
2. **Held by X since Y.** A lock badge line with `reason` (expandable),
   `placed_by`, `placed_at`; if `legalHoldGate === 'on'`, a "Release hold…"
   button. (The hold-exists *display* itself is not gated by `legalHoldGate`
   — an admin can always see an existing hold's detail regardless of whether
   *this* token happens to carry `allow_legal_hold`, since visibility is the
   free/core side of the split in §2; only the release *action* needs the
   gate.)
3. **Releasing.** Clicking "Release hold…" opens a small modal requiring a
   release `reason` (same non-empty validation as placement) with
   busy/error/success states — mirrors the AI Vision modal's
   busy/error/result state machine: a spinner while the request is in
   flight, an inline error banner on failure (e.g. `legal_hold_already_released`
   if another admin released it concurrently), and the badge disappearing on
   success.

**Context menu / action sheet** (single-selection, `audit` perm only): a
"Legal hold…" entry (lock icon) opening the same detail-panel section/modal.
When the selected item is itself on hold, its **Delete**/**Move to trash**
context-menu entries are shown **disabled** (not hidden — hidden would look
like a missing feature) with the same non-sensitive tooltip as the row badge,
for *every* user, not just admins — so a plain collaborator gets a clear,
non-alarming "why can't I delete this" signal at the point of the attempted
action, not just after a failed request.

`canLegalHoldFile()` helper (parallel to `canAiVisionFile()`): true whenever
`this._hasPerm('audit')` and a single item is selected (files and folders
both — unlike AI Vision, this isn't image-only).

---

## 9. Test plan

**Unit (`tests/unit/test-legal-hold.php`):**
- `StorageMetadataHandler`: `addHold`/`getHold`/`releaseHold`/`allHolds`/
  `countActiveHolds` round-trip; `holdCovering()`/`holdBlocking()` prefix-
  overlap matrix — self, ancestor, descendant, sibling (no match), and the
  asymmetry between the two (a descendant-only case matches `holdBlocking`
  but not `holdCovering`).
- `Claims::fromJwtPayload` — `allow_legal_hold` decode + default `false`.
- Cap enforcement (`countActiveHolds` at/over `FLUXFILES_LEGAL_HOLD_MAX_ACTIVE`).
- Duplicate-active-hold-at-same-path → the placement layer's `409` behavior.
- `docs/CONFIG.md` sync — covered automatically by the existing
  `tests/unit/test-config-doc.php` guard once the claim is documented.
- `lang/*.json` — the existing `tests/unit/test-i18n.php` guard, once the
  new error + UI keys are added to all 16 files.

**Integration (`tests/integration/test-legal-hold-enforcement.php`),
against `FileManager` directly:**
- Hold a single file → `delete()`/`trash()`/`rename()`/`move()` all throw
  `legal_hold_active`; `copy()`/`cross-copy` still succeed (not over-blocked).
- Hold a **folder** → deleting/renaming/moving the folder itself is blocked;
  deleting/renaming a file living *inside* it is also blocked (descendant
  coverage via `holdBlocking`); a file **uploaded into the held folder
  after** the hold was placed is *also* blocked when later deleted — this is
  the load-bearing test proving the "live prefix re-check, not a snapshot"
  design decision (§6) actually holds in code.
- Hold a single **file**, then attempt to delete/rename/move its **parent
  folder** → blocked (the "ancestor operation touches a held descendant"
  direction of `holdBlocking`).
- `purgeTrash()`/`emptyTrash()` on an item whose trash entry's
  `original_key` is under an active hold (hold placed either before or
  after trashing) → blocked; `emptyTrash()` over a mixed scope (one held,
  one not) → purges the unheld one and returns `{purged: 1, held: 1}`.
- Release the hold → all previously-blocked operations now succeed.
- **License-independence proof:** with the `legal-hold` module NOT
  registered in `ModuleRegistry` at all (simulating an uninstalled/unlicensed
  server) but a hold entry already present in `holds.json` (e.g. left over
  from a prior license, or migrated data), `assertNoActiveHold()` still
  blocks — proving enforcement genuinely doesn't depend on the module class
  existing.
- Cross-tenant scoping: a prefix-scoped admin token can't place/release/see
  a hold outside its prefix (`legal_hold_scope` 403 / filtered out of
  `/hold/list`); an unscoped admin token sees/manages holds across all
  tenants on the disk.
- Audit trail: placing/releasing writes `legal_hold_place`/`legal_hold_release`
  with `reason` in `detail`; a **blocked** delete attempt writes
  `legal_hold_blocked` (mirroring the existing `virus_blocked` precedent) —
  including for a request that itself returned 403.
- `ModuleRegistry` 3-layer gate on the paid routes: uninstalled → 501,
  installed+unlicensed → 402, installed+licensed+claim-off → 403
  `allow_legal_hold_forbidden` (relies on the existing generic i18n
  fallback, not a dedicated key).

**Proxy smoke tests** (`packages/{laravel,wordpress}/tests/test-*-smoke.php`):
follow the existing stubbing pattern used for `audit-export`'s smoke test —
stub `LegalHoldModule`, verify the 4 new routes gate correctly, and add a
regression asserting the *existing* `trash`/`delete`/`rename`/`move` smoke
tests still pass once `assertNoActiveHold()` ships in core (proves the
"automatic inheritance" claim in §7 rather than just asserting it in prose).

**Browser (`tests/browser`):** place-hold modal reason-required validation;
release confirmation flow busy/error states; row badge renders for a
non-admin fixture (generic tooltip, no reason/who) vs. an admin fixture
(full detail + release control); `legalHoldGate` locked-teaser rendering
matches the `auditExportGate` inline-pill pattern.

**e2e:** the real place→block→release→allow HTTP round trip lives in the
private `legal-hold` module's own repo (same as other paid modules' real
behavior tests) since `LegalHoldModule` doesn't exist in the OSS checkout;
core's public CI only exercises the free enforcement primitives + the
`ModuleRegistry` 501/402 paths, per existing precedent for the other 10
paid modules.

---

## 10. Open questions / trade-offs (not silently decided)

1. **Out-of-band storage deletion** (S3/R2 lifecycle rules, direct `rm`,
   console/CLI access, raw DB writes under the `db` backend) — **documented,
   not solved** (§6). FluxFiles enforces only at its own API layer.
2. **In-place content mutation isn't blocked in v1** (overwrite-upload,
   `/optimize`, `/watermark`, `/content` PUT, `/chmod`, extract-overwrite) —
   **documented, not solved** (§6), explicitly recommended as the v2
   increment.
3. **`holds.json` has no archival-before-anything, unlike audit's
   archive-before-truncate.** Released entries are kept forever (needed for
   history), and there's no cap on *total* (only *active*) entries. For a
   customer with years of released holds this file could grow unbounded. A
   future `_fluxfiles/legal-hold/archive/` pattern (mirroring
   `_fluxfiles/audit/archive/`) is the natural fix if this becomes a real
   operational issue — deferred, not designed here.
4. **Metadata edits (title/tags/alt text via `PUT /api/fm/metadata`) are not
   blocked by a hold.** Judgment call: metadata edits don't destroy or
   relocate the underlying record, so they were left out of scope — but some
   compliance interpretations might consider tag/caption changes on a held
   record a form of tampering too. Flagged as a genuine, arguable limitation,
   not resolved here.
5. **Only one active hold per exact path in v1** (a second placement attempt
   on an already-held path gets `409`, not a second independent hold record).
   An enterprise juggling multiple simultaneous matters that touch
   overlapping files may want N independent holds per path, each releasable
   on its own timeline (item stays held until *all* are released). Deferred
   to v2 — v1's single-hold-per-path model is simpler and covers the common
   case.
6. **`audit` perm reuse (§3) bundles legal-hold authority with raw audit-log
   visibility** — cannot be split finer without a new perm bucket. Accepted
   trade-off, not a default any future work should assume is permanent.
