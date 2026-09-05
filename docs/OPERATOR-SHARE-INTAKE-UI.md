# Spec — Operator UI for Share links and Intake portals

> Status: implemented and shipped. Implements the approved plan
> `.claude/work/plan-operator-share-intake-ui.md` (decisions there are settled input). Scope:
> the **free MIT core** standalone UI (`packages/core/assets/fm.js` + `fm.css` +
> `public/index.html` + `lang/*.json`), plus four prerequisite fixes in the gitignored paid
> packages and the two PHP adapters. See `51bcc2f feat(core): operator UI for Share links and
> Upload portals` + follow-ups (`509aa8e`, `d36c7cd`, `1f8b2ec` WordPress support).

## 0. One objection to the settled decisions

None to the build/free-core/three-state calls — they hold. One caveat about a *consequence*
nobody costed: the i18n bill is **~50 keys × 16 locales**, not the plan's "~30". Two panels,
two create forms with disjoint fields, a one-shot reveal, a locked state and five error codes
do not compress below that without reusing noun-bearing strings across Share and Portal, which
mistranslates in the declined/gendered locales (ru/tr/ar/de). §7 splits the keys into a
noun-free shared namespace (`links.*`) and two noun-bearing ones precisely to keep the count
honest rather than to hide it. Budget accordingly; nothing else in the plan changes.

---

## 1. Problem & who pays

Pro (= `share` + `intake`) is fully implemented server-side and has **no client**. An operator
who buys it, installs both private packages and opens `/public/` gets a file manager with no
button — the only interface is hand-writing `POST /api/fm/share` against a self-minted JWT.
This is a **delivery defect on an already-sold SKU**, not a new feature.

