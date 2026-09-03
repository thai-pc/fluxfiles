# Design: Sharing a specific folder with a specific teammate

Status: **Final — pattern documented, nothing to implement.** This spec is
documentation/pattern only by design (see §1 non-goals): it introduces no new
claims, storage, or endpoints, so there is no FluxFiles code change to make
and no automated test to add (§7). The cross-reference from
`ACL-ROLE-PRESETS-DESIGN.md`'s §1 non-goal 1 back to this document (§8) is
already in place. Verification is the operator's own grant-lookup/mint code
and, for §4b, a manual `updateToken()` + `setDisk()` walkthrough — both
outside this repo's scope.

## 0. What this is and why it's a separate spec

[`ACL-ROLE-PRESETS-DESIGN.md`](ACL-ROLE-PRESETS-DESIGN.md) (§1, non-goal 1)
explicitly deferred this:

> **Per-resource, per-teammate sharing** (Google-Drive-style "share this
> specific folder with user X as editor"). This genuinely requires persisted
> state — a real assignment record of who can access what beyond a path
> prefix — and is a materially larger feature... If pursued later, it should
> be its own spec, and should be designed to **reuse `pathPrefix`-scoped
> tokens** (mint teammate B a token scoped to the specific subtree they're
> being given access to) rather than inventing a generic permissions table.

`docs/DB-STORAGE-MIGRATION-DESIGN.md` §14 item 6 flagged the same boundary
from the other direction: turning FluxFiles' capability tokens into a
server-checked ACL system is "a capability-token → server-checked-ACL
architecture change, not a storage-backend swap" and was deliberately kept
out of scope there too.

Both docs point at the same conclusion, which this spec makes concrete: the
"who can access which folder" **assignment record** is real persisted state,
but it belongs entirely in **the operator's own application**, not in
FluxFiles. FluxFiles' job stays exactly what it already does — decode a JWT,
enforce `pathPrefix`/`perms`/`disks` — and needs **zero new claims, zero new
storage, and zero new endpoints** to support this. The feature is a *pattern*
for combining things that already ship: `prefix`-scoped tokens, the `role`
preset from the ACL spec (once implemented), and the already-shipped
`updateToken()` live-token-swap bridge.

This is why it's a separate spec rather than an extension of the ACL spec:
the ACL spec is about *what a token can do*; this spec is about *who decides
which token a specific person gets*, which is inherently an
operator-database concern.

## 1. Non-goals

1. **No change to `Claims.php`.** `pathPrefix` stays a single string. Grepping
   `isPathInScope()`/`scopePath()`/`unscopePath()`
   (`packages/core/api/Claims.php`) confirms all three implement a
   chroot-style rewrite relative to exactly **one** prefix — there is no
   multi-prefix/allowlist model like `hasDisk()`'s `in_array` check. A user
   who needs access to their own home folder *and* one shared folder gets
   **two tokens**, not one token with two prefixes. Giving a single token
   multiple disjoint prefixes would be a real `Claims.php` model change and
   is explicitly out of scope here — a candidate for its own future spec only
   if real demand emerges for merging scopes into one token.
2. **No new server-side persistence in FluxFiles.** No new `_fluxfiles/*.json`
   file, no new sidecar, nothing added to `StorageMetadataHandler.php`. The
   "who has access to what" table lives in the operator's own database
   (Laravel migration, WordPress custom table, whatever the host app already
   uses for its own users/teams).
3. **No sub-prefix exclusions.** Sharing folder `/projects/acme` shares
   everything under it, including subfolders, exactly like any other
   `pathPrefix`-scoped token today. You cannot share `/projects/acme` while
   excluding `/projects/acme/private`. If that granularity is ever needed,
   it requires restructuring storage layout (move the excluded subtree
   outside the shared prefix), not a FluxFiles feature.
4. **Not the `share`/`intake` paid modules.** Those modules exist for
   **external or semi-anonymous recipients** — a branded public link, TTL'd,
   optionally password-protected, with no assumption the recipient has any
   account in the operator's system (`PublicLinks.php`, no JWT on the
   recipient side at all). This spec is for **internal teammates who already
   have their own authenticated session and their own normal FluxFiles JWT**
   in the same tenant. Do not route this pattern through `ModuleRegistry`,
   licensing, or the share/intake claim family — it needs none of that. This
   spec produces zero billing surface; it is pure claims composition, same
   free/core mechanism as the rest of the JWT claim system.
5. **No operator-facing UI.** Like the ACL spec, this is a backend/minting
   pattern the operator's own app code implements. What the "Shared with me"
   panel looks like is the operator's product decision.

## 2. The data model (entirely operator-owned)

A single table in the operator's own database, illustrative only:

