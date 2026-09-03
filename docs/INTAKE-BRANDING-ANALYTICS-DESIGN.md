# Intake Branding + Per-Event Analytics — Design

Status: **Implemented and shipped.** Branding (`intake_brand_*` claims,
`Claims::sanitizeIntakeBrand()`, rendered in `packages/core/public/intake.html`)
and per-event analytics (`intake_analytics` claim, `GET /api/fm/intake/analytics`,
`IntakeModule::analytics()`) both live in `packages/intake/src/IntakeModule.php`.
See the CHANGELOG "Intake branding + per-event analytics" entry (`intake`'s
composer floor bumped to `^0.2.76`).

> Scope note: this spec covers **two additions** to the Intake module
> (`packages/intake/src/IntakeModule.php`, gitignored private package, gated by
> `ModuleRegistry` — installed + licensed + `allow_intake`):
>
> - **Part A — Intake branding**: a straight port of the already-shipped "Branded
>   Share" feature (`share_brand_*` claims / `Claims::sanitizeShareBrand()` /
>   `$rec['brand']` in `ShareModule.php`) onto Intake's landing page
>   (`packages/core/public/intake.html`).
> - **Part B — Intake per-event analytics**: a port of the already-shipped
>   "Share Analytics" feature (`docs/SHARE-ANALYTICS-DESIGN.md`), reworked for
>   Intake's actual shape — Intake has no `download` concept, and its failure
>   modes (wrong password, cap exceeded, bad extension, oversized, virus
>   hit) are much richer than Share's single `unlock_fail`, so this is not a
>   find-and-replace of the Share doc; see §B.1–B.3 for what's different and why.
>
> Both features touch the same three methods (`createPortal()`, `portalInfo()`,
> and the record-assembly literal), so they are designed together in one doc to
> keep those edits coherent, exactly as Share's own two features share
> `createShare()`/`card()`/`shareInfo()`.

This is a **deepening of the existing paid Intake module**, not a new module,
not a new free/core feature, and not a new SKU. Per the gitignored
`docs/COMMERCIAL-STRATEGY.md` and project memory ("deepen heroes Share+Intake
rather than adding breadth"), Share just shipped both of these; Intake — its
stated "inbound twin" — is now behind on both. This closes that gap.

---

## Part A — Intake Branding

### A.1 Problem & who pays

An operator who sells (or white-labels) Intake portals to their own clients —
an agency collecting client deliverables, a bookkeeper collecting receipts, an
MSP collecting logs — currently hands senders a bare, unbranded upload form.
Share already lets an operator put their own name/logo/accent color/link on the
recipient-facing page; Intake's sender-facing page has no equivalent. Same
paying persona as Share (the operator who resells or white-labels FluxFiles to
their own end clients), same module (`allow_intake`), no new pricing tier.

### A.2 Architecture fit

Identical shape to Branded Share: branding is **cosmetic display data with no
serve-time cost**, sanitized once at JWT-decode time in `Claims.php`, baked
into the portal's `_fluxfiles/intakes.json` record at `createPortal()` time
(not read live on every public request — a public sender request carries no
main JWT/claims, only the signed portal token + the stored record), and
surfaced back out through `portalInfo()`. Changing an operator's `intake_brand_*`
claims only affects portals **created after** the change, identical to the
already-accepted trade-off `intake_base_url` / `max_mb` / `allowed_ext` all
have today.

### A.3 JWT claims

Four new claims, flat strings, exactly mirroring `share_brand_*`:

| Claim | Type | Default | Validation |
|---|---|---|---|
| `intake_brand_name` | string | `""` | `trim()`, truncated to 80 chars (`mb_substr`). |
| `intake_brand_logo_url` | string | `""` | Must match `^https?://`; anything else dropped to `""`. |
| `intake_brand_color` | string | `""` | Must match `^#([0-9a-f]{3}\|[0-9a-f]{6})$` (case-insensitive); anything else dropped. |
| `intake_brand_link_url` | string | `""` | Must match `^https?://`; anything else dropped. |

Assembled into one claim, `Claims::$intakeBrand` (`array{name,logo_url,color,link_url}|null`),
`null` when all four are blank — identical contract to `$shareBrand`.

### A.4 `Claims.php` changes

**Refactor, not duplicate**, the sanitizer: `sanitizeShareBrand()`
(`packages/core/api/Claims.php:373-386`) and the new `sanitizeIntakeBrand()`
would be byte-for-byte identical except for the four `share_brand_*` vs.
`intake_brand_*` field names. Recommended shape:

- Extract the current body of `sanitizeShareBrand()` into a private
  `sanitizeBrandFields(object $payload, string $prefix): ?array` that reads
  `"{$prefix}_name"`, `"{$prefix}_logo_url"`, `"{$prefix}_color"`,
  `"{$prefix}_link_url"` off `$payload` (using the same `{$prefix}_x` string
  built via simple concatenation, not a dynamic property-access trick that
  would break static analysis — a small `match`/lookup per field name is
  fine too if that reads better).
- `sanitizeShareBrand(object $payload): ?array` becomes a one-line wrapper:
  `return self::sanitizeBrandFields($payload, 'share_brand');` — **zero
  behavior change** for existing Share callers/tests.
- `sanitizeIntakeBrand(object $payload): ?array` — `return
  self::sanitizeBrandFields($payload, 'intake_brand');`.

New property, placed next to `$intakeBaseUrl` (`Claims.php:199-204`):

```
/** @var array{name:string,logo_url:string,color:string,link_url:string}|null
 *            Sanitized Intake brand config (null = no branding). Baked into
 *            the portal record at create time (IntakeModule::createPortal) —
 *            a public request has no claims to read live. Mirrors shareBrand. */
public ?array $intakeBrand = null;
```

Wired in `fromJwtPayload()` right after the existing `intake_base_url` block
(`Claims.php:520-523`):

```
$c->intakeBrand = self::sanitizeIntakeBrand($payload);
```

No `isAllowed()` change needed — like `share_brand_*`, this is policy data
gated only by the existing `allow_intake` 3-layer check, not a standalone gate
claim.

### A.5 `IntakeModule.php` changes

- `createPortal()` (`packages/intake/src/IntakeModule.php:105-119`, the
  `$record = [...]` literal): add `'brand' => $claims->intakeBrand,` as a
  sibling of `label`/`max_files`/`max_mb`/`allowed_ext` — same "read at create
  time, baked in" comment Share's `createShare()` already carries.
- `portalInfo()` (line ~154-170, the public response array): add `'brand' =>
  $rec['brand'] ?? null,` as a sibling of `label`/`max_files`/`has_password`.
- `revokePortal()` (line ~260-280): **no change needed for branding** — brand
  is cosmetic display data with no ownership/audit dependency, unlike
  analytics (§B.5 explains why the tombstone *does* need a change there).
- `listPortals()` / `createPortal()`'s return payload to the operator: no
  change required — brand is operator-supplied input, not something the
  operator needs echoed back beyond what `createPortal()`'s `$body` already
  told them.

### A.6 Public UI (`packages/core/public/intake.html`)

`intake.html` currently has no brand rendering at all (confirmed by reading
the file — no `.brand` CSS class, no `renderBrand()`/`safeHttpUrl()` JS). Port
verbatim from `share.html` (lines 40-43 CSS, lines 109-151 JS):

- **CSS**: add the same `.brand`/`.brand img`/`.brand span`/`.brand a` rules
  from `share.html`'s `<style>` block (lines 40-43).
- **Markup**: add `<div id="brand" class="brand hidden"></div>` as the first
  child of `#form`, directly above `<h1 id="label">Upload files</h1>` (line 49)
  — the sender should see who's asking for files before the upload widget.
- **JS**: port `safeHttpUrl()` and `renderBrand()` (`share.html:109-151`)
  verbatim (same `new URL(...)` browser-side validation, never a regex on the
  raw string, for the same reason: `info.brand` is operator-supplied and
  rendered into an anonymous, unauthenticated sender's browser — an
  attacker-adjacent surface). Call `renderBrand($('brand'), info.brand);` at
  the top of `render()` (currently starting at line 89), alongside the
  existing `label`/`has_password`/constraints rendering.
- **Accent color**: identical to Share — `renderBrand()` sets
  `document.documentElement.style.setProperty('--accent', brand.color)` after
  re-validating the hex pattern client-side (defense-in-depth; the server
  already validated it, but the browser must not trust a value it didn't
  itself re-check before it reaches a CSS custom property, same posture as the
  `logo_url`/`link_url` `new URL()` check).
- No change to `intake/upload` error rendering, drop-zone, or progress bars.

### A.7 Security

- Exactly Share's threat model: `logo_url`/`color`/`link_url` are
  **operator-supplied but attacker-adjacent** once rendered — a compromised or
  careless operator config must not become a `javascript:`/`data:` URL in an
  anonymous sender's browser. Server-side: `preg_match('#^https?://#i', …)`
  drops anything else at decode time (belt). Browser-side: `new URL(...)`
  re-validation before touching `img.src`/`a.href` (braces) — a regex-only
  check on the client would be bypassable by a value that slipped past a
  *future* relaxation of the server check; two independent checks in two
  different languages is the existing, deliberate Share posture, ported as-is.
- No new error codes, no new i18n strings — branding never fails a request
  (a malformed brand claim just sanitizes to `null`/`""` fields, same as
  Share).
- No new SSRF surface — logo/link are rendered as an `<img src>`/`<a href>` in
  the *sender's own browser*, never fetched server-side (unlike, say,
  `import-url`).

