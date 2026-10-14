# Multi-prefix tokens — Design Spec

Status: **Draft for review — not implemented.** Free/core. Touches the
path-scoping core (`Claims::scopePath()` / `isPathInScope()` /
`unscopePath()`), which 93 call sites across core and both proxy adapters
depend on, so it gets a spec and a security review before any code.

---

## 1. Problem

A token has exactly one `prefix`. A team member who needs **their own
folder plus a shared team folder** (or a contractor who needs two client
folders) currently needs two tokens and two iframes, or the operator widens
the prefix to a common parent — which exposes every sibling folder too.

## 2. Proposal — claim shape

```json
"roots": [
  { "label": "Me",     "prefix": "users/42" },
  { "label": "Team A", "prefix": "teams/a", "perms": ["read"] }
]
```

- `roots` is **mutually exclusive with `prefix`**. Present → `prefix` is
  ignored and an empty-string root is refused (an unscoped root inside a
  multi-root token would silently become "everything").
- Max 10 roots. `label`: 1–40 chars from `[A-Za-z0-9 _.-]`, unique per
  token. `prefix`: normalized like today, non-empty, and **no root may be an
  ancestor of another** (refused at decode — overlapping roots make "which
  root does this key belong to" ambiguous).
- Per-root `perms` is optional and can only **narrow** the token's `perms`
  (intersection), never widen.

## 3. Client-visible paths

A multi-root token sees a virtual top level:

```
/                    ← list() returns one folder per root (type "root")
/Me/…                ← users/42/…
/Team A/…            ← teams/a/…
```

The first path segment is the **label**. `scopePath('Team A/x.pdf')` →
`teams/a/x.pdf`; `unscopePath('teams/a/x.pdf')` → `Team A/x.pdf`. A path
whose first segment is not a label → `403 path_denied`. The root listing is
synthesized (no storage call); writing at `/` itself (upload, mkdir,
rename of a root) → `403`.

Single-prefix tokens are untouched: no virtual level, identical paths.

## 4. Enforcement — where each check moves

| Concern | Today | With roots |
|---|---|---|
| Scope a client path | `scopePath()` prefixes | resolve label → that root's prefix, then the same strip-dot-segments + idempotency rules per root |
| "Is this absolute key mine?" | `isPathInScope()` vs one prefix | true if inside **any** root (and no dot segments) |
| Present a key | `unscopePath()` strips prefix | strip the **matching** root's prefix, prepend its label |
| Permission check | `hasPerm($p)` | `hasPermAt($p, $scopedKey)` = token perm ∧ root perm. Every FileManager `assertPerm()` call that has a path in hand switches to it. |
| Cross-root move/copy | n/a | allowed only when the destination root grants `write` (copy) / source root grants `delete` (move); same-disk `move()` between roots is a cross-prefix move — check both ends |
| Search / folder search | `pathPrefix` filter | OR over the roots' prefixes; results keyed by label path |
| Audit `/audit` list | `isPathInScope()` | unchanged API (it already calls `isPathInScope()`) |
| Quota / usage | per `pathPrefix` | per root (usage dashboard gets a root picker); `max_storage` applies **per root** — documented |
| Trash | `trashVisible()` by prefix | visible if the original key is in any root |
| Fixity / duplicates / retention | `scopedPath('')` | run per root (duplicates: across roots, since that's where the waste is) |
| Rate limits, BYOB, `owner_only` | per token | unchanged |

Implementation shape: a small `PathScope` value object (roots, perms) that
`Claims` builds; `scopePath()`/`isPathInScope()`/`unscopePath()` delegate to
it. Single-prefix tokens get a one-root `PathScope` with an empty label and
**no virtual level**, so existing behaviour is byte-for-byte the same — the
whole existing suite must pass unmodified before any multi-root test is
added.

## 5. Threat model

1. **Label confusion** — a label equal to a real folder name of another root
   (`/Me/…` vs a folder literally called `Me`). Resolution is on the first
   segment only, labels are a closed set from the signed token, and the
   synthesized root listing never shows storage folders → no ambiguity.
2. **Sibling escape** — today's "parent of the prefix" guard in `scopePath()`
   generalizes to: after resolving a label, the result must stay inside that
   root (same checks). A path that names root A but traverses (`..`) toward
   root B is rejected by the existing dot-segment stripping before
   resolution, as today.
3. **Perm widening** — per-root perms only intersect. A root without `delete`
   makes `trash()`/`delete()`/retention refuse in that root even though the
   token has `delete`.
4. **Mixed-root bulk ops** — zip/move/copy of a selection spanning roots
   checks each item against its own root.
5. **Proxy adapters** — both proxies forward the claim and use the same
   `Claims`; their own `isPathInScope()` call sites (metadata/chunk routes)
   inherit the new semantics. Floor guard: adapters need the core release
   that ships `PathScope`.

## 6. Out of scope (v1)

- Roots on **different disks** in one token (`{disk, prefix}` pairs). The
  current UI is one-disk-at-a-time; revisit with a disk-aware root picker.
- Renaming/reordering roots from the UI (they're operator claims).

## 7. Test plan

- Whole existing PHP + browser suite passes **unchanged** with the refactor
  alone (single-prefix path).
- New unit tests: label resolution, unknown label → 403, overlapping roots
  refused at decode, per-root perm narrowing, cross-root move/copy rules,
  root listing synthesis, search/trash/audit visibility across roots.
- New e2e: a two-root token can't reach a third tenant via `..`, encoded
  separators, label lookalikes (`Me ` / `me`), or a root's sibling.
- Browser: virtual root level renders, breadcrumbs show the label, upload at
  `/` is disabled.

## 8. Open questions for the owner

1. Per-root `max_storage` (independent quotas) or one quota summed across
   roots? (Spec assumes per root — simpler and matches today's per-prefix
   accounting.)
2. Should duplicates/fixity/retention run across all roots by default, or
   per root chosen in the UI?
3. Builders: expose `roots` as a first-class option in all five, or only via
   the generic `claims` escape hatch until it settles?