```
folder_shares
  id                  PK
  tenant_id           -- if multi-tenant; omit for single-tenant apps
  disk                -- which FluxFiles disk (must be one the tenant has)
  folder_path         -- the shared subtree, e.g. "projects/acme"
  granted_to_user_id  -- the teammate receiving access
  granted_by_user_id  -- who created the grant (for audit/UI display)
  role                -- 'viewer' | 'editor' | 'admin' (see §2 of the ACL spec)
  created_at
  revoked_at          -- null while active; set on revoke (see §5)
```

FluxFiles never sees this table and has no API that reads or writes it. It
is exactly as invisible to FluxFiles as the operator's own `users` table.

Nothing here is new to the ACL principle already established in
`ACL-ROLE-PRESETS-DESIGN.md` (§0): "don't treat the server as the owner of
user metadata." The metadata FluxFiles owns is *file* metadata; who's allowed
into which folder is *access* metadata, and it belongs with the operator's
own users/permissions system, which every real host app already has.

## 3. Minting: a second, separately-scoped token per grant

When teammate B needs to see folder F that A shared with them, the operator
mints a **new JWT scoped to F**, using `pathPrefix = F` and (once the ACL
spec ships) `role` = the grant's stored role to set the capability bundle:

```php
// Laravel-flavored example; same shape via fluxfiles_token() in embed.php,
// createToken() in @fluxfiles/node, or the WordPress equivalent.
$grant = FolderShare::where('granted_to_user_id', $teammate->id)
    ->where('id', $shareId)
    ->whereNull('revoked_at')
    ->firstOrFail();

$sharedToken = fluxfiles_token([
    'user'   => (string) $teammate->id,
    'disks'  => [$grant->disk],
    'prefix' => $grant->folder_path,
    'role'   => $grant->role,      // 'viewer' | 'editor' | 'admin'
    'ttl'    => 3600,              // see §5 — short-lived, re-minted on open
]);
```

This is a completely ordinary token mint — nothing about it is
sharing-specific from FluxFiles' point of view. The only thing tying it to
"sharing" is that the operator looked up `$grant->folder_path` from their own
table instead of from the teammate's own home-folder convention.

If a grant needs finer-grained permissions than the four standard roles
provide (e.g. read+write but no delete), override `perms` explicitly — the
options array's `claims` escape hatch (or the `perms` key directly, since
it's a first-class option) always wins over the role preset, per the
"explicit claims always win" rule established in the ACL spec's §3.

## 4. Presenting the shared folder in the UI

Two integration patterns, in order of recommendation:

### 4a. Two embeds (recommended default)

Mount FluxFiles twice: once with B's normal home-prefix token for "My
files," and once — e.g. inside a "Shared with me" tab/modal, mounted only
when opened — with the `prefix`-scoped token from §3. This requires **no
`fm.js` changes and no special sequencing**: each iframe boots once with its
own token and never needs to swap scope mid-session. This is the safer
default because it sidesteps the gap described in §4b entirely.

### 4b. One embed, switchable workspace (optional, more complex)

If the operator wants a single mounted FluxFiles instance that can pivot
between "my files" and "shared folder F" without a remount (e.g. a
folder-switcher dropdown in the host chrome), the already-shipped
`updateToken()` bridge is the right primitive — but it needs to be paired
with an explicit reset, which is not automatic.

`updateToken(token)` exists in the SDK (`packages/sdk/fluxfiles.js:279`),
React (`useFluxFiles.ts:161`), and Vue (`useFluxFiles.ts:143`); all three
just `postMessage` an `FM_TOKEN_UPDATED` payload into the iframe. Inside
`fm.js`, the handler for that message
(`packages/core/assets/fm.js:338-349`) does:

```js
if (msg.type === 'FM_TOKEN_UPDATED' && msg.payload?.token) {
    this.token = msg.payload.token;
    this.authRequired = false;
    this.authState = 'ok';
    this._refreshAttempts = 0;
    // A fresh token arrived while a load was broken → recover the view.
    if (this.loadError) {
        this.loadError = null;
        this.loadFiles();
        this.loadQuota();
    }
}
```

That handler was built for one purpose: recovering from an expired/invalid
token (`FM_TOKEN_REFRESH` flow, `fm.js:621-670`). It only reloads the
listing when `this.loadError` was already set. On a healthy session with no
load error — the normal case when switching from "my files" into "shared
folder F" — swapping the token silently changes `this.token` **and nothing
else**: `currentPath`, `currentDisk`, and the currently-rendered file list
all keep showing the *old* scope, while every subsequent API call
(uploads, deletes, presigns) starts hitting the *new* scope. That mismatch
is a real correctness hazard for this pattern specifically, not a bug in
`fm.js` — the handler is doing exactly what it was designed for
(error-recovery), and this spec is asking it to do something else
(workspace switch) that happens to reuse the same message type.