### A.8 Package layout (Part A)

**Free/core files touched:**
- `packages/core/api/Claims.php` — `sanitizeBrandFields()` extraction (refactor,
  behavior-preserving for Share), new `sanitizeIntakeBrand()`, new
  `$intakeBrand` property + one `fromJwtPayload()` line.
- `packages/core/public/intake.html` — CSS + markup + `renderBrand()`/`safeHttpUrl()`
  JS ported from `share.html`; `render()` gains one call.
- `docs/CONFIG.md` — four new claim rows (§C below).

**Private module files touched** (`packages/intake/`, gitignored):
- `packages/intake/src/IntakeModule.php` — `createPortal()` + `portalInfo()`,
  one new key each (§A.5).

### A.9 Test plan (Part A)

- `packages/core/tests/unit/` — a small `Claims::sanitizeIntakeBrand()` unit
  test (mirrors whatever, if any, direct test exists for
  `sanitizeShareBrand()`; if none exists today, add one alongside covering:
  all-blank → `null`; valid full set → sanitized array; bad `logo_url`
  scheme (`javascript:...`) → dropped to `""`; bad `color` (`red`, missing
  `#`, 4-digit) → dropped; name truncated at 80 chars).
- `packages/intake/tests/test-intake.php` (extend the existing file, which
  already exists — see §B.7 for the full list of new cases added in this pass):
  add one case that `createPortal()` with `intakeBrand` set on `$claims` bakes
  `brand` into the record, and `portalInfo()` echoes it back; one case with no
  brand claims set asserts `brand: null`.
- `packages/core/tests/unit/test-config-doc.php` — passes once the four new
  claims are documented (no code change to the test itself).
- No Playwright/browser test proposed — `share.html`'s brand rendering has none
  either (per `docs/SHARE-ANALYTICS-DESIGN.md` §11's "not needed" note for the
  sibling feature); this is a `fetch`-and-render path already covered at the
  API layer by the module test above. Add one if the team's bar for public
  landing pages changes.

---

## Part B — Intake Per-Event Analytics

### B.1 Problem & who pays

Today `intakes.json` gives an operator exactly one counter per portal —
`received` — and nothing else: no "who actually sent files, when, and how many
attempts were rejected and why." For the persona buying Intake (an
agency/bookkeeper/support team running an inbound collection portal), that's a
real gap on two fronts Share's analytics doesn't have to answer:

1. **Who's sending, and did it work** — same value Share Analytics gives for
   opens (a timestamped per-visitor record), but for uploads instead of views.
2. **What's going wrong** — Intake's public surface accepts files from
   strangers by design, so it has a materially richer failure surface than
   Share's single `unlock_fail`: wrong/missing portal password, the
   `max_files` cap being hit, and every upload-pipeline rejection
   (`ext_not_allowed`, `upload_too_large`, `quota_exceeded`, `too_many_files`,
   `virus_detected`, …). An operator running a public "send us your files"
   link genuinely wants to know when it's being hammered or misused, at least
   as much as they want to know it's working.

This is a **deepening of the existing paid module**, ships inside
`packages/intake/`, gated by the same `allow_intake` claim as everything else
in Intake. No new SKU.

### B.2 Architecture fit

Same grain as Share Analytics — fully storage-resident, no DB, no scheduler:

- The **aggregate counters** already live in `<prefix>/_fluxfiles/intakes.json`
  (one JSON blob per tenant), guarded by `IntakeModule::withLock()`. This spec
  adds one new aggregate counter, `rejected` (§B.5), alongside the existing
  `received`.
- The **new per-event log** is a second, additive artifact: one JSONL file
  **per portal** at `<prefix>/_fluxfiles/intake-events/<jti>.jsonl` (§B.5) —
  same per-share-not-per-tenant reasoning `share-events/` already established
  (a read only ever wants one portal's history; free-standing lifecycle so the
  file can be deleted outright when its portal is pruned).
- Whether logging happens at all is **baked into the portal record at create
  time** from a new opt-in claim, `intake_analytics` (§B.3) — exactly like
  `share_analytics`, and for the identical reason: a public sender has no JWT,
  so policy has to travel with the record, not be read live from claims.
- No cron, no background pruning job — same opportunistic-cleanup-on-prune
  trade-off `shares.json`/`intakes.json` already accept.

**What's genuinely different from Share** (per the task's instruction not to
find-and-replace):

- **No `download` concept.** A portal only ever receives files; there is no
  second action analogous to Share's separate `view`/`download` split. The
  two event types are `received` (a file successfully landed) and `rejected`
  (it didn't, for any reason) — see §B.4 for why a single `rejected` type with
  a `reason` sub-field beats minting one event type per failure mode.
- **A `name` field per event.** Share's per-event log has no file-identifying
  field because a share token is already scoped to exactly one object — "which
  file" is redundant. A portal receives *many different files* over its
  lifetime, so "who's sending what, and what specifically got rejected" is
  real operator value a bare `type`/`ip`/`ua` tuple would lose. This is a
  deliberate addition beyond a literal port of Share's schema (§B.5).
- **An unconditional `rejected` aggregate counter**, not gated by
  `intake_analytics` — mirroring how Share's `unlock_fails` counter is
  unconditional (visible via `listShares`/`share/list`) while the *detailed*
  per-event JSONL log is the opt-in layer on top. Rationale and the
  alternative considered are in §B.5.

### B.3 JWT claims

**New claim: `intake_analytics`** (bool, default `false`), **not folded into
`allow_intake`** — same reasoning `share_analytics` already established: this
persists a **timestamped, per-visitor IP address + User-Agent string** (now
also a submitted filename) for every anonymous upload attempt against a
portal, which is squarely the kind of thing an operator needs explicit,
separate control over for privacy/compliance reasons. An operator who cannot
legally log visitor IPs for a given tenant must be able to keep Intake's core
value (portal + expiry + cap + password) while leaving event logging off — a
single `allow_intake` gate would force an all-or-nothing choice, identical to
the `share_analytics` argument. Turning `intake_analytics` on/off only affects
portals **created after** the change (same baked-at-create-time trade-off as
`max_mb`/`allowed_ext`/`intake_base_url`).

`Claims.php` changes:

New property, next to `$intakeBrand` (§A.4):

```
/** @var bool Log a per-event received/rejected record (timestamp + IP +
 *            user-agent + filename + rejection reason) for a portal,
 *            alongside the existing aggregate `received`/`rejected`
 *            counters. Off by default — persists visitor IPs (privacy/
 *            compliance footprint). Baked into the portal record at create
 *            time, like intakeBrand. */
public bool $intakeAnalytics = false;
```

Wired in `fromJwtPayload()`, next to the `$c->intakeBrand = ...` line:

```
$c->intakeAnalytics = isset($payload->intake_analytics) ? (bool) $payload->intake_analytics : false;
```

No other new claims — rotation/retention sizing (§B.5) stays hardcoded
constants in `IntakeModule.php`, mirroring Share's `EVENTS_MAX_BYTES` /
`EVENTS_KEEP_LINES` (not tenant-configurable there either).

### B.4 Endpoints

One new **operator-authed** endpoint, nested under `/intake` like the existing
`list`/`revoke` management routes (never public — the sender never sees this):

**`GET /api/fm/intake/analytics?disk=local&jti=<id>&limit=100&offset=0&event=received|rejected`**

- Auth: main JWT, same 3-layer gate as create/list/revoke —
  `ModuleRegistry::require('intake', LicenseManager::fromEnv(), $claims)` (501
  not installed, 402 unlicensed, 403 `module_forbidden` if `allow_intake` off).
- Ownership: same rule as `revokePortal()` — under `owner_only`, only the
  portal's creator may read its analytics (`403 perm_denied` otherwise). A
  single-portal lookup, so a non-owner request fails loudly, unlike
  `listPortals()`'s silent filtering.
- `limit` clamped `[1, 500]` (default 100); `offset >= 0`; `event` optional
  exact-match filter on `received`/`rejected`.
- Response:

```json
{
  "jti": "a1b2c3d4e5f60718293a4b5c",
  "analytics_enabled": true,
  "total": 4,
  "events": [
    { "ts": 1755500400, "type": "rejected", "ip": "203.0.113.7", "ua": "Mozilla/5.0 (...)", "reason": "ext_not_allowed", "name": "invoice.exe" },
    { "ts": 1755500300, "type": "received", "ip": "203.0.113.7", "ua": "Mozilla/5.0 (...)", "reason": null, "name": "invoice.pdf" },
    { "ts": 1755499800, "type": "rejected", "ip": "198.51.100.4", "ua": "curl/8.4.0", "reason": "intake_password_wrong", "name": "" },
    { "ts": 1755498200, "type": "rejected", "ip": "198.51.100.4", "ua": "curl/8.4.0", "reason": "intake_full", "name": "extra.zip" }
  ]
}
```

  `analytics_enabled` distinguishes "created with logging off" from "logging
  on but nothing happened yet" — identical purpose to Share's field.

- Why one `type: "rejected"` with a `reason` sub-field, not a distinct event
  type per failure mode (`intake_password_wrong`, `intake_full`,
  `ext_not_allowed`, …): Share's three counters (`views`/`downloads`/
  `unlock_fails`) map 1:1 to three fixed, named user *actions*. Intake's
  failure surface is a much larger, occasionally-growing set of *causes*
  (extension policy, size cap, quota, file-count cap, virus scan, password,
  portal cap) that all reduce to the same operator-facing question — "did
  this attempt land, and if not, why" — so a fixed `{received, rejected}`
  vocabulary plus a free-form (but bounded/allowlisted-in-practice) `reason`
  string scales to new pipeline rejections without a schema/endpoint change
  every time `FileManager::upload()` grows a new `ApiException` code. `reason`
  is always one of the module's own or `FileManager`'s existing
  `error_code` constants (`intake_password`, `intake_password_wrong`,
  `intake_full`, `ext_not_allowed`, `upload_too_large`, `quota_exceeded`,
  `too_many_files`, `virus_detected`, `name_conflict`, `name_invalid`, or the
  generic `upload_failed` for a non-`ApiException` throwable) — never raw
  exception message text, so it stays a short, stable, log-safe token (§B.8).