- **Persona A (agency / freelancer / WP implementer)** — "send the client their deliverable
  with an expiry and a password, and collect their assets back, without a WeTransfer seat."
  A does not write API calls. For A, Pro without this UI is unusable at any price. The
  competitive frame is real: WeTransfer's current lineup is Free / Starter / **Ultimate $25 a
  month**, and the Teams plan is **$25 per user per month, 2-seat minimum** — a per-seat
  recurring cost for exactly "expiring link + password + file request"
  ([GoodSend](https://goodsign.io/blog/wetransfer-pricing),
  [ITQlick](https://www.itqlick.com/wetransfer/pricing)).
- **Persona B (SaaS operator)** — "let my end users share and collect files." B resells the
  embedded surface, not the API, so the buttons must exist inside the iframe.

**Free or paid: the UI is FREE/core.** All minting, enforcement, counters, tombstones and
licensing stay in the paid packages. Mechanically, a gitignored package cannot inject markup,
JS, CSS or locale keys into a zero-build Alpine/htmx bundle; putting the client in the module
means inventing an asset-injection system for one consumer. Without the module, every button
here gets a `501` and the panel is an empty shell — core supplies only the chrome.

**Guardrail:** a neutral client. It renders what the module returns. No branding controls, no
custom-domain field, no analytics view — those stay monetizable (Share+).

---

## 2. Architecture fit

Nothing new is stateful.

| Concern | Where it lives | New? |
|---|---|---|
| Share/portal records, counters, tombstones | `<store>/_fluxfiles/shares.json`, `_fluxfiles/intakes.json` | no (exists) |
| Recipient link base | `share_base_url` / **`intake_base_url`** claims | one new claim |
| Whether this tenant may create links | `allow_share` / `allow_intake` claims | no |
| Whether the server is entitled | Ed25519 license → `GET /api/fm/license` | no |
| Whether to advertise Pro | **`pro_hints`** claim + framed-ness (runtime) | one new claim |
| The recipient link itself | the token, returned **once**, held only in the operator's clipboard | no |

No DB, no queue, no scheduler, no new server process, no new persisted state. The UI holds the
one-shot token in a JS variable for the lifetime of one modal and never writes it anywhere.
Two new routes are added to nothing: the six operator endpoints already exist and are already
behind the `ModuleRegistry` 3-layer gate.

---

## 3. JWT claims

Two new claims. Both are parsed in `packages/core/api/Claims.php` and documented in
`docs/CONFIG.md` §2.13 (see §10 for the exact rows).

### `intake_base_url`
- **Type** string (http/s), **default** `''`.
- **Parse** (mirror of `share_base_url`'s own block immediately above it, `Claims.php:608-609`;
  this snippet itself is `Claims.php:615-616`):
  ```php
  $intakeBase = trim((string) ($payload->intake_base_url ?? ''));
  $c->intakeBaseUrl = preg_match('#^https?://#i', $intakeBase) ? $intakeBase : '';
  ```
  Anything non-http(s) is dropped, so it can never become a `javascript:`/`data:` link in a
  host UI. Property: `public string $intakeBaseUrl = ''`.
- **Meaning** public base the intake create response builds the recipient link from
  (e.g. `https://files.acme.com/public/intake.html`). Empty → the request origin +
  `/public/intake.html` (fallback in `index.php`, §4.2).
- **Forwarded by** node (`intakeBaseUrl` option + raw `claims` passthrough), and — as of the
  §6.3 fix — Laravel and WordPress **unconditionally, in every mode** (§6.3).

### `pro_hints`
- **Type** bool, **default** `true`.
- **Parse**: `$c->proHints = isset($payload->pro_hints) ? (bool) $payload->pro_hints : true;`
  Property: `public bool $proHints = true`.
- **Meaning** the hard off switch for the locked "Pro" affordance. `false` → the UI never
  renders a paid-module teaser under any condition. Read **only by the UI**; no endpoint
  behaviour changes. For operators shipping free core in production who do not want an upsell
  in their product.
- Default `true` is deliberate: the affordance is already bounded to *unlicensed **and**
  unframed* (§5.1), i.e. the Docker/standalone evaluation, where there is no paying operator to
  embarrass.

**No other claims.** TTL presets, default labels and column choices are UI constants, not
config — adding claims for them would re-open the "neutral client" guardrail.

---

## 4. Endpoints

**No new routes.** All six operator endpoints exist and are unchanged apart from one fallback
line (§4.2). All are **operator-authed** (`Authorization: Bearer <main JWT>`) and behind
`ModuleRegistry::require` → `501 module_not_installed` / `402 license_required` /
`402 license_expired` / `403 allow_<x>_forbidden`.

### 4.1 Consumed as-is

| Method | Path | Request | Response (relevant fields) |
|---|---|---|---|
| POST | `/api/fm/share` | `{disk, path, ttl?, label?, password?, max_downloads?}` | `{token, jti, expires, label, max_downloads, has_password, url}` |
| GET | `/api/fm/share/list?disk=` | — | `[{jti, disk, path, label, created, expires, max_downloads, downloads, views, unlock_fails?}]` |
| POST | `/api/fm/share/revoke` | `{disk, jti}` | `{revoked: bool}` |
| POST | `/api/fm/intake` | `{disk, path, ttl?, label?, password?, max_files?, max_mb?, allowed_ext?}` | `{token, jti, expires, label, max_files, max_mb, allowed_ext, has_password, url*}` |
| GET | `/api/fm/intake/list?disk=` | — | `[{jti, disk, path, label, created, expires, max_files, max_mb, allowed_ext, received}]` |
| POST | `/api/fm/intake/revoke` | `{disk, jti}` | `{revoked: bool}` |
| GET | `/api/fm/license` | — | `{edition, status, modules[], limits, expires, days_left}` |

`*` `url` on intake does not exist today — §4.2.

Public (token-authed, pre-JWT) routes are untouched: `/api/fm/share/{info,unlock,file}`,
`/api/fm/intake/{info,upload}`.

### 4.2 The one core change — a recipient URL for Intake (prerequisite 1)

Before this fix, `POST /api/fm/share` got a `url` two ways (module from `share_base_url`, else
a fallback inline in `index.php`); `POST /api/fm/intake` got neither, so a UI that said "here is
your portal link" had nothing to show. Both branches now call the shared helper below (share at
`index.php:772`, intake at `index.php:812-813`).

Fix, mirroring Share exactly:

1. **Module** (`packages/intake/src/IntakeModule.php::createPortal`) returns
   `'url' => $claims->intakeBaseUrl !== '' ? $base . (str_contains($base,'?') ? '&' : '?') . 'token=' . rawurlencode($token) : ''`
   — a literal mirror of `ShareModule.php:139-144`. Verified against the current source of
   both private packages (re-read directly from disk — they are gitignored **from git**, not
   absent from the filesystem; see §6.2's updated note): `IntakeModule.php` builds `$url` from
   `intakeBaseUrl` at `:152-157` and returns it as the `url` field at `:168`, matching this
   description exactly.
2. **Core** (`packages/core/api/index.php`, the `POST /api/fm/intake` branch starting at :804,
   fallback call at :812-813) falls back to the request origin + the core landing page, with the
   same comment as the share branch's, at :767-770.

**Extract the duplicated line into a helper** so the fallback is unit-testable in free core
(today it is inline and only reachable with a paid module installed, i.e. untestable in CI):

```php
/** Public recipient link for a one-shot module token, when the module built none. */
function ff_public_link_url(string $page, string $token): string
{
    return ff_request_origin() . '/public/' . $page . '?token=' . rawurlencode($token);
}
```

Both branches call it (`'share.html'`, `'intake.html'`). Behaviour is byte-identical for Share.

---

## 5. UI design

### 5.1 Three-state gating (the part most likely to regress)

One helper in `fm.js`, used by both features. **Carry the reasoning into the code comment** —
this is a deliberate, bounded departure from the `optimize`/`terminal` precedent and a later
reader must not "fix" it:

```js
// Paid-module UI gate — three states, discriminated by LICENSE, not only by claim.
//
//   'on'     claim on               → the full panel (the server still enforces).
//   'hidden' claim off, but this server IS licensed for the module → render nothing.
//            The operator has deliberately withheld the feature from this tenant;
//            advertising it inside their product would sell against them.
//   'locked' claim off AND the server is unlicensed AND we are NOT framed
//            (top-level /public/, i.e. the Docker evaluation) AND pro_hints is on
//            → a small "Pro" affordance with a docs link.
//
// This is NOT the optimize/terminal precedent (claim-gated, invisible when off) and
// that is on purpose: those are capability toggles whose absence is meant to be
// invisible; Share/Intake IS the SKU, and an unlicensed server has no paying
// operator to embarrass. The departure is bounded to the unlicensed, unframed case.
// Do not "simplify" this to tokenAllows() alone. See docs/OPERATOR-SHARE-INTAKE-UI.md §5.1.
proGate(claim, moduleId) { /* 'on' | 'hidden' | 'locked' */ }
```

Resolution order (short-circuits so the common paths cost **zero extra requests**):

1. `this.tokenAllows(claim, false)` → `'on'`. *(licensed tenants never fetch the license)*
2. `window.parent !== window` → `'hidden'`. *(every embedded/adapter path exits here)*
3. `this.tokenAllows('pro_hints', true) === false` → `'hidden'`. *(hard off switch)*
4. `this.licenseInfo === null` → fire `loadLicense()` once (memoized via the
   `_licenseFetched`/`_licenseInFlight` fields inside `loadLicense()`, `fm.js:3159`) and return
   `'hidden'` for this tick; Alpine re-renders when it resolves.
5. `(Array.isArray(this.licenseInfo.modules) ? this.licenseInfo.modules : []).includes(moduleId)`
   → `'hidden'`; else → `'locked'`.

So the only context that pays an extra `GET /api/fm/license` is *unframed + claim off +
pro_hints on* — the evaluation case. Getters: `get shareGate()` → `proGate('allow_share','share')`,
`get intakeGate()` → `proGate('allow_intake','intake')`.

Additional capability preconditions (ANDed with `'on'`, not part of the gate):
Share needs `read` and a **file**; Intake needs `write` and a **folder**.

> The public demo (`DemoMode`) is free core and top-level → it shows the locked affordance.
> That is intended (it is the funnel); if not wanted, the demo token sets `pro_hints:false`.

### 5.2 Entry points

- **Detail panel** (`public/index.html` `.detail-actions`, alongside `canOptimizeFile` /
  `canChmod` at :965-972):
  - `x-show="shareGate === 'on' && detailFile?.type !== 'dir'"` → **Share link**.
  - `x-show="intakeGate === 'on' && detailFile?.type === 'dir'"` → **Upload portal**.
- **Toolbar icon** in the `ff-theme-toggle-sm` cluster next to Activity/Usage/Trash — the
  `x-show="linksToolbarState === 'on'"` button, currently `index.html:213` — opens the **Links
  panel**, shown when either gate is `'on'`.
- **Locked affordance**: when either gate is `'locked'`, the same toolbar icon renders with a
  lock glyph and a `Pro` pill instead (`x-show="linksToolbarState === 'locked'"`,
  `index.html:216`); clicking opens a small modal (title, one sentence per feature, a docs
  link, Close). It issues **no API calls**. Nothing appears in the detail panel in this state —
  one affordance per app, not one per file.

### 5.3 Create modal — shared shell, disjoint body

**One component, two configurations.** Share and Intake use the same modal shell, the same
label/TTL/password rows, the same submit/one-shot flow, the same error mapping.

| Shared (one implementation) | Must NOT be shared |
|---|---|
| Modal shell + `ff-modal-overlay` chrome, escape/backdrop close | The create endpoint + the field set |
| Label input, TTL select, password input, busy state | TTL bounds: Share `[60s, 30d]` default 7d; Intake `[300s, 90d]` default 14d |
| One-shot reveal + copy + close-guard | Share `max_downloads`; Intake `max_files` / `max_mb` / `allowed_ext[]` |
| Revoke + confirm + toast plumbing | Target kind (file vs folder) and its validation |
| `proGate`, error mapping, list-table shell | List columns (`views`/`downloads` vs `received`) |
| Noun-free i18n (`links.*`, `duration.*`) | Every noun-bearing string (`share.*`, `intake.*`) |

The last row is load-bearing: do **not** build one string with a `{noun}` parameter. In
ru/tr/ar/de "share link" and "upload portal" decline differently and a shared template
mistranslates in 16 locales at once.

Field shapes:
- **TTL** `<select>` from `duration.*`: Share `1h / 24h / 7d / 30d`; Intake `24h / 7d / 30d / 90d`.
  Values are seconds; the module clamps regardless.
- **Password** `type=password`, `autocomplete="new-password"`, optional, POST body only.
- **`allowed_ext`** a comma-separated text input → `split(/[,\s]+/)` → lowercased array; the
  module re-normalizes (`^[a-z0-9]+$`).
- **`max_mb` / `max_files` / `max_downloads`** `type=number`, empty → omitted (module default /
  unlimited).
- Submit is disabled while in flight (`linksBusy`). **Double-clicking must not mint two live
  links** — a duplicate share is a real, revocable-only artifact.

### 5.4 One-shot link presentation (prerequisite 4)

The token is returned once and never stored (`index.php:769` for share, mirrored for intake at
`:810-811`). Design *around* that; do not weaken it.

On success the modal swaps to a reveal state:

- The URL in a **read-only `<input>`**, auto-selected, plus a **Copy** button.
  Reuse the clipboard/`execCommand` fallback via `copyText(str)`, already extracted out of
  `copyUrl(file)` (`copyText` at `fm.js:1919`, `copyUrl` at `fm.js:1940` calling it at `:1947`).
  `navigator.clipboard` needs a secure context; the fallback keeps `http://localhost` dev working.
- A prominent, non-dismissible line: `links.once_warning` — *"This link is shown once. Copy it
  now — it can't be recovered later, only revoked."*
- Closing before a successful copy → `links.close_confirm` *("You haven't copied the link yet.
  Close anyway?")*. Copying is tracked in component state only.
- **Never** render the raw `token`. Only `url`.
- **Degenerate case** (`url` empty — should be impossible after §4.2): show `links.no_url`,
  naming `share_base_url` / `intake_base_url`, plus a Revoke button for the `jti` just created.
  Never reconstruct a URL client-side from the token.
- `postMessage` emits `FM_EVENT {event:'share:created', jti, expires, has_password}` — **no
  token, no url**. A host that wants the link calls the API itself.

### 5.5 Links panel — what a non-displayable link shows

A tabbed modal in the trash/activity style (`index.html:1932`, `.ff-trash-modal` shape),
`z-index:60`, `x-cloak`, escape-closable. Tabs **Shared links** / **Upload portals**, each shown
only if its own gate is `'on'`; if exactly one is, it opens on that tab and the tab bar is
hidden. Loads on open (`GET .../list?disk=<currentDisk>`), scoped to the current disk — with a
one-line note that the list is per-disk and stored in the tenant's own storage.

Columns:

| Shared | Share tab | Intake tab |
|---|---|---|
| Label (falls back to the target's basename) | Views | Received |
| Target path (`title` = full key) | Downloads / limit | Files limit, per-file MB |
| Created, Expires (relative + absolute in `title`) | | Allowed extensions (or "any") |
| Lock glyph when `has_password` | | |
| **Link column: `links.link_hidden`** — a muted "Not shown" with a `title`/tooltip: *"For security the link is shown only once, when it is created. It can be revoked but not re-displayed."* | | |
| Revoke button + confirm | | |

- An entry whose `expires` is in the past renders an `links.expired` badge and a muted row;
  Revoke stays enabled (revoking an expired record is harmless and clears it).
- Empty list → `share.empty` / `intake.empty`.
- After a successful revoke: re-fetch the list, toast `links.revoked`.
- Password is never displayable — the module strips `password_hash`; the UI shows only the
  lock glyph from `has_password`.
- **No re-link, no edit.** There is no update API; editing expiry/password is out of scope (v2).

### 5.6 Error handling for the gate

`this.api()` already maps `error_code` → `t('error.' + code)` and falls back to the raw message
(`fm.js:594-619`), and sets `err.code`/`err.params`. So:

- `module_not_installed` (501), `license_required` (402) and `license_expired` (402) already
  have `en.json` entries (`:292`, `:323`, `:324`) → they surface localized without any new key.
- **What actually shipped is not two new per-module keys.** `ModuleRegistry::require()`
  (`ModuleRegistry.php:101`) raises the refusal as `$claim . '_forbidden'` — a code built at
  runtime from whatever claim name was passed in (`allow_share_forbidden`,
  `allow_intake_forbidden`, and every other module's `allow_<x>_forbidden` alike) — so there is
  no fixed literal a translation file could key on ahead of time, and a dedicated key per
  module would need re-adding for every future module. `fm.js`'s `api()` instead falls back,
  when `error_code` matches `/^allow_[a-z0-9_]+_forbidden$/` and no exact key exists, to one
  generic `error.module_forbidden` key with a `{module}` param (`fm.js:608-613`; the key text
  itself, *"This access token is not allowed to use {module}."*, is `en.json:325`). Neither
  `allow_share_forbidden` nor `allow_intake_forbidden` exists, or is needed, as a literal key —
  do not add them.
- The create modal renders these **inline in the modal body as a terminal state** (like the
  URL-import error state), not as a transient toast, and hides the submit button — a 501 is not
  a retryable failure. The list panel does the same in its body.
- On `module_not_installed` / `license_required` / `license_expired` the panel additionally
  shows the same docs link as the locked affordance. It does **not** silently switch to the
  locked state (the claim is on; the operator's install is broken, and saying so is the useful
  message).

---

## 6. Prerequisite fixes outside core's UI

> These are in gitignored private packages and the adapters. **Never stage the private package
> files.** Listed here because the UI is not shippable without them.

### 6.1 Intake recipient URL — see §4.2 (prerequisite 1)

### 6.2 `owner` on the intake record + `owner_only` (prerequisite 2) — confirmed already shipped

> **Updated after re-reading the private source.** This subsection previously described a gap
> in `packages/intake/` that this doc could not verify (the package is gitignored **from
> git**, but that does not mean absent from the filesystem — it and `packages/share/` have now
> been re-read directly). **The gap does not exist in the current source.** `owner`,
> `owner_only` filtering in both `listPortals` and `revokePortal`, and tombstone-based
> revocation are all already present, matching in substance what this section originally
> proposed as a fix. Kept below in the same numbered form, corrected to describe what shipped
> (not what remains to do), with accurate current line numbers.

`IntakeModule::createPortal` writes `'owner' => $claims->userId` in the record
(`IntakeModule.php:117`, inside the `$record` array spanning `:112-144`), and `listPortals`
(`:390-407`) filters on it (`:397-399`) — matching `ShareModule`'s own `owner` write
(`ShareModule.php:114`), tombstone-skip-then-owner-filter in `listShares` (`:342-347`), and the
`revokeShare` owner check (`:317-319`).

1. **Record**: `owner` is present in `createPortal`'s `$record` (`IntakeModule.php:117`),
   mirroring `ShareModule.php:114`.
2. **`listPortals`**: skips tombstones (`IntakeModule.php:394-396`) then filters on
   `owner_only` (`:397-399`), mirroring `ShareModule.php:342-347`.
3. **`revokePortal`**: throws `403 perm_denied` when `$claims->ownerOnly` and the owner differs
   (`IntakeModule.php:360-362`), mirroring `ShareModule.php:317-319`.
4. **Tombstone, not `unset`.** `revokePortal` (`IntakeModule.php:354-375`) writes
   `{jti, revoked:true, expires, owner}` (`:366-371`) instead of unsetting the entry — the same
   fix `ShareModule::revokeShare` already carries (`ShareModule.php:299-332`, tombstone write at
   `:320-329`) for the identical reason: `receiveUpload` rewrites the whole tenant map on every
   upload (`IntakeModule.php:272-295`, counter bump at `:291-293`), so a revoke interleaved with
   an in-flight upload under a plain `unset` would resurrect the portal. The shape matches what
   this section originally specified: `resolveToken` (`:414-439`) treats
   `!empty($rec['revoked'])` as `404 intake_revoked` (`:433-436`), and `save()` (`:606-623`)
   prunes tombstones past `expires` (`:611-621`).

**Migration — portals created before `owner` existed.** Still fails **closed**, exactly like
Share, and unaffected by this update: `($rec['owner'] ?? null) !== $claims->userId` is true for
a missing owner, so under `owner_only` a legacy portal is invisible and un-revokable in the UI.

- Tenants **without** `owner_only` (the majority) are unaffected — the filter is a no-op and
  every legacy portal keeps listing normally.
- Under `owner_only`, a legacy portal **keeps working publicly** until it expires. Intake's
  hard cap is 90 days, so the window is bounded and self-clearing.
- Remedy, in order: (a) list/revoke with an operator token that does **not** set `owner_only`
  — this is the documented escape hatch and needs no code; (b) edit
  `<prefix>/_fluxfiles/intakes.json` by hand.
- **`owner` is not backfilled on read or on revoke** — confirmed in current source: neither
  `listPortals` nor `revokePortal` writes `owner` onto a record that lacks it. Backfilling on
  read would hand ownership of another user's portal to whoever lists first, the exact leak
  this behavior avoids.

### 6.3 Adapters proxy Share/Intake in full — this prerequisite shipped differently than planned

> **Status check, since what shipped is broader than the plan below.** The original plan
> (forward `allow_share`/`allow_intake` only when Laravel is in `standalone` mode; strip them
> from WordPress entirely, "the plugin is proxy-only") assumed Share and Intake had **no**
> proxied routes, so forwarding the claims anywhere but standalone Laravel would render a
> button that 404s. That assumption is no longer true. Verified against the current adapter
> source (both are free/MIT and readable):
>
> - Both adapters now proxy **all eleven** Share/Intake routes — the six operator ones
>   (create/list/revoke × 2) plus analytics, plus the public recipient landing routes
>   (`info`/`unlock`/`file` for Share, `info`/`upload` for Intake) — via
>   `FluxFilesController::shareIntake()`/`publicLink()` in
>   `packages/laravel/src/Http/Controllers/FluxFilesController.php` and
>   `FluxFilesApi::shareIntake()`/`publicLink()` in
>   `packages/wordpress/includes/FluxFilesApi.php`. There is no 404 risk left to guard against.
> - **Laravel** (`packages/laravel/src/FluxFilesManager.php:294-298`) forwards `allow_share`/
>   `allow_intake` **unconditionally, in every mode** — not gated by
>   `config('fluxfiles.mode')` — together with `share_url_ttl`/`share_base_url`/
>   `share_preview`/`share_analytics`/`intake_base_url`/`intake_analytics` (:299-320). The code
>   comment at :290-293 states the reasoning directly: *"Share + Intake now have routes in both
>   modes (proxy: FluxFilesController's shareIntake()/publicLink() dispatchers; standalone:
>   index.php), so the gate claims — and the config that travels with them — forward
>   unconditionally, matching WordPress's FluxFilesPlugin."*
> - **WordPress** (`packages/wordpress/includes/FluxFilesPlugin.php:468-500`) mirrors this: the
>   same claims forward unconditionally, with its own comment noting the REST API "now proxies
>   all eleven ... so the claims are forwarded like any other" (:468-471).
> - Route-parity guard tests confirm neither adapter's unproxied allowlist contains any
>   Share/Intake route: Laravel's `$intentionallyUnproxied` in
>   `packages/laravel/tests/test-laravel-smoke.php` (~:383-405: `chmod`, `zip`, `sso/*`,
>   `metadata/export`, `metadata/import` only), and WordPress's equivalent test —
>   `'proxy route surface covers every core /api/fm route'`
>   (`packages/wordpress/tests/test-wp-smoke.php:1102`, allowlist ~:1140-1144) — which is the
>   **same guard `docs/SHARE-PUBLIC-LANDING.md` §8 previously said WordPress lacked**; that
>   claim there is also being corrected as part of this pass.
> - Claim forwarding itself is asserted directly too: Laravel's smoke test around :222-253, and
>   WordPress's around :328-345 (plus a dedicated `share_base_url` override test ~:791-806).
>
> `allow_ai_vision`/`allow_ocr`/`allow_backup`/`allow_c2pa`, called out below as "out of scope"
> when this section was first written, are now proxied too, for the identical reason (each
> claim's own forwarding-site comment says so). That happened separately and is not
> re-litigated here.
>
> The bullets immediately below are the **original, superseded plan**, kept only so a reader
> can see what was intended vs. what shipped — they are **not** current instructions:
>
> - ~~Laravel: forward `allow_share`/`allow_intake` + landing config only when
>   `config('fluxfiles.mode') === 'standalone'`.~~ Shipped unconditional instead, once the
>   proxy routes existed.
> - ~~WordPress: strip `allow_share`/`allow_intake`/`share_*` entirely ("dead config" on a
>   proxy-only plugin).~~ Shipped forwarded instead, for the same reason.
> - **No core floor bump needed** — the adapters only write payload array keys; they call no new
>   core `Claims` API. (`scripts/check-adapter-core-floor.sh` will confirm.)
> - **Node** (`packages/node/src/token.ts`): add an `intakeBaseUrl` option next to
>   `shareBaseUrl`. Node mints tokens for a real core, so no mode condition ever applied here.

---

## 7. Storage layout

**No new files.** For reference, the two existing stores (one JSON map per tenant prefix,
read-modify-write, storage-resident):

`<prefix>/_fluxfiles/shares.json` — `{ "<jti>": {...} }`, entries either a record or a tombstone.

```jsonc
{ "jti":"…","disk":"local","path":"…","store":"…","owner":"u42","label":"Q3",
  "created":1753,"expires":1754,"max_downloads":5,"downloads":2,"views":9,
  "url_ttl":60,"preview":true,"password_hash":"…","unlock_fails":0 }
{ "jti":"…","revoked":true,"expires":1754 }                     // tombstone
```

`<prefix>/_fluxfiles/intakes.json` — same shape; **already includes `owner` and
tombstone-based revocation in the current source** (§6.2 — verified, not a pending fix).

```jsonc
{ "jti":"…","disk":"local","path":"clients/acme/in","store":"…","owner":"u42",
  "label":"Send assets","created":1753,"expires":1754,
  "max_files":20,"max_mb":50,"allowed_ext":["jpg","pdf"],"received":3,
  "password_hash":null }
```

`password_hash` is stripped from every list response; the UI only ever sees `has_password`.

---

## 8. Security

- **SSRF: none.** No outbound fetch is added. The only URL-ish inputs are `share_base_url` /
  `intake_base_url`, validated `^https?://` in `Claims` and used server-side only for string
  concatenation.
- **The `url` from a create response is rendered as text, never executed.** It goes into a
  read-only `<input value>` and the clipboard. If it is ever made a clickable `<a href>`, the
  UI must re-check `/^https?:\/\//i` first — the same lesson `share.html` already carries a
  regression test for (the "hostile `preview_url` never reaches `iframe.src`" case in
  `share-landing.spec.ts`, currently around :307-326). It is never assigned to `iframe.src` and
  never rendered with `x-html`.
- **Token hygiene.** The one-shot token exists only in a component variable for one modal.
  Never in `localStorage`, never in `postMessage`, never in a toast, never logged, never in the
  audit view. `FM_EVENT` carries `{jti, expires, has_password}` only.
- **`owner_only`** — closed for Intake list *and* revoke (§6.2), fail-closed for legacy records.
  Already enforced for Share.
- **Path scoping.** Create sends the key exactly as `list()` returned it (prefix-relative); the
  modules re-scope via `validateUserPath` + `assertCanModifyScopedPath`. The UI never sends an
  absolute or prefix-prepended path and never lets the user type a free-form target.
- **Rate/size caps.** None new. Submit is disabled in flight so a double click cannot mint two
  live links; revoke is confirm-gated. The existing per-user write bucket covers abuse.
- **Password** never enters a query string (POST body only) and is never echoed back.
- **Signing/HMAC** unchanged — tokens are minted by the modules with `FLUXFILES_SECRET` and
  carry `t=share` / `t=intake`, which `JwtMiddleware` refuses as access tokens.
- **The locked affordance never renders when framed**, so an operator's end users can never see
  an ad inside a resold product; `pro_hints:false` disables it everywhere.
- **Error codes needing i18n ×16** — all pre-existing, none new for this feature:
  `module_not_installed`, `license_required`, `license_expired`, `perm_denied`, `disk_denied`,
  `bad_request`, `not_found`, plus the generic `module_forbidden` (`{module}` param) that
  already covers every `allow_<x>_forbidden` code at runtime, Share/Intake included — see §5.6.
  No per-module forbidden key exists, or is needed.

---

## 9. Package layout

**Free / core (MIT, staged):**

| File | Change |
|---|---|
| `packages/core/assets/fm.js` | `proGate()` + `shareGate`/`intakeGate` getters, create-modal state ×2, `linksPanel` state, `createShare()`/`createPortal()`, `loadLinks()`, `revokeLink()`, `copyText()` extracted from `copyUrl()` |
| `packages/core/assets/fm.css` | Links panel/table (reuse `.ff-activity-*`), one-shot reveal block, `Pro` pill + lock glyph |
| `packages/core/public/index.html` | 2 detail-panel buttons, 1 toolbar icon, create modal ×2, links panel modal, locked modal |
| `packages/core/api/Claims.php` | `intakeBaseUrl`, `proHints` + parsing |
| `packages/core/api/index.php` | `ff_public_link_url()` helper; intake `url` fallback |
| `packages/core/lang/*.json` (16) | ~50 keys (§10) |
| `docs/CONFIG.md` | 2 claim rows (§12) |
| `CHANGELOG.md` | entry |
| `.claude/api-map.md` | note the intake `url` field |

**Private / gitignored (never staged; re-read directly from disk for this pass — see §6.2's
updated note):**

| File | Change |
|---|---|
| `packages/intake/src/IntakeModule.php` | `url` from `intake_base_url`; `owner` on the record; `owner_only` in `listPortals`/`revokePortal`; tombstone revoke — all confirmed already present in current source (§6.2), not outstanding work |
| `packages/intake/tests/*` | the tests in §11 |

**Adapters (staged):** `packages/laravel/src/FluxFilesManager.php`,
`packages/laravel/tests/test-laravel-smoke.php`,
`packages/wordpress/includes/FluxFilesPlugin.php`, `packages/wordpress/tests/*`,
`packages/node/src/token.ts` + `packages/node/tests/token.test.ts` (+ `package.json` bump).

**Not touched:** React/Vue/SDK/editor wrappers — this is inside the iframe. No `postMessage`
command is added.

---

## 10. i18n keys (all 16 `packages/core/lang/*.json`)

Split so that no noun-bearing string is reused between the two features.

**`links.*` — shared, noun-free (25):** `label`, `label_ph`, `expires_in`, `password`,
`password_hint`, `copy`, `once_warning`, `close_confirm`, `no_url`, `link_hidden`,
`link_hidden_hint`, `col_target`, `col_created`, `col_expires`, `col_password`, `expired`,
`revoke`, `revoked`, `note`, `panel_title`, `load_error`, `pro_badge`, `pro_learn`,
`pro_title`, `unlimited`.

**`duration.*` — shared (5):** `1h`, `24h`, `7d`, `30d`, `90d`.

**`share.*` — noun-bearing (9):** `title`, `create`, `manage`, `max_downloads`, `created`,
`empty`, `col_views`, `col_downloads`, `revoke_confirm`, `pro_teaser`.

**`intake.*` — noun-bearing (11):** `title`, `create`, `manage`, `max_files`, `max_mb`,
`allowed_ext`, `allowed_ext_ph`, `created`, `empty`, `col_received`, `revoke_confirm`,
`pro_teaser`.

**`error.*` — new (0):** none. What shipped instead is a single pre-existing generic fallback,
`error.module_forbidden` (`{module}` param), that already covers every module's
`allow_<x>_forbidden` runtime error code, Share/Intake included — see §5.6. `license_expired`
was also already present before this feature. Neither `allow_share_forbidden` nor
`allow_intake_forbidden` exists as a literal key, and none should be added.

Reused as-is: `common.{cancel,close,confirm,loading,delete,retry}`, `copy.copied`,
`error.{module_not_installed,license_required,license_expired,module_forbidden,perm_denied,not_found,bad_request}`.

`tests/unit/test-i18n.php` fails CI on any locale missing a key **or carrying an extra one** —
add to all 16 in the same commit.

---

## 11. Test plan

**Core unit** (`packages/core/tests/unit/`)
- `test-config-doc.php` — passes automatically once §12's rows land (it parses
  `$payload->intake_base_url` / `$payload->pro_hints` out of `Claims.php`).
- `test-i18n.php` — passes once all 16 locales carry the ~50 keys.
- Claims cases (extend the existing claims test): `intake_base_url` accepts `https://…`, drops
  `javascript:…`/`data:…`/`ftp://…` → `''`; `pro_hints` defaults `true`, honours `false`,
  coerces truthy/falsy.
- New: `ff_public_link_url()` — origin + page + `rawurlencode` of a token containing `+`/`/`
  and a base already carrying `?`.

**Core integration** — no new server behaviour to test in free core: all six routes throw
`501` without the paid packages. Keep the existing `501` assertions; add the intake trio if not
already present, in the shape of `tests/e2e/test-share-http.php:172`.

**Module tests (private, `packages/intake/tests/`)** — these describe regression coverage for
behavior confirmed already shipped in §6.2, not new-feature tests:
- `createPortal` writes `owner`; response `url` from `intake_base_url`, `''` without it.
- `listPortals`: under `owner_only`, another user's portal is absent; own portal present;
  a **legacy record without `owner`** is absent (fail-closed) — the migration contract.
- `revokePortal`: 403 for another user's portal under `owner_only`; allowed without it.
- Tombstone: revoke → an interleaved `receiveUpload` that rewrites the map must **not**
  resurrect the portal; `resolveToken` returns `404 intake_revoked` after revoke.

**Adapters**
- Laravel smoke (`packages/laravel/tests/test-laravel-smoke.php`, ~:222-253): asserts
  `allow_share`/`allow_intake` + `share_base_url`/`share_analytics`/`intake_base_url`/
  `intake_analytics` are forwarded **unconditionally, in every mode** — not dropped in proxy
  mode as this doc originally planned in §6.3. Route-parity list unchanged (Share/Intake were
  never on `$intentionallyUnproxied`).
- WordPress smoke (`packages/wordpress/tests/test-wp-smoke.php`, ~:328-345, plus the dedicated
  `share_base_url` override test ~:791-806): the same claims are present in a minted token in
  every mode.
- `scripts/check-adapter-core-floor.sh` must stay green (no new core API used).
- Node vitest: `intakeBaseUrl` option + raw `claims: {intake_base_url}` passthrough.

**Playwright** — new `packages/core/tests/browser/share-intake-ui.spec.ts`, mirroring
`share-landing.spec.ts` (harness = stock `php -S`, so the paid modules are genuinely absent;
mock only what needs a licensed server). **The three gating states are the point of this file:**

1. *locked* — `openManager(page, mintToken())` (claim off), free core, top level → the Pro
   affordance is visible, opens the locked modal, and **no request to `/api/fm/share*` is made**.
2. *hidden — hard off switch* — `mintTokenWithClaims({pro_hints:false})` → no affordance, no
   panel, no `/api/fm/license` request.
3. *hidden — licensed but withheld* (**most likely to regress silently**) — claim off, mock
   `GET /api/fm/license` → `{edition:'pro', status:'active', modules:['share','intake']}` →
   nothing renders anywhere.
4. *hidden — framed* — same as (1) but the manager is loaded inside a same-origin `<iframe>`
   (`page.setContent` with an iframe whose `src` is `/public/index.html?token=…`) → no
   affordance. Guards the "never advertise inside a resold product" rule.
5. *on, module absent* — `mintTokenWithClaims({allow_share:true, allow_intake:true})` → buttons
   render; Create hits the **real** endpoint and the real `501` renders as the localized
   *"This feature isn't installed."* inline terminal state (not a generic toast, not a card).
6. *create + one-shot* — mock `POST /api/fm/share` → `{jti, expires, url:'https://x/public/share.html?token=T'}`:
   the URL shows once, Copy writes it to the clipboard, closing before copying prompts, and
   after dismissal (with `share/list` mocked to the tokenless record) **no `token=` string
   exists anywhere in the DOM**.
7. *hostile url* — mocked create returning `javascript:window.__pwned=1` → rendered as text
   only, no `href`/`src` assignment, `window.__pwned` undefined (mirrors the same-named case in
   `share-landing.spec.ts`, currently around :307-326).
8. *list* — mocked `share/list` + `intake/list`: columns render, the link column shows
   "not shown", Revoke confirms then calls `revoke` with the right `jti` and re-fetches.

Also re-run `ui-reach.spec.ts` — the new toolbar icon lands in the action cluster whose
single-row/scroll invariant that spec guards.

---

## 12. Claims to add to `docs/CONFIG.md`

Append to **§2.13 Paid-module gates**, immediately after the `allow_intake` row:

```markdown
| `intake_base_url` | string (http/s) | — | Public base the intake create response builds the portal link from (e.g. `https://files.acme.com/public/intake.html`). Non-http(s) dropped. Empty = the request origin + `/public/intake.html` — i.e. derived from the `Host` header, so **set this explicitly behind a proxy/CDN**. Mirrors `share_base_url`. |
```

Add to **§2.2 Access & permissions** (free claims):

```markdown
| `pro_hints` | bool | `true` | Show the locked "Pro" affordance for paid modules the token isn't allowed to use. Only ever renders when the server is **unlicensed** *and* the app is **not framed** (top-level `/public/`); on a licensed server the feature is hidden entirely instead. `false` = never show it — for operators shipping free core in production. UI-only; no endpoint behaviour changes. |
```

## 13. Open questions / trade-offs

1. **`pro_hints` as a claim vs a `FLUXFILES_*` env var.** Claim chosen (config = JWT claims, and
   the UI already reads claims). An env would be a truly server-wide switch that cannot be
   forgotten per token, but core would need a second UI-config injection channel next to
   `__FM_LOCALE__`. If an operator ever mints tokens from more than one place, the claim is
   easy to miss — revisit only if that is reported.
2. **Intake `url` built by the module vs by core.** Spec'd as module-then-core-fallback purely
   to mirror Share. Core alone could do both (it holds the claim and the origin), which would
   avoid a private-package change — at the cost of the two features diverging for a later reader.
3. **The intake tombstone (§6.2.4)** was originally flagged as one line beyond the plan's
   wording, pending the reviewer's call on whether it belonged in this change or its own.
   Re-reading the shipped `packages/intake/src/IntakeModule.php` confirms it landed
   (`revokePortal` at `:354-375` writes a tombstone, not an `unset`) — resolved, not open.
4. **Panel scope is the current disk only.** `list` takes one `disk`. A tenant with three disks
   sees three lists. Aggregating means N calls; deferred, with the per-disk note in the UI.
5. **No "created by" column** even though Share records carry `owner`. Under `owner_only` every
   row is yours; without it, showing another user's id is a small disclosure. Deferred.
6. **Expired rows are listed** (Share's `listShares` filters only tombstones). Rendering them
   greyed with a badge is honest but grows the list over time; a "hide expired" toggle is a v2
   nicety, not a v1 need.
