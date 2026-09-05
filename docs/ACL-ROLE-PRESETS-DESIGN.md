# ACL / Role Presets — Stateless "Role" Layer for JWT Minting

> **Status: Implemented and shipped.** The `role` mint-time preset described
> in this document is live across all four token builders — shipped in
> commit `70f1cea` ("feat(acl): implement role mint-time preset across all
> token builders", 2026-09-01), with a same-day fix for a viewer/editor
> chmod-default bug (commits `d880b98`/`fb7c8a2`, documented in
> `CHANGELOG.md`'s `[0.3.00]` entry). Most of the body below (§0–§6) is the
> original design record, retained for context on the *why* and the claim
> table; it is not a pending proposal. The authoritative, as-shipped
> behavior — including two precedence bugs found and fixed after this design
> was written — is in the **Status** section at the very bottom of this
> file. Read that section first if you only have time for one.

> This is the future spec `docs/DB-STORAGE-MIGRATION-DESIGN.md` §14 item 6
> pointed at: *"a DB could later support a materially different, additional
> model on top of the JWT — e.g. a `permissions`/`acl` table... that would be
> a capability-token → server-checked-ACL architecture change, not a
> storage-backend swap."* That item explicitly deferred a richer ACL as a
> separate spec and resolved that authorization stays 100% stateless
> (`Claims.php` as the sole decision point) regardless of storage backend.
> **This spec does not reopen that question.** It proposes the smallest thing
> that answers the recurring "phân quyền" (role/authorization) request
> without adding any server-side state: a mint-time-only claim-bundle preset,
> exactly parallel to the `edition` preset that already ships in
> `packages/core/embed.php`.

## 0. Executive summary

**Who this is for, and who it isn't.** The value is asymmetric:

- **High value for multi-tenant/multi-user operators** — a SaaS platform
  whose *own tenant* has a real admin/editor/viewer hierarchy among several
  people, and whose backend mints one JWT per (tenant, teammate, session).
  Today that operator hand-assembles the claim bundle for "editor" or "admin"
  from scratch, and a mistake is a real security bug: e.g. minting an
  "editor" token and forgetting `owner_only` or a `pathPrefix`, silently
  granting one teammate full-tenant read/write instead of their own folder.
  A named role preset turns that into a reviewable, testable, one-line
  vocabulary (`role: 'editor'`) instead of a from-scratch claim assembly done
  slightly differently in every call site.
- **Near-zero value for a single-admin/non-tenant operator** — an operator
  who mints tokens for one class of user (e.g. "my own CMS's media library")
  already fully controls the claim bundle themselves; there is no role
  hierarchy to standardize. Don't oversell this case in docs/marketing.

**What this is NOT: a stored role-assignment system.** FluxFiles will not
learn or persist "user X has role Y." That mapping already lives in the
operator's own app — Laravel has its own auth/gates, WordPress has its own
roles/capabilities system, every real SaaS has a users/roles table already.
Storing a parallel copy inside FluxFiles would create two sources of truth
that can silently drift: the operator revokes/demotes a user in their own
system and forgets a FluxFiles-side role record still grants access until a
still-valid, unexpired token expires. This is the same "avoid a duplicate
stateful ACL store" argument already litigated and resolved for the DB
storage migration (`docs/DB-STORAGE-MIGRATION-DESIGN.md` §14 item 6) — cited,
not re-derived here.

**What this spec actually proposes.** A stateless **`role` preset**, mint-time
only, structurally identical to the existing `edition` preset:

```php
$token = fluxfiles_token([
    'user'   => $teammateId,
    'role'   => 'editor',           // ← new: expands to a claim bundle below
    'prefix' => "tenants/{$tenantId}/",   // still explicit, still mandatory
    'disks'  => ['s3'],                   // still explicit, still mandatory
]);
```

Same precedence rule as `edition`: any claim also given explicitly (directly,
or via the `claims` escape hatch) in the same call always wins over the
preset's default. The preset is **never itself a JWT claim** — `role` never
appears in the signed payload; it only ever expands, at mint time, into
individual claims that already exist and are already decoded by
`Claims::fromJwtPayload`. Confirmed by inspection: `edition` does not appear
anywhere in `Claims::fromJwtPayload` (`packages/core/api/Claims.php`) today,
and `role` will not either — this is a mint-side-only feature with **zero
changes to the decode/enforcement side**.

## 1. Non-goals (explicitly out of scope)

1. **Per-resource, per-teammate sharing** (Google-Drive-style "share this
   specific folder with user X as editor"). This genuinely requires
   persisted state — a real assignment record of who can access what beyond
   a path prefix — and is a materially larger feature (the exact
   capability-token → server-checked-ACL shift `DB-STORAGE-MIGRATION-DESIGN.md`
   §14 item 6 flagged and deferred). If pursued later, it should be its own
   spec, and should be designed to **reuse `pathPrefix`-scoped tokens** (mint
   teammate B a token scoped to the specific subtree they're being given
   access to) rather than inventing a generic permissions table. Not designed
   here — see [`TEAMMATE-FOLDER-SHARING-DESIGN.md`](TEAMMATE-FOLDER-SHARING-DESIGN.md)
   for that spec.
2. **Any operator-facing role-management UI/admin panel.** Out of scope —
   this spec is the claims/preset layer only, consumed by code the operator
   writes when minting tokens, not an end-user product surface.
3. **Any change to `Claims.php`, `docs/CONFIG.md`'s claim table, or
   `FileManager.php` authorization logic.** Zero changes to the decode or
   enforcement side. `role` is resolved and discarded entirely on the mint
   side, in `embed.php`/`token.ts`/`FluxFilesManager.php`/`FluxFilesPlugin.php`.

## 2. Role vocabulary and exact claim table

Four roles: `viewer`, `editor`, `admin`, `superadmin`. Each expands to a
concrete bundle drawn from **existing** claims in `Claims.php` — no new claim
names are introduced by this feature.

**Claims a role preset sets** (the "how much capability" layer — perms,
scope-independent behavior toggles, non-paid-module feature gates):

| Claim | viewer | editor | admin | superadmin |
|---|---|---|---|---|
| `perms` | `['read']` | `['read','write']` | `['read','write','delete','audit']` | `['read','write','delete','audit']` |
| `owner_only` | `true` | `true` | `false` | `false` |
| `allow_download` | `true` | `true` | `true` | `true` |
| `allow_zip` | `true` | `true` | `true` | `true` |
| `allow_extract` | `false` | `true` | `true` | `true` |
| `allow_chmod` | `false` | `false` | `true` | `true` |
| `allow_code_edit` | `false` | `false` | `true` | `true` |
| `show_hidden` | `false` | `false` | `true` | `true` |

Notes on specific calls:

- **`perms`** is set by every role because a role IS a capability level —
  unlike `prefix`/`disks`/`userId` (WHO and WHERE, not HOW MUCH), `perms` is
  exactly the kind of thing "role" means. `viewer` never gets `write`;
  `editor` deliberately never gets `delete` (matches the user's own
  vocabulary: editor = read+write, no delete); `admin`/`superadmin` get
  `delete` plus the `audit` permission (`index.php`'s activity-log routes
  gate on `hasPerm('audit')` — an admin who can delete other users' files
  should reasonably be able to read the audit trail of who did what).
- **`owner_only`** flips at `editor`→`admin`: an `editor` is still fenced to
  files they themselves uploaded (per the user's own definition — "editor"
  is a *contributor*, not a manager of other people's files), while `admin`
  can manage other tenant users' files within the same `prefix` (this is
  the whole reason to call it "admin" — team-management capability, not raw
  permission bits).
- **`allow_extract`**/`allow_chmod`/`allow_code_edit`/`show_hidden** turn on
  progressively for `admin`+ (zip/extract/chmod/code_edit/download is the
  exact "broader `allow_*` set" the task called for) because these are
  file-manager *power-user* affordances, not paid features — a `viewer`
  should not be able to run `/api/fm/extract` or flip on dotfile visibility,
  but an `admin` managing the whole tenant reasonably can.
- `allow_zip`/`allow_download` are `true` for every role including `viewer`
  because they're core read-side conveniences (download a zip of what you
  can already see), matching their own claim defaults in `Claims.php`
  (`allowZip`/`allowDownload` both default `true` there already) — a role
  preset narrowing them for `viewer` would be a *behavior change from the
  system default* that isn't what "read-only" means in this codebase (a
  viewer can still download).

**`superadmin` is `admin`'s claim bundle, unchanged, plus one documented
distinction:**

> **`superadmin` does NOT itself clear or widen `prefix`/`disks`.** Those
> remain separate, explicit, per-call arguments the caller controls — the
> role preset only ever touches the feature-claim layer above. An operator
> who wants a truly unscoped token must still pass `prefix: ''` (and the
> right `disks` list) themselves, exactly as they would without any role at
> all. `superadmin` is documented as the one role that is *safe* to combine
> with an empty prefix (there is no case where "read-only unscoped" or
> "editor unscoped" makes sense as a **role**'s intent — an unscoped token is
> inherently an operator/superadmin concept) — but the role preset function
> itself never reads or writes `prefix`/`disks`. Conflating "which role" with
> "which scope" is exactly the misconfiguration class this feature exists to
> prevent, so the two must never be entangled in the implementation or the
> docs.

**Claims a role preset NEVER touches** (role-independent — pure per-call /
per-tenant business config, unrelated to "who is this person on the team"):

- `prefix`, `disks`, `userId`/`sub` — WHO and WHERE, always explicit args.
- `max_upload`, `max_storage`, `max_files`, `allowed_ext`, `upload_collision`,
  `dedupe_uploads`, `variants` — storage/quota/upload policy.
- `rate_read`, `rate_write` — rate limits.
- All URL-import (`allow_url_import`, `max_import_mb`, `import_url_allowlist`,
  `import_path`, `import_rate_limit`, `import_concurrency`), media-preview
  (`media_preview`, `preview_url_ttl`, `max_preview_mb`, `stream_token_ttl`),
  and WebP/srcset (`webp_enabled`, `webp_max_width`, `webp_default_quality`,
  `srcset_widths`, `srcset_sizes`) claims.
- `watermark_*`, `usage_*` — display/branding/dashboard config.
- `terminal_pty_url`, `pdf_tools_url`, `office_url`, `esign_url` — BYO-embed
  URLs (operator infra, not a per-person capability).
- `allow_terminal` — deliberately **not** touched by any role. Shell access
  as the SSH user is high-consequence enough (grants a real remote shell,
  not just file operations) that this spec treats it as its own opt-in
  decision an operator must make explicitly per token, never implied by
  "this person is an admin of the file manager." (Flagged in Open Questions
  below in case that call is wrong.)
- **All eleven paid-module claims** — `allow_share`, `allow_intake`,
  `allow_versioning`, `allow_webhooks`, `allow_ai_vision`, `allow_ocr`,
  `allow_virus_scan`, `allow_backup`, `allow_c2pa`, `allow_audit_export`,
  and (pre-auth, N/A to a JWT claim anyway) `sso`. These stay purely gated by
  the `ModuleRegistry` 3-layer check (installed + licensed + claim) — a role
  preset turning one on is inert on an unlicensed/uninstalled server exactly
  like `edition` today, so there is no reason to fold them into `role`
  (`edition` already owns "which paid features does this tier get"; `role`
  owns "how much can this person do within the features already enabled").
  `allow_optimize` is likewise free/core but replaces/deletes originals in
  place — the same "opt-in per token, not implied by role" reasoning as
  `allow_terminal` applies, so it's also left untouched.

## 3. Interaction with the existing `edition` preset

Both `edition` and `role` are additive, mint-time-only presets writing into
the same payload map. Most of `role`'s claims (`owner_only` and the four
behavioral `allow_*`/`show_hidden` claims) use the identical `if
(!array_key_exists($k, $payload))` guard `fluxfiles_apply_edition_preset()`
already uses — but `perms` is a documented exception requiring earlier
resolution (see item 1 below; this was a real bug in an earlier draft of this
spec). **Merge order** (must match the existing internal order in
`_fluxfiles_build_token()` / `applyTenantOverrides()`, where explicit values
always win last):

1. **Base claims — `perms` needs early resolution; everything else doesn't.**
   Verified directly against all four implementations
   (`embed.php:92-104`, `token.ts:36-46`, `FluxFilesManager.php:34-44`,
   `FluxFilesPlugin.php:264-275` and `587-598` for the BYOB variant): `perms`
   is written **unconditionally, with an already-resolved default value**,
   inside the very same literal array/object as `disks`/`prefix` — e.g. PHP's
   `'perms' => $pick(['perms'], ['read'])` sits inside the same `$payload =
   [...]` block as `'prefix' => (string) $pick(['prefix'], '')`. That means by
   the time any preset function runs *afterward*, `perms` **already exists**
   in the payload — either the caller's explicit value or the hardcoded
   `['read']` (or `['read','write']` for the BYOB helpers) default. A guard of
   the shape `if (!array_key_exists('perms', $payload)) { … }` — the exact
   mechanism `edition` uses for its own claims — can therefore **never fire**
   for `perms`, because the key is always already present.
   >
   > An earlier draft of this spec proposed exactly that guard for `perms`.
   > **It is a real, load-bearing bug**: it silently makes every role's
   > single most important claim inert whenever the caller doesn't pass
   > `perms` explicitly — i.e. the common case a role preset exists to serve.
   > Fixed below.
   >
   > **`owner_only` and the four behavioral claims do NOT have this problem**,
   > despite looking similar — confirmed by re-reading the code: none of them
   > are part of the unconditional base-array literal. `owner_only` is only
   > ever written by a separate `if ($pick(['ownerOnly','owner_only'], false))
   > { $payload['owner_only'] = true; }` (PHP) / `if (opts.ownerOnly)
   > payload.owner_only = true;` (Node) — a block that writes the key **only
   > when truthy**, so the key genuinely is absent from the payload whenever
   > the caller omits it and the global default (`false`) applies. A plain
   > `array_key_exists` guard running afterward can safely fill in an
   > editor/viewer role's `owner_only: true` in that case. The same reasoning
   > holds for `allow_extract`/`allow_chmod`/`allow_code_edit`/`show_hidden` —
   > none of the four base-payload literals initialize them at all. **`perms`
   > is the one exception**, precisely because it is the one role-owned claim
   > the base payload construction *also* hard-defaults.
   >
   > **The fix**: resolve `role` — and look up its preset's `perms` default —
   > *before* the base payload/array is constructed, then thread that default
   > into the *same* resolution call that already supplies the global
   > `['read']` fallback, so `perms` is decided in exactly one place:
   > `'perms' => $pick(['perms'], $roleDefaults['perms'] ?? ['read'])` (PHP) /
   > `perms: opts.perms ?? rolePreset?.perms ?? ['read']` (Node). Precedence
   > is unaffected: `$pick`/`??` already checks for the caller's explicit key
   > first, so an explicit `perms` is found and returned before either
   > fallback is even evaluated. See the corrected snippets in §4.
2. `fluxfiles_apply_edition_preset($payload, $edition)` — sets paid-module
   `allow_*` claims not already present.
3. `fluxfiles_apply_role_preset($payload, $roleDefaults)` — sets `owner_only`
   and the four behavioral `allow_*`/`show_hidden` claims from §2, not
   already present. (`perms` was already resolved in step 1 — this function
   must NOT also touch `perms`, or it would silently no-op exactly like the
   bug above, harmlessly this time since step 1 already got it right, but
   it's dead/confusing code. Keep `perms` out of this function's claim map
   entirely.)
4. The generic `claims` escape hatch, merged last — always wins.

**Design invariant: `edition` and `role` are orthogonal by construction, and
must stay that way.** Every claim `edition` sets today (`allow_optimize`,
`allow_share`, `allow_intake`, `allow_virus_scan`, `allow_c2pa`) is a
paid-module claim. Every claim `role` sets (§2's table) is a
non-paid-module, non-paid-feature behavioral claim. There is no claim either
preset would set to a conflicting value today because their claim sets don't
intersect. This must be preserved as new roles/editions are ever added: if a
future edit to either preset function ever needs to touch a claim the other
already owns, that's a signal that a hand-off should stay explicit (the
`claims` escape hatch) rather than silently expanding one preset's authority
into the other's domain.

Order between steps 2 and 3 is arbitrary today (disjoint claim sets), but is
specified as edition-then-role to match the order the code already computes
it in (`edition` is resolved first in every one of the four ported
implementations today) — don't reorder without reason.

## 4. Adapter porting checklist

Mirrors `edition`'s exact existing footprint — four places, same shape:

Each file needs a small helper returning the raw preset map (so its `perms`
can be pulled out early and threaded into the base payload) *plus* the
existing after-the-fact apply for the rest — not one function that tries to
do both jobs the same way, per §3's fix.

1. **`packages/core/embed.php`** — add a lookup and an apply function:
   ```php
   function fluxfiles_role_preset(?string $role): array
   {
       $presets = [
           'viewer'     => ['perms' => ['read'], 'owner_only' => true],
           'editor'     => ['perms' => ['read', 'write'], 'owner_only' => true],
           'admin'      => ['perms' => ['read', 'write', 'delete', 'audit'], 'owner_only' => false,
                             'allow_extract' => true, 'allow_chmod' => true, 'allow_code_edit' => true, 'show_hidden' => true],
           'superadmin' => ['perms' => ['read', 'write', 'delete', 'audit'], 'owner_only' => false,
                             'allow_extract' => true, 'allow_chmod' => true, 'allow_code_edit' => true, 'show_hidden' => true],
       ];
       return $presets[strtolower((string) $role)] ?? [];
   }

   // Sets owner_only + the 4 behavioral claims when not already present. Deliberately
   // excludes 'perms' — that's resolved earlier, in the base $payload array itself
   // (see _fluxfiles_build_token()), because unlike these claims it already has an
   // unconditional default baked into that array and this guard would never fire for it.
   function fluxfiles_apply_role_preset(array &$payload, array $roleDefaults): void
   {
       foreach ($roleDefaults as $k => $v) {
           if ($k !== 'perms' && !array_key_exists($k, $payload)) {
               $payload[$k] = $v;
           }
       }
   }
   ```
   Wire into `_fluxfiles_build_token()` — resolve `$roleDefaults` *before*
   the base `$payload = [...]` block, thread it into the `perms` line, and
   call the apply function after `fluxfiles_apply_edition_preset()`:
   ```php
   $roleDefaults = fluxfiles_role_preset($pick(['role'], null));
   $payload = [
       'sub'         => (string) $pick(['user', 'userId', 'sub'], ''),
       'iat'         => $now,
       'exp'         => $now + $ttl,
       'jti'         => bin2hex(random_bytes(12)),
       'perms'       => $pick(['perms'], $roleDefaults['perms'] ?? ['read']),   // ← only changed line
       'disks'       => $pick(['disks'], ['local']),
       'prefix'      => (string) $pick(['prefix'], ''),
       'max_upload'  => (int) $pick(['maxUploadMb', 'max_upload'], 10),
       'allowed_ext' => $pick(['allowedExt', 'allowed_ext'], null),
       'max_storage' => (int) $pick(['maxStorageMb', 'max_storage'], 0),
       'max_files'   => (int) $pick(['maxFiles', 'max_files'], 0),
   ];
   if ($pick(['ownerOnly', 'owner_only'], $roleDefaults['owner_only'] ?? false)) {
       $payload['owner_only'] = true;
   }
   fluxfiles_apply_edition_preset($payload, $pick(['edition'], null));
   fluxfiles_apply_role_preset($payload, $roleDefaults);
   ```
   Add `?string $role = null` to `fluxfiles_token()`'s legacy positional
   signature list (end of the list, like `$edition`) and to the doc-comment,
   and thread it through the `_fluxfiles_build_token([...])` array in the
   legacy-form branch (`'role' => $role` alongside `'edition' => $edition`).
   `fluxfiles_byob_token()`/`fluxfiles_mixed_token()` are **out of scope**,
   consistent with `edition` today — neither BYOB helper accepts an `edition`
   parameter either, so `role` following the same precedent is not a new gap.

2. **`packages/node/src/token.ts`** — add a `ROLE_PRESETS` const next to
   `EDITION_PRESETS` (value type `Record<string, unknown>`, not
   `Record<string, boolean>`, since `perms` is a string array):
   ```ts
   const ROLE_PRESETS: Record<string, Record<string, unknown>> = {
     viewer: { perms: ['read'], owner_only: true },
     editor: { perms: ['read', 'write'], owner_only: true },
     admin: { perms: ['read', 'write', 'delete', 'audit'], owner_only: false,
              allow_extract: true, allow_chmod: true, allow_code_edit: true, show_hidden: true },
     superadmin: { perms: ['read', 'write', 'delete', 'audit'], owner_only: false,
                   allow_extract: true, allow_chmod: true, allow_code_edit: true, show_hidden: true },
   };
   ```
   In `createToken()`, resolve the preset *before* building `payload` and
   thread it into the `perms` line (the only changed line in the base
   object):
   ```ts
   const rolePreset = opts.role ? ROLE_PRESETS[String(opts.role).toLowerCase()] : undefined;
   const payload: Record<string, unknown> = {
     sub: opts.userId,
     iat: now,
     exp: now + (opts.ttl ?? 3600),
     jti: newJti(),
     perms: opts.perms ?? (rolePreset?.perms as string[] | undefined) ?? ['read'],
     disks: opts.disks ?? ['local'],
     prefix: opts.prefix ?? '',
     max_upload: opts.maxUploadMb ?? 10,
     allowed_ext: opts.allowedExt ?? null,
     max_storage: opts.maxStorageMb ?? 0,
     max_files: opts.maxFiles ?? 0,
   };
   if (opts.ownerOnly ?? (rolePreset?.owner_only as boolean | undefined)) payload.owner_only = true;
   applyTenantOverrides(payload, opts); // edition preset, then role preset below
   ```
   Inside `applyTenantOverrides()`, right after the `edition` preset block,
   apply the *rest* of the role preset (excluding `perms`, already resolved
   above — and excluding `owner_only`, already resolved above too, since
   Node's `ownerOnly` fallback is folded into the same `if` rather than a
   separate later pass):
   ```ts
   if (rolePreset) {
     for (const [k, v] of Object.entries(rolePreset)) {
       if (k !== 'perms' && k !== 'owner_only' && !(k in payload)) payload[k] = v;
     }
   }
   ```
   **`packages/node/src/types.ts`** — add `role?: 'viewer' | 'editor' |
   'admin' | 'superadmin';` to `BaseTokenOptions`, doc-commented like
   `edition`. `createByobToken()` stays out of scope, matching `edition`'s
   existing BYOB exclusion there too.

3. **`packages/laravel/src/FluxFilesManager.php`** — same restructuring:
   add a private static `rolePreset(?string $role): array` returning the raw
   map (identical preset values to §2's table), resolve it before the
   `$payload = [...]` block in `token()`, thread into the `perms` line
   (`'perms' => $overrides['perms'] ?? ($roleDefaults['perms'] ?? $defaults['perms'])`),
   and add a private static `applyRolePreset(array &$payload, array
   $roleDefaults): void` (excludes `perms`, mirrors the embed.php version)
   called from `applyTenantOverrides()` right after
   `self::applyEditionPreset(...)`. The BYOB token-minting method in this
   same file has the identical unconditional-`perms` base array — **it needs
   the same early-resolution fix if `role` is meant to reach BYOB tokens
   here**; if not (matching core's BYOB exclusion), leave it untouched and
   say so explicitly in the PR, since silently forgetting one of two mint
   paths is exactly the kind of gap this feature exists to prevent elsewhere.

4. **`packages/wordpress/includes/FluxFilesPlugin.php`** — same pattern as
   Laravel. This file has **two** separate unconditional-`perms` base arrays
   (the regular token method and the BYOB token method, roughly lines
   264-275 and 587-598) — apply the identical early-resolution fix to
   whichever of the two `role` is meant to support (by precedent, the
   regular token only, matching `edition`'s existing scope in this file).

None of these four touch `Claims.php` / `Claims::fromJwtPayload` — the
preset only ever expands to claims that already exist and are already
decoded server-side. This is the reason this feature is low-risk/low-effort
relative to `DB-STORAGE-MIGRATION-DESIGN.md`: it is a **mint-side-only,
zero-server-decode-change** feature. The server cannot even tell a token was
minted via a role preset — it just sees ordinary `perms`/`owner_only`/
`allow_*` claims, identical to a hand-assembled bundle.

## 5. Testing plan

Mirrors the existing `edition` test coverage in each package (same files,
new test blocks — no new test files needed since this reuses the token
builder entry points).

**Core PHP** (`packages/core/tests/unit/` — add to whichever file already
covers `embed.php`'s token helpers, or a new `test-role-preset.php` if none
does today):
- **Regression test for the §3/§4 `perms` bug**: `fluxfiles_token(['user'=>'u',
  'role'=>'viewer'])` with **no `perms` key at all** in the options array
  must decode to `perms === ['read']`, and `role=>'editor'` (same, no `perms`
  key) must decode to `['read','write']`. This is the exact case the
  original buggy `array_key_exists`-guard design would have silently failed
  (it would always decode to the global `['read']` default regardless of
  role, since `perms` is unconditionally present in the base payload before
  any preset runs) — this test must fail loudly against that implementation
  and pass against the fixed one.
- Each of the 4 roles produces exactly the claim bundle in §2's table (call
  `fluxfiles_token(['user'=>'u','role'=>'viewer'])`, decode with
  `JwtCompat::decode`, assert `perms`/`owner_only`/`allow_*`/`show_hidden`).
- An explicit claim in the same call overrides the role default (e.g.
  `['role'=>'viewer','perms'=>['read','write']]` → `perms` is
  `['read','write']`, not `['read']`).
- `edition` + `role` combined produce the union of both presets' claims with
  no clobbering (e.g. `['edition'=>'pro','role'=>'admin']` → both
  `allow_share`/`allow_intake`/`allow_optimize` (from `edition`) AND
  `allow_chmod`/`allow_code_edit`/`show_hidden` (from `role`) are present).
- `role` preset never sets `prefix`/`disks`/`sub`/`max_upload`/`max_storage`/
  `max_files` — pass an options array with `role` set and no `prefix`, and
  assert `prefix` decodes to `''` (the constructor default), not something
  the role silently populated.
- `superadmin` + `prefix: ''` decodes to an actually-unscoped token
  (`isPathInScope()` returns true for any path) — proving the role and the
  scope argument compose correctly without either implying the other.

**Node** (`packages/node/tests/token.test.ts`) — same shape as the existing
`edition` override test at line 47 (`'claims escape hatch sets any raw
snake_case claim; explicit wins'`):
```ts
it('role preset sets the exact claim bundle; explicit perms wins', () => {
  const c = decodeToken(createToken({ secret: SECRET, userId: 'u', role: 'editor' }));
  expect(c.perms).toEqual(['read', 'write']);
  expect(c.owner_only).toBe(true);

  const c2 = decodeToken(createToken({ secret: SECRET, userId: 'u', role: 'viewer', perms: ['read', 'write'] }));
  expect(c2.perms).toEqual(['read', 'write']); // explicit perms overrides viewer's default
});

it('edition + role compose without clobbering', () => {
  const c = decodeToken(createToken({ secret: SECRET, userId: 'u', edition: 'pro', role: 'admin' })) as Record<string, unknown>;
  expect(c.allow_share).toBe(true);       // from edition
  expect(c.allow_chmod).toBe(true);        // from role
  expect(c.owner_only).toBe(false);        // from role
});
```

**Laravel/WordPress smoke tests** (`packages/laravel/tests/test-*-smoke.php`,
`packages/wordpress/tests/test-*-smoke.php`) — one test per package calling
`FluxFiles::token($user, ['role' => 'admin'])` /
`FluxFilesPlugin::token($userId, ['role' => 'admin'])` and asserting the
decoded payload matches §2's `admin` row, plus one override test (explicit
`perms` in the overrides array wins over the role default) — matching the
existing `edition` smoke coverage shape in both files.

**Not needed:** no `Claims.php` unit test changes (nothing decode-side
changed), no e2e/browser test changes (no new endpoint, no UI surface).

## 6. Docs impact

**No `docs/CONFIG.md` claim-table changes** — same reasoning as `edition`
today: `role`, like `edition`, never appears in `Claims::fromJwtPayload` and
is not itself a claim, so it needs no row in §2's claim tables. (Confirmed:
`edition` does not appear anywhere in `Claims.php` today — verified by
reading the full file for this spec.)

**Add to `docs/CONFIG.md` §1** ("How to set claims — one options object"),
in the existing PHP example that already demonstrates `'edition' => 'pro'`,
add a `role` line immediately below it:

```diff
     'edition'=> 'pro',                 // optional tier preset
+    'role'   => 'editor',              // optional role preset (viewer/editor/admin/superadmin)
```

...and one sentence in the prose above/below the snippet:

> `role` is a second, orthogonal DX preset (same mechanism as `edition`):
> `edition` defaults which *paid features* a tier gets; `role` defaults how
> much a *person* can do with the features already enabled (perms,
> owner-scoping, and the free power-user toggles). See
> `docs/ACL-ROLE-PRESETS-DESIGN.md` for the full claim table.

**`docs/INDUSTRY-PRESETS.md`** — optionally add one line to its "Notes"
section alongside the existing `edition` note, once implemented, since that
doc already documents `edition` as "DX sugar, not the license gate" — `role`
deserves the identical one-line caveat ("role is DX sugar for the
perms/owner-scoping layer, not an ACL store — see
ACL-ROLE-PRESETS-DESIGN.md"). Not required for this spec to land; a natural
follow-up edit alongside the implementation PR.

No `tests/unit/test-config-doc.php` changes needed — it only checks
`Claims.php`'s `$payload->` property reads, and this feature adds none.

## 7. Open questions

1. **Should `allow_terminal` scale with role after all?** This spec
   deliberately excludes it (§2) on the theory that shell access is
   high-consequence enough to always be its own explicit opt-in, never
   implied by "this person is an admin of the file manager." An operator
   building an internal ops/hosting-panel tool (the SFTP/VPS persona in
   `docs/INDUSTRY-PRESETS.md` §5) might reasonably want `superadmin` to
   include it. Left out for now; easy to add later without a breaking
   change (just add a key to the `superadmin` preset map) if real usage asks
   for it.
2. **Is `perms: ['read','write','delete','audit']` the right default for
   `admin` (not just `superadmin`)?** The task's spec draws no distinction
   between `admin` and `superadmin` on the raw `perms`/`owner_only` axis —
   both get identical capability, differing only in the prefix-scoping
   *convention* (superadmin is "safe to pair with an unscoped prefix",
   admin isn't specially blessed that way but isn't prevented either). This
   spec keeps them identical on purpose (§2's table shows one column for
   admin, superadmin repeats it) rather than inventing an artificial
   difference — flagging in case the intent was for `superadmin` to carry
   something `admin` doesn't (e.g. `allow_terminal`, per Q1).
3. **Naming: `role` vs. something more specific** (e.g. `acl_role`,
   `access_role`) to avoid any future collision with an unrelated concept
   named "role" in an adapter (Laravel's own `Role` model, WordPress's own
   role strings). Not expected to collide today (the option name lives in
   the FluxFiles-specific options array/overrides array, never merged with
   the host app's own auth objects), but worth a second look before
   shipping the exact option key.

## Status

**Implemented** in all four token builders: `packages/core/embed.php`
(`fluxfiles_role_preset()` + `fluxfiles_apply_role_preset()`),
`packages/node/src/token.ts` (`ROLE_PRESETS` + the `applyTenantOverrides()`
role block, with the `role` option added to `BaseTokenOptions` in
`packages/node/src/types.ts`), `packages/laravel/src/FluxFilesManager.php`
(`rolePreset()` + `applyRolePreset()`, wired into both `token()` and
`tokenWithByob()`), and `packages/wordpress/includes/FluxFilesPlugin.php`
(`rolePreset()` + `applyRolePreset()`, wired into both `generateToken()` and
`generateByobToken()`). §3's `perms`-early-resolution fix is implemented
exactly as specified in all four files: the role's `perms` default is
resolved *before* the base payload array/object is built, and threaded into
the same expression that already supplies the global `['read']` fallback.

**One precedence bug beyond the spec's own literal §4.1 snippet was found
and fixed during implementation:** the "explicit value and absent-key are
indistinguishable" problem the spec calls out for `perms` turns out to apply
to `owner_only` too, not just `perms`. `owner_only` is only ever written into
the payload by a truthy-only block (`if (owner_only) { payload.owner_only =
true }`), so if a caller explicitly passes `ownerOnly: false` to override an
`editor`/`viewer` role's `owner_only: true` default, the key stays absent —
and a guard that only excludes `perms` (as the spec's §4.1 snippet literally
shows) would then have the role-preset apply step incorrectly fill in
`owner_only: true` from the role default, silently breaking the "explicit
claims always win" guarantee. Fixed by excluding `owner_only` from the
post-hoc guard loop in every implementation (`fluxfiles_apply_role_preset()`,
the Node `applyTenantOverrides()` role block, and both PHP adapters'
`applyRolePreset()`), alongside `perms` — both are already correctly resolved
earlier via the same early-resolution expression, so the guard never needs to
touch them.

**Second precedence bug found and fixed after initial ship (B1):** the
viewer/editor presets originally omitted `allow_extract`/`allow_chmod`
entirely, following this doc's own §4.1 table literally under an
"absent = false" assumption. That assumption is wrong for these two specific
claims — `Claims::fromJwtPayload` (`packages/core/api/Claims.php`) defaults
`allowExtract`/`allowChmod` to **`true`** when absent (unlike
`allow_code_edit`/`show_hidden`, which correctly default `false`), because
their independent default (outside any role) is permissive. The result: an
`editor` role token — meant to be a contributor with no chmod capability —
actually resolved `allowChmod = true`, and since `editor` also gets `write`
perm, `POST /api/fm/chmod` (`FileManager.php`, requires write + `allowChmod`)
was silently reachable on SFTP disks. `viewer`'s matching gap wasn't
independently reachable (extract/chmod also require write perm, which
viewer lacks by default) but became live the moment `role: 'viewer'` was
combined with an explicit `perms` override — which this spec explicitly
permits (see "explicit overrides win" above). Fixed by setting
`allow_extract`/`allow_chmod` **explicitly** (`viewer`: both `false`;
`editor`: `allow_extract: true`, `allow_chmod: false`, matching the §4.1
table's own intent) in all four token builders, so the fix never depends on
Claims.php's own default again. The regression test in every suite
(`test-role-preset.php`, `token.test.ts`, both PHP adapter smokes) was
strengthened to assert against the **decoded, effective** claim value
(`Claims::fromJwtPayload(...)->allowExtract`, not just raw-JWT `isset()`),
since asserting only `isset()` is exactly what let B1 through undetected —
an absent key still resolves to `true` after decode. This B1 fix is what
`CHANGELOG.md`'s `[0.3.00]` — 2026-09-01 entry ("Fixed — ACL role presets:
`viewer`/`editor` could chmod on SFTP disks") documents; commits `d880b98`
(core/Laravel/WordPress) and `fb7c8a2` (Node) landed it the same day as the
initial `role` implementation (`70f1cea`).

**BYOB scope**: `role` is excluded from BYOB tokens in core
(`fluxfiles_byob_token()`/`fluxfiles_mixed_token()` are untouched, matching
`edition`'s existing exclusion there) and in Node (`createByobToken()` is
untouched). In Laravel and WordPress, `role` **does** reach BYOB tokens
(`tokenWithByob()` / `generateByobToken()`) — a deliberate choice, documented
in-code at each call site, made for consistency with those two files' existing
behavior: their `applyTenantOverrides()` is already shared between the regular
and BYOB token paths, so `edition` already reaches BYOB there too. This is a
per-adapter divergence from core, not an oversight.

**Tests**: `packages/core/tests/unit/test-role-preset.php` (15 cases — the
perms-early-resolution regression, one per-role claim bundle, explicit-override
precedence for both `perms` and `owner_only`, edition+role composition, role
never touching `prefix`/`disks`/`sub`/`max_upload`/`max_storage`/`max_files`,
and a `superadmin` + empty-prefix unscoped-token case); nine new cases added to
`packages/node/tests/token.test.ts` (30 total in that file, all passing);
one smoke test each in `packages/laravel/tests/test-laravel-smoke.php` and
`packages/wordpress/tests/test-wp-smoke.php` (the latter also covers the
BYOB-inclusion decision above). The full core unit + integration suite,
`npm run typecheck`, and `npm run build` in `packages/node/` all pass with no
regressions.

**Docs**: `docs/CONFIG.md` §1's PHP example now shows `'role' => 'editor'`
alongside `'edition' => 'pro'`, with a short explanatory paragraph — `role`
is not itself a claim, so no `docs/CONFIG.md` claim-table entry or
`test-config-doc.php` change was needed.

The §7 open questions (scaling `allow_terminal` with role, whether
`admin`==`superadmin` is the right call, and the `role` naming-collision risk
against app-specific "role" concepts) remain deliberately unresolved, as the
spec allows.