- Errors (both **reused**, no new error codes / no new i18n strings — see
  §B.8): `404 intake_revoked` (unknown jti — same "not found and revoked are
  one answer" posture `resolveToken()` already uses), `403 perm_denied` (not
  the owner).

`index.php` wiring — directly after the existing `/api/fm/intake/revoke`
block (`packages/core/api/index.php:696-700`):

```
if ($method === 'GET' && $uri === '/api/fm/intake/analytics') {
    $module = \FluxFiles\ModuleRegistry::require('intake', \FluxFiles\LicenseManager::fromEnv(), $claims);
    return $module->analytics(
        $diskManager,
        $claims,
        ff_str_param($_GET, 'disk', 'local'),
        ff_str_param($_GET, 'jti'),
        max(1, min(500, (int) ($_GET['limit'] ?? 100))),
        max(0, (int) ($_GET['offset'] ?? 0)),
        isset($_GET['event']) && $_GET['event'] !== '' ? ff_str_param($_GET, 'event') : null
    );
}
```

Core-standalone, like the rest of Intake's management routes — not proxied by
Laravel/WordPress (they don't proxy `/api/fm/intake*` today either).

### B.5 Storage layout

**One JSONL file per portal** (not per tenant):

```
<prefix>/_fluxfiles/intake-events/<jti>.jsonl
```

`<jti>` is the portal's own id (`bin2hex(random_bytes(12))`, 24 lowercase hex
chars, filename-safe); validate with the same
`preg_match('/^[A-Za-z0-9_-]{8,64}$/', $jti)` pattern Share's `analytics()`
already uses, before it ever reaches a path.

**JSON-lines schema**, one line per event:

```json
{"ts": 1755500300, "type": "received", "ip": "203.0.113.7", "ua": "Mozilla/5.0 (...)", "reason": null, "name": "invoice.pdf"}
{"ts": 1755500400, "type": "rejected", "ip": "203.0.113.7", "ua": "Mozilla/5.0 (...)", "reason": "ext_not_allowed", "name": "invoice.exe"}
```

- `ts` — int, unix seconds.
- `type` — `"received"` | `"rejected"`.
- `ip` — `filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: ''`
  — identical to Share, never a raw unvalidated value.
- `ua` — control-stripped + capped at 200 chars via `cleanLabel($ua, 200)`.
- `reason` — `null` for `received`; for `rejected`, the triggering
  `ApiException::getErrorCode()` (or the literal string `upload_failed` for a
  non-`ApiException` throwable), additionally passed through
  `cleanLabel($reason, 60)` as defense-in-depth (these are all short internal
  constants today, but a future code path must not be able to smuggle
  arbitrary length/content into this field just by changing an exception
  message).
- `name` — the **originally submitted** filename (attacker-controlled,
  untrusted) for a `rejected` event, or the **final stored** filename (already
  collision-resolved, e.g. `invoice-1.pdf`) for a `received` event — either
  way, run through `cleanLabel($name, 200)` before it's ever `json_encode()`d
  (§B.8; this is the field most exposed to a hostile sender, since a filename
  is 100% attacker-chosen and never validated for "reasonable content" the way
  extension/size already are upstream).

**Rotation** (identical constants/shape to Share, new dir name):

```
private const EVENTS_DIR        = '_fluxfiles/intake-events';
private const EVENTS_MAX_BYTES  = 1 * 1024 * 1024; // 1MB per-portal rotation threshold
private const EVENTS_KEEP_LINES = 2000;            // keep last N events after rotation
private const ANALYTICS_MAX_LIST = 500;            // per-request read cap
```

On each append, if the current file exceeds `EVENTS_MAX_BYTES`, keep only the
last `EVENTS_KEEP_LINES` lines before appending the new one. The existing
`FLUXFILES_INTAKE_UPLOAD_LIMIT` (10/min per portal+IP) and
`FLUXFILES_INTAKE_UPLOAD_TOTAL` (60/min per portal) rate limits already bound
how fast this file can grow in the ordinary case; the rotation cap exists for
the pathological one (a portal hammered for its full 90-day `MAX_TTL`).