**The fix requires no `fm.js` change** — just pairing `updateToken()` with
an existing `FM_COMMAND`. `switchDisk()` (`fm.js:1079-1086`) already does
precisely the reset this pattern needs:

```js
switchDisk(disk) {
    this.currentDisk = disk;
    this.currentPath = '';
    this._updateUrlPath();
    this.loadFiles();
    this.loadQuota();
    this.sidebarOpen = false;
},
```

So the operator-side pattern for switching into a shared folder is:

```js
sdk.updateToken(sharedFolderToken);           // swap credentials
sdk.setDisk(sharedFolderToken_disk);          // FM_COMMAND → switchDisk():
                                               //   resets path to '', reloads
                                               //   files + quota under the
                                               //   NEW token
```

Call `setDisk()` with the shared grant's disk **even if it's the same disk
name as the teammate's home disk** — `switchDisk()`'s reset (path → `''`,
reload listing + quota) is what's actually needed here, not the disk change
itself. `navigate('')` (`fm.js:911-916`) alone would also reset the path and
reload the listing, but skips the quota reload that `switchDisk()` includes,
so `setDisk()` is the more complete reset command. Switching back to "my
files" is the same two calls in reverse (home token + home disk).

Order matters: `updateToken()` must complete before `setDisk()` fires, since
`switchDisk()`'s `loadFiles()`/`loadQuota()` calls use whatever token is
current at that moment. Both are one-way `postMessage` sends with no ack, so
in practice call them back-to-back synchronously — `fm.js`'s message
listener processes them in the order they arrive.

## 5. Revocation and token lifetime

FluxFiles JWTs are stateless and cannot be revoked early — the same
constraint the ACL spec's §0 already leans on. This means:

- **Setting `revoked_at` on the `folder_shares` row does not invalidate an
  already-minted token.** A teammate holding a live shared-folder token can
  keep using it until it expires, even after the grant is revoked in the
  operator's DB.
- **Mitigation: mint short-lived, on-demand.** Don't mint the shared-folder
  token once and cache it client-side for reuse across sessions. Mint it
  fresh (re-check `whereNull('revoked_at')`, exactly as in the §3 example)
  every time the teammate opens the "Shared with me" view, with a `ttl` on
  the order of a working session (e.g. 3600s = 1 hour) rather than a
  long-lived token. This mirrors the operator being the source of truth for
  revocation, not FluxFiles.
- This is a real, inherent limitation of any stateless-JWT sharing model —
  not something this spec can eliminate — and should be communicated to the
  operator as a caveat, not silently glossed over. An operator who needs
  instant, mid-session revocation (e.g. "kick the teammate out right now")
  would need a materially different architecture (server-checked ACL, which
  is exactly the "capability-token → server-checked-ACL" shift both
  `ACL-ROLE-PRESETS-DESIGN.md` and `DB-STORAGE-MIGRATION-DESIGN.md` §14 item
  6 deliberately keep out of scope).

## 6. Security notes (operator responsibility)

- **Tenant boundary check happens in the operator's own mint code**, not in
  FluxFiles. Before minting the shared token, the operator must confirm
  `$grant->folder_path` is actually inside the granting tenant's own root —
  e.g. if tenants are separated by a path convention like
  `tenants/{tenant_id}/...`, verify `folder_path` starts with the *granter's*
  own tenant prefix before trusting it, so one tenant's admin can't
  construct a grant row pointing into another tenant's tree. FluxFiles has
  no tenant concept at all; this check exists entirely in application code
  that already validates the grant record on creation.
- The grantor should only be able to create a grant for `role`s at or below
  their own access level on that path (an operator's own authorization
  concern — FluxFiles has no notion of "can grant," only "can access").
- Audit the grant lifecycle (create/revoke) in the operator's own logs. This
  is separate from FluxFiles' own `_fluxfiles/audit.jsonl`, which logs file
  *operations*, not sharing *grants*.

## 7. Testing

No FluxFiles-side automated test is applicable — this spec introduces no
new claims, endpoints, or enforcement logic (§1 non-goals). Verification is
purely of the pattern's correctness in the operator's own app:

- Unit-test the operator's own grant-lookup + mint code (§3): a revoked
  grant must not mint, an out-of-tenant `folder_path` must not mint (§6).
- Manually exercise §4a or §4b end-to-end during implementation of whichever
  the operator chooses; §4b specifically should verify that after
  `updateToken()` + `setDisk()`, the file listing reflects the *new* scope
  and an upload lands under the new `prefix`, not the old one.

## 8. Cross-reference

Add a pointer from `ACL-ROLE-PRESETS-DESIGN.md`'s §1 non-goal 1 to this
document once both are reviewed, so a future reader following that non-goal
lands here instead of re-deriving the same design.