**Unconditional `rejected` aggregate counter** — new field on the
`intakes.json` record, alongside the existing `received`:

```json
{ "jti": "...", "received": 12, "rejected": 3, "...": "..." }
```

Incremented on every rejection (password wrong/missing, cap exceeded, or any
upload-pipeline failure — §B.6), **regardless of `intake_analytics`** —
mirroring Share's `unlock_fails`, which is likewise unconditional (visible via
`listShares`/`share/list`) while the detailed per-event JSONL is the opt-in
layer on top. Rationale: `received` already exists unconditionally today, so
an operator with analytics off would otherwise see "12 files landed" and have
*zero* visibility into "and 40 more attempts failed" — arguably a worse
information asymmetry for Intake than Share ever had, since Intake's public
surface takes input from strangers by design. The alternative (gate `rejected`
behind `intake_analytics` too, keeping `intakes.json`'s shape exactly as today
plus one opt-in file) was considered and rejected: a bare integer count of
failures carries no personal data on its own (same category of decision that
already separates `views`/`downloads`/`unlock_fails` from `share_analytics`),
so there's no privacy reason to gate it, and gating it would leave `received`
looking artificially healthy for a portal that's mostly being probed/abused
with the wrong extension or a guessed password. Surfaced in `listPortals()`
(operator-only) next to `received`; **not** surfaced in the public
`portalInfo()` response (a sender doesn't need to know how many other
attempts failed, and `portalInfo()`'s response shape is otherwise unchanged
except for `brand`, §A.5).

**Cleanup on revoke/expiry** — piggybacks on existing `save()`/`revokePortal()`
work, no new trigger, mirroring Share exactly:

- `revokePortal()` (`IntakeModule.php:260-280`) currently tombstones with
  `['jti', 'revoked', 'expires']` only — **no `owner`**, unlike Share's
  *current, already-shipped* tombstone (`ShareModule.php:322-328`, which
  *does* retain `owner` — that fix already landed for Share). This spec
  requires the identical fix for Intake: extend the tombstone to
  `['jti', 'revoked', 'expires', 'owner']`. Without it, `analytics()`'s
  `owner_only` check has nothing to check against once a portal is revoked,
  since the full record (which carried `owner`) is gone — the endpoint would
  have to fail closed (`404`) for every revoked portal under `owner_only`,
  worse for the paying operator than a one-field tombstone change. Adding a
  field to the tombstone changes no existing check — `resolveToken()` and
  `putRecord()`'s tombstone guard only ever look at `revoked`/`expires`.
- `save()` (`IntakeModule.php:385-396`) already prunes tombstones whose
  `expires` has passed. Extend that loop to also delete
  `_fluxfiles/intake-events/<jti>.jsonl` for each pruned jti (best-effort,
  wrapped like every other write in this class) — identical to
  `ShareModule::save()`'s extension.
- Same accepted limitation as Share: a portal that is **never explicitly
  revoked** (just left to expire naturally) keeps its events file forever,
  exactly like its `intakes.json` record already does today. Not a regression
  this feature introduces (§B.9).

### B.6 Write path (in `IntakeModule.php`)

Three distinct call sites need instrumentation, and — unlike Share, where
every counter bump already happens inside one `mutateRecord()`-style locked
closure per public action — Intake's `receiveUpload()` splits "reserve a slot"
from "know the outcome" across two phases (reservation happens *before* the
upload pipeline's own extension/size/quota/virus checks run), so the write
path needs slightly different plumbing at each point. All three still reuse
the *same* `intakes.lock` file `withLock()` already serializes every
`intakes.json` mutation through — no new lock file, no new lock semantics,
just (in one case) an additional acquisition of the existing one.

1. **Password check** (`receiveUpload()`, before the reservation — currently
   throws `intake_password`/`intake_password_wrong` with no counter touched
   at all): on failure, acquire `withLock()` once to load the record, bump
   `rejected`, save, and — if the record's own `analytics` flag is on — append
   a `rejected` event with `reason` = the same error code and `name` =
   `cleanLabel($file['name'], 200)` (the filename is already known at this
   point, since `handleIntakePublic()` builds `$file` before calling
   `receiveUpload()`). This is the one new lock acquisition Share's design
   didn't need (Share's password check and its counter bump already lived in
   the same function/closure); it is bounded by the existing
   `FLUXFILES_INTAKE_UPLOAD_LIMIT`/`_TOTAL` rate limits already gating this
   route, so it introduces no new DoS surface.
2. **Cap exceeded** (`receiveUpload()`'s reservation closure, already inside
   `withLock()`, `IntakeModule.php:204-219` — currently throws `intake_full`
   with `received` left untouched): extend the *same* locked closure to also
   bump `rejected` and, if `analytics` is on, append the `rejected` event
   (`reason: "intake_full"`, `name` = the submitted filename) **before**
   throwing — no new lock, this reuses the one already held.
3. **Upload-pipeline failure** (the `try { $portalFm->upload(...) } catch
   (\Throwable $e) { ...rollback... }` block, `IntakeModule.php:221-238` —
   currently only rolls back the `received` reservation): extend the existing
   rollback closure (already inside `withLock()`) to *also* bump `rejected`
   (net effect: the reservation's `received++` is undone and `rejected++`
   replaces it, so the two counters stay mutually exclusive per attempt) and,
   if `analytics` is on, append the `rejected` event with `reason` =
   `$e instanceof ApiException ? $e->getErrorCode() : 'upload_failed'` and
   `name` = the submitted filename — again, no new lock.
4. **Success** (after `$portalFm->upload(...)` returns without throwing):
   the reservation already incremented `received` correctly, so there is
   nothing left to bump — but there *is* a new small write here: if
   `analytics` is on, append the `received` event with `name` = the
   **final stored** filename (`$result['name']`, post-collision-rename). This
   is a second, dedicated lock acquisition purely for the append (the counter
   write already happened at reservation time and must not be duplicated).
   Gated on the record's `analytics` flag exactly like every other append —
   zero filesystem cost when off, same as Share's fast path.

And `createPortal()`'s record-assembly literal (`IntakeModule.php:105-119`,
alongside `label`/`max_files`/`max_mb`/`allowed_ext`/`brand`, §A.5):

```
'received'  => 0,
'rejected'  => 0,
'analytics' => $claims->intakeAnalytics,   // baked in at create time — see Claims::$intakeAnalytics
```

`cleanLabel()` (`IntakeModule.php:362-367`) currently has **no `$max`
parameter** (hardcoded 120) — add an optional `int $max = 120` parameter,
identical one-line signature change to the one Share's `cleanLabel()` already
got, so it can be reused at `200` for `ua`/`name` and `60` for `reason`
without a second near-duplicate helper.

`appendEvent()` (new, private) has the same shape as Share's, plus the two new
fields:

- Signature sketch: `appendEvent($fs, string $prefix, string $jti, string
  $type, ?string $reason, ?string $name): void`.
- Body: identical read-rotate-append shape to `ShareModule::appendEvent()`,
  writing `{"ts":…, "type":…, "ip":…, "ua":…, "reason":…, "name":…}` as one
  `json_encode()` call (never hand-concatenated — §B.8) to
  `intake-events/<jti>.jsonl`.

### B.7 Read/list semantics

- **Pagination**: `limit` (default 100, clamped `[1, 500]`) + `offset` —
  identical shape/reasoning to Share's `analytics()` and
  `AuditLogStorage::list()`.
- **Sort order**: newest first (`ts` descending).
- **Filtering**: optional `event` exact-match on `received`/`rejected` (an
  operator investigating abuse wants `event=rejected` only). No `reason`
  sub-filter in v1 — a portal's failure volume is expected to be low enough
  that `limit`/`offset` over the unfiltered `rejected` set is sufficient;
  add one later if real usage shows a portal accumulating enough distinct
  rejection reasons to need it. No date-range filter, same reasoning as
  Share (the rotation cap already bounds lookback).
- No cross-portal listing — analytics is always scoped to one `jti`;
  `GET /api/fm/intake/list` (aggregate `received`/`rejected` counters) remains
  the cross-portal summary table, `analytics` is the drill-down, identical
  division of labor to Share's `list` vs. `analytics`.

### B.8 Security considerations

- **Log injection (JSONL corruption via a crafted filename or User-Agent)**:
  `json_encode()` already escapes control characters (including `\n`/`\r`) as
  part of PHP's JSON string-encoding rules, *as long as the entry is always
  built as a PHP array and passed through one `json_encode()` call* — never
  hand-concatenated. This spec still requires stripping control characters and
  capping length **before** encoding, via `cleanLabel()`, for two reasons
  beyond defense-in-depth: (a) the same belt-and-braces posture Share already
  applies to `ua`/`label`, and (b) `name` here is the **least trustworthy**
  field either module logs — a portal's `receiveUpload()` accepts an
  attacker-fully-controlled filename with only an extension check applied
  upstream (no charset/length validation on the base name itself), so this is
  the first place in either module's analytics where the logged string is
  guaranteed hostile-input, not just "generally untrusted."
- **IP validation**: identical to Share —
  `filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP)`, invalid →
  stored as `""`, never a raw value.
- **Length caps**: `ua` at 200 (`cleanLabel($ua, 200)`), `name` at 200
  (`cleanLabel($name, 200)`), `reason` at 60 (`cleanLabel($reason, 60)`,
  generous headroom over the longest existing error-code constant) — each
  bounds both display clutter and per-event storage size independent of the
  rotation cap.
- **`reason` is a closed-ish vocabulary, not free text**: always the
  triggering `ApiException::getErrorCode()` (a short internal constant) or the
  literal `upload_failed` fallback — never `$e->getMessage()`, which can embed
  interpolated values (e.g. `assertUploadSize()`'s message includes the actual
  file size in MB). Logging the *code*, not the *message*, keeps `reason`
  stable and free of any incidentally-interpolated user input.
- **XSS on display**: `ua`/`name`/`reason` are returned as JSON only
  (`Content-Type: application/json`); any HTML rendering in a future operator
  dashboard is that UI's responsibility to escape, same posture the Share
  Analytics doc already flagged for `ua`/`ip`.
- **No SSRF surface** — this feature makes no outbound request; `SsrfGuard` is
  not applicable.
- **jti format validation on the attacker-reachable read path**: `analytics()`
  validates `$jti` against `^[A-Za-z0-9_-]{8,64}$` **before** it ever reaches
  a filesystem path — identical requirement and pattern to Share's
  `analytics()`, called out explicitly here because Intake's portal token is
  likewise public-facing (a hostile actor who has seen a valid portal link can
  attempt to guess/mutate a `jti` against this endpoint) even though this
  specific *endpoint* is operator-authed; defense-in-depth against a malformed
  value ever reaching `getRecord()`/a path join.
- **Ownership / `owner_only`**: `analytics()` reuses exactly `revokePortal()`'s
  check (`$claims->ownerOnly && ($rec['owner'] ?? null) !== $claims->userId` →
  `403 perm_denied`), which is why §B.5 extends the tombstone to retain
  `owner` — identical reasoning to Share's tombstone fix.
- **Error codes**: **no new error codes, no new i18n strings.** `analytics()`
  reuses `404 intake_revoked` and `403 perm_denied`, both already translated
  in all 16 `packages/core/lang/*.json` files (confirmed present:
  `intake_invalid`, `intake_revoked`, `intake_password`, `intake_full` already
  exist in `lang/en.json:324-327`; `perm_denied` is a core-wide code used
  throughout). This was a deliberate design choice, mirroring Share
  Analytics, to avoid new translation work for a feature that needs no new
  user-facing message shape.
- **Public-route unaffected**: `handleIntakePublic()` / `PublicLinks.php`'s
  response shapes change only via `portalInfo()`'s new `brand` field (§A.5) —
  `analytics` data is never returned to a sender, only to the operator via the
  new authed endpoint.

### B.9 Backward compatibility

- `received` is **unchanged** — same field, same increment logic, still
  readable via `GET /api/fm/intake/list`. `rejected` is new but defaults to
  `0` for any pre-existing record missing the key (`(int) ($rec['rejected'] ??
  0)`), so an old portal's record just starts accumulating it from the first
  rejection after upgrade — no migration step.
- Default (`intake_analytics` unset / `false`): **zero behavior change**
  beyond the new unconditional `rejected` counter (a single extra integer in
  an already-read-modify-written JSON blob) — the `!empty($rec['analytics'])`
  check short-circuits before any JSONL file is ever touched, so an operator
  who doesn't opt in sees no new files, no new I/O beyond the counter bump
  that was already happening for `received`.
- A portal created **before** this feature ships has no `analytics`/`brand`
  key in its stored record; `!empty($rec['analytics'])` and `$rec['brand'] ??
  null` both treat a missing key exactly like `false`/`null` — old portals
  keep working, logging/branding nothing, no migration step.

### B.10 Package layout (Part B)

**Free/core files touched:**
- `packages/core/api/Claims.php` — new `$intakeAnalytics` property + one
  `fromJwtPayload()` line (§B.3).
- `packages/core/api/index.php` — new `GET /api/fm/intake/analytics` route
  (§B.4).
- `docs/CONFIG.md` — new `intake_analytics` row (§C).

**Private module files touched** (`packages/intake/`, gitignored):
- `packages/intake/src/IntakeModule.php`:
  - `createPortal()` — `rejected: 0` + `analytics` keys in the record literal.
  - `receiveUpload()` — password-check branch, reservation closure, and
    rollback closure each gain a `rejected` bump + conditional event append;
    the success path gains a `received` event append (§B.6).
  - `revokePortal()` — tombstone gains `owner` (§B.5, same fix as Share's).
  - `save()` — tombstone-pruning loop also deletes the pruned jti's events
    file (§B.5).
  - `cleanLabel()` — gains an optional `int $max = 120` parameter (§B.6).
  - New: `appendEvent()` (private) and `analytics()` (public) (§B.4/B.6/B.7).
  - New constants: `EVENTS_DIR`, `EVENTS_MAX_BYTES`, `EVENTS_KEEP_LINES`,
    `ANALYTICS_MAX_LIST` (§B.5/B.7).

No changes to `packages/core/api/PublicLinks.php` beyond what §A already
requires for `brand` — the public routes (`handleIntakePublic`) are otherwise
untouched.

### B.11 Test plan (Part B)

**`packages/intake/tests/test-intake.php`** (existing file — add cases
alongside the existing `max_files cap`/counter tests):

- `createPortal()` bakes `analytics` from `$claims->intakeAnalytics` (both
  `true` and default `false`) and `rejected: 0` into a fresh record.
- With `analytics = false` (default): a successful upload, a wrong-password
  attempt, a cap-exceeded attempt, and an extension-rejected attempt each
  still bump the right aggregate counter (`received` or `rejected`) exactly
  as designed, and `_fluxfiles/intake-events/<jti>.jsonl` is **never created**
  (assert `!is_file(...)`) — pins the zero-cost-when-off requirement (§B.9).
- With `analytics = true`:
  - One `received` event on a successful upload — assert `type`, `ip`, `ua`,
    `reason: null`, and `name` equal to the *stored* (possibly
    collision-renamed) filename.
  - One `rejected` event (`reason: "intake_password_wrong"`) on a wrong
    password.
  - One `rejected` event (`reason: "intake_full"`) once `max_files` is hit.
  - One `rejected` event (`reason: "ext_not_allowed"`) for a disallowed
    extension, and one (`reason: "upload_too_large"`) for an oversized file —
    both asserting `name` equals the *submitted* (not stored) filename, since
    the file never landed.
  - Assert `received` and `rejected` aggregate counters match the event
    counts exactly (mutual exclusivity per attempt).
- A crafted filename containing `"\r\nInjected: header"` and raw control
  bytes, submitted to a rejecting path (e.g. bad extension) → the stored
  `name` has no literal control characters, the events file stays exactly one
  line for that event (proves no JSONL corruption via the attacker-controlled
  field, mirroring Share's UA-injection test but on the *more* hostile field).
- Rotation: identical pattern to Share's rotation test, pre-seeding
  `_fluxfiles/intake-events/<jti>.jsonl` past `EVENTS_MAX_BYTES` directly,
  appending one more event, asserting the file holds at most
  `EVENTS_KEEP_LINES + 1` lines.
- `analytics()`: newest-first ordering; `event=` filter narrows correctly;
  `limit`/`offset` clamp/paginate; unknown `jti` → `intake_revoked`; a
  non-owner under `owner_only` → `perm_denied`; the owner still gets
  `perm_denied` correctly rejected for another user's portal even after that
  portal has been revoked (exercises the tombstone-retains-`owner` fix).
- Revoking a portal, aging its tombstone past `expires`, triggering another
  `save()` (e.g. creating a second portal) → the pruned jti's events file is
  deleted from disk.
- Branding (§A.9's module-level case) can live in this same file alongside
  the above, since both features touch `createPortal()`/`portalInfo()`.

**`packages/core/tests/e2e/test-intake-http.php`** — **does not exist yet**
(confirmed: only `test-share-http.php` exists under `packages/core/tests/e2e/`
today; Intake currently has no HTTP-level e2e coverage of its public routes at
all, unlike Share). This spec's scope is the two features above, not filling
that pre-existing gap, but since the new `/api/fm/intake/analytics` route is
exactly the kind of operator-authed route `test-share-http.php` exercises for
Share, the recommended path is to **create** `test-intake-http.php` following
`test-share-http.php`'s three-phase pattern (free-core 501 / installed
unlicensed 402 / licensed) verbatim, and use it to cover:

- Phase 3 (licensed) only for the new behavior: mint a portal with
  `intake_analytics: true` and `intake_brand_name`/etc. in `claims`, drive one
  successful `POST .../upload` and one failing one (wrong password or bad
  extension) over real HTTP, then call
  `GET /api/fm/intake/analytics?jti=...` with the operator's main JWT and
  assert both events come back with the right `type`/`reason`. Also assert
  `GET /api/fm/intake/info` echoes the `brand` object, and that
  `analytics_enabled: false` + `events: []` for a portal created without the
  claim.
- An unused self-boot port per the project's port-allocation convention
  (`share` uses 8110-8112; pick the next free block for `intake`).
- Picking up this pre-existing test gap is called out as a prerequisite of
  this spec's e2e coverage, not optional — a new operator-authed route with no
  HTTP-level test at all would be a regression in coverage relative to how
  Share shipped its own analytics endpoint.

**`packages/core/tests/unit/test-config-doc.php`**: passes once
`intake_analytics`/`intake_brand_*` are documented (§C) — no code change.

**Not needed**: no new browser/Playwright test for the analytics data path
itself (no public UI surface changes beyond `brand` rendering, already covered
in §A.9); this is an operator API addition.

### B.12 Open questions / trade-offs

- **Never-revoked, naturally-expired portals keep their events file forever**
  — same pre-existing property `intakes.json` already has for its own record;
  not fixed here (identical to Share's own open question).
- **No server-wide kill switch** for `intake_analytics` — same reasoning
  Share's design gave for not adding one: the blast radius of a mis-set claim
  is bounded and storage-local (an extra JSONL file with IP/UA/filenames in
  the operator's own bucket), so the per-token claim is judged sufficient.
- **The unconditional `rejected` counter is a new field on an existing,
  already-shipped JSON shape** (`intakes.json`) — flagged explicitly in
  §B.5/§B.9 as a deliberate, low-risk addition (a bare integer, no personal
  data), but it *is* a scope decision beyond "port Share Analytics verbatim,"
  since Share's analogous `unlock_fails` counter already existed before its
  own analytics feature shipped, whereas Intake has never tracked rejections
  at all. If the team wants the smallest possible diff, `rejected` could ship
  gated behind `intake_analytics` instead (folding it into the opt-in path
  entirely) — noted here as the alternative not chosen, with the reasoning in
  §B.5 for why the unconditional version was preferred.
- **No `reason` sub-filter on `GET /api/fm/intake/analytics`** in v1 (only
  `event=received|rejected`) — add one later if an operator has enough
  `rejected` volume with enough distinct `reason` values to need it (§B.7).
- **No date-range filter** in v1, same as Share — the rotation cap already
  bounds lookback in practice.
- **`test-intake-http.php` doesn't exist yet** (§B.11) — this spec treats
  creating it as in-scope groundwork for shipping the new route with adequate
  coverage, but flags it here since it's a larger lift than "add a phase to an
  existing file," which is all Share's equivalent doc needed.

---

## Part C — `docs/CONFIG.md` additions

Insert into **§2.13 "Paid-module gates"**, directly **after** the existing
`intake_base_url` row (line 187) and **before** `allow_versioning` (line 188):

```
| `intake_analytics` | bool | `false` | Log a per-event record (timestamp, type `received`/`rejected`, IP, user-agent, reason, filename) for a portal, in addition to the existing `received`/`rejected` counters. Off by default — persists visitor IP addresses (privacy/compliance footprint), unlike the plain counters. Baked into the portal record at create time, like `intake_base_url`. |
| `intake_brand_name` | string | — | Intake branding: display name shown on the upload-portal landing page. Max 80 chars. Baked into the portal record at create time, like `intake_base_url`. |
| `intake_brand_logo_url` | string (http/s) | — | Intake branding: logo image URL rendered on the landing page. Non-http(s) dropped. Operator hosts the file themselves (same BYO-embed model as `office_url`). |
| `intake_brand_color` | string (hex) | — | Intake branding: accent color, e.g. `#7c3aed`. Must match `^#([0-9a-f]{3}\|[0-9a-f]{6})$`; anything else dropped. |
| `intake_brand_link_url` | string (http/s) | — | Intake branding: link behind the brand name/logo on the landing page. Non-http(s) dropped. |
```

This also introduces one new aggregate field, `rejected`, on the
`_fluxfiles/intakes.json` record shape — not a JWT claim, so it does not get
its own CONFIG.md row (the file documents claims, not storage schema; the
existing `intakes.json` shape isn't documented there either).

---

## Part D — Composer floor bump

Once the `Claims.php` changes (both parts) land in a tagged core release, bump
`packages/intake/composer.json`'s `fluxfiles/fluxfiles` constraint from its
current `^0.2.65` to the **first core tag whose `index.php` calls** the new
`IntakeModule::analytics()` method — per the project's adapter-floor rule, the
floor must be the release where core actually *invokes* the new API, not
merely where `Claims.php` gained the new properties (a lower floor would
permit an install that's present, licensed, and never invoked). Mirrors how
`packages/share/composer.json` was bumped `^0.2.74` → `^0.2.75` when Share's
own analytics route shipped. Read `git tag | grep '^core-v' | sort -V | tail -1`
at release time for the real next version — never derive it from
`CHANGELOG.md`.

---

## Summary: exact claims to add to `docs/CONFIG.md`

| Claim | Type | Default |
|---|---|---|
| `intake_analytics` | bool | `false` |
| `intake_brand_name` | string | `""` |
| `intake_brand_logo_url` | string (http/s) | `""` |
| `intake_brand_color` | string (hex) | `""` |
| `intake_brand_link_url` | string (http/s) | `""` |

(Row wording and exact placement in §2.13 given in Part C above.)
