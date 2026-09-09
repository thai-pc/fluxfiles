# Proactive License-Expiry Notifications — Design

Status: **Design only, not implemented.** No code in this doc.

## 0. One-line summary

FluxFiles already computes a correct license lifecycle (`free|active|grace|
expired|perpetual` + `daysLeft()` in `LicenseManager.php`) and already 402s
paid-module calls on expiry, but nothing tells the operator *before* that
happens. This is four small, independent additions — one per surface (core
standalone UI, WordPress, Laravel, the vendor license-server) — none of which
introduce a new JWT claim, a database, a queue, or a daemon. The only new
state anywhere is two SQLite columns on the vendor back-office's existing
`licenses` table (§D), which is already the one stateful piece in this whole
system by design (`services/license-server/README.md`).

## 1. Problem (verified against the current codebase)

License status is **computed correctly** but **surfaced only on request**:

1. **Core standalone UI** (`packages/core/assets/fm.js`,
   `packages/core/public/index.html`) — `loadLicense()` (fm.js:3309) is only
   called from `openUsage()` (fm.js:3290, the toolbar "usage" button at
   index.html:204) or lazily/silently from `proGate()` (fm.js:3350). `init()`
   never calls it. The license banner markup (index.html:1692-1708) is fully
   built — edition/status text, a `grace`/`expired`/`perpetual` note, and a
   separate "renew soon" block for `active && days_left<=30` — but it lives
   entirely inside the `showUsage` modal (index.html:1682), which nobody
   opens unless they're specifically checking storage usage. i18n keys
   (`license.edition.*`, `license.status.*`, `license.note.{grace,expired,
   perpetual,renew_soon}`) already exist in all 16 locales — this design adds
   no new copy for §A, only new *triggering*/*placement* logic.
2. **WordPress** (`FluxFilesAdmin.php:271` `renderLicenseStatus()`) — renders
   a `notice-warning`/`notice-success` box, but only inside
   **Settings → FluxFiles**. Verified via grep: zero `add_action('admin_notices', …)`
   calls anywhere in `packages/wordpress/includes/*.php` — nothing surfaces
   on the main wp-admin dashboard or any other screen.
3. **Laravel** — no license UI at all. `FluxFilesController::license()`
   (`FluxFilesController.php:911`) exposes `GET /api/fm/license`, but it's a
   JWT-authed HTTP route (goes through `$this->rateLimit($this->claims($request), …)`),
   so it's unusable from a non-request context like a scheduled command.
   `\FluxFiles\LicenseManager::fromEnv()->info()` is the same in-process call
   the controller makes — nothing wraps it for reuse outside a request.
4. **No proactive email/webhook reminders anywhere.** The vendor back-office
   (`services/license-server/`) is request-driven only — `php -S 0.0.0.0:9000
   server.php`, no daemon, no cron wired (verified: no cron/systemd-timer
   file in that directory). `LicenseMailer.php` is solid and already used for
   the *initial* key-delivery email (`sendLicense()` → `renderBody()` /
   `renderSupportBody()`, branching on whether `modules` is empty). But
   `LicenseStore`'s `licenses` table (`LicenseStore.php:33-50`) has no column
   tracking whether a *renewal* reminder was ever sent — only `mailed_at`
   (delivery-of-the-original-key, a different event). No CLI entry point
   exists in the service today at all.

## 2. Design overview

Four independent pieces, one per surface. None talk to each other; none
introduce a claim or a database. Each is additive and off by default in the
sense that it only ever fires when there's actually something to warn about
(a configured, non-`free` license approaching or past expiry).

| # | Surface | What ships | New state |
|---|---|---|---|
| A | Core standalone `/public/` UI | Eager (not lazy) license fetch + a toolbar badge, non-framed only | none |
| B | WordPress plugin | Dismissible `admin_notices` box | 1 user-meta key (WP's own mechanism) |
| C | Laravel adapter | `FluxFiles::licenseInfo()` facade helper + README pattern, no bundled UI | none |
| D | `services/license-server/` | Cron-invoked reminder-email CLI script | 2 new SQLite columns on `licenses` |

D is the only piece that can reach an operator who never opens the admin UI
at all (email), and the only one that needs new persisted state, because it's
the only piece running outside a request/response cycle.

## 3. §A — Core standalone UI: eager fetch + toolbar badge

**Trigger scope: `window.parent === window` only.** `init()`'s standalone
branch (`fm.js:420`, the block that already special-cases "not in an iframe")
is where `this.token` gets set for the top-level `/public/` admin console —
today it ends with `if (this.token) { this.loadFiles(); this.loadQuota(); }`
(fm.js:505-507). Add `this.loadLicense();` alongside those two calls, in the
same `if` block, so it fires once per page load, for free (the fetch already
happens eventually via `proGate()` in the common case — this just moves it
earlier and makes it unconditional).

**Do not touch the framed/embedded path.** `proGate()`'s own comment block
(fm.js:3336-3346) already draws this exact line for Share/Intake: `window.parent
!== window` short-circuits to `'hidden'` *before* `loadLicense()` is even
called, specifically so an embedded end-customer's UI never shows the
*operator's own* vendor-license internals. A persistent "your FluxFiles
license is expiring" badge would be the same category of leak — it tells the
operator's paying customer something about the operator's vendor
relationship that has nothing to do with them, inside a product the customer
believes they're using directly. §A's eager fetch and badge are gated
identically: only in the top-level, non-framed console, which is by
construction the operator looking at their own admin surface (self-hosted
Docker eval, or the standalone deploy an operator runs for themselves).
Framed/adapter contexts keep today's fully lazy, on-demand-only behavior —
zero behavior change there.

**Badge.** A small corner dot/pill on the existing Usage toolbar button
(index.html:204, `@click="openUsage()"`), shown when:

```
licenseNeedsAttention || (licenseInfo.status === 'active' && licenseInfo.days_left != null && licenseInfo.days_left <= 30)
```

— i.e. exactly the union of the two conditions the modal banner already
renders (fm.js:3325-3328 `licenseNeedsAttention` getter, plus the inline
`active && days_left<=30` check at index.html:1704). No new computed state:
add one more Alpine getter (e.g. `licenseBadgeVisible`) that ORs the two
existing conditions, referenced from a `<span x-show="licenseBadgeVisible" class="ff-badge-dot">`
sibling inside the existing usage button. Style follows the codebase's
existing small-badge conventions — `.ff-pro-pill` (fm.css:3931, an absolutely
positioned corner pill on a toolbar affordance) is the closest existing
precedent for "small attention-getting mark on a toolbar icon"; a plain
colored dot (no text — the button already has a tooltip via `:title`) is
simpler and doesn't need an i18n string of its own. Clicking the button
still opens the same Usage modal, which shows the full existing banner —
unchanged, just now pre-warmed so it renders instantly instead of a beat of
loading state.

**Opt-out claim: deferred, not added.** The task's design direction asks this
to be decided explicitly. Recommendation: **no `hide_license_badge` claim in
v1.** The badge is scoped to `window.parent === window` already, i.e. it can
only ever appear on the operator's own top-level admin console viewing their
own license — there's no third party present to hide it from in that
context, unlike Share/Intake's `pro_hints` (which exists because an
unlicensed *reseller* might not want their own end-customers to see a "Pro"
teaser — a case that structurally cannot occur here since §A never renders
framed). Flag as a natural follow-up only if a hosted-reseller pattern
emerges where an agency runs the standalone console *for* a client who
shouldn't see the agency's own vendor-license state — not a known case today.

## 4. §B — WordPress: dismissible site-wide admin notice

New hook, `add_action('admin_notices', [$this, 'renderLicenseExpiryNotice'])`,
registered alongside the existing `admin_menu`/`admin_init`/
`admin_enqueue_scripts` hooks in `FluxFilesAdmin.php`'s constructor
(`FluxFilesAdmin.php:12-14`).

**Data path: reuse, don't duplicate.** Same call `renderLicenseStatus()`
already makes — `FluxFilesPlugin::licenseKey()` then `FluxFilesPlugin::license()->info()`
(`FluxFilesAdmin.php:277,283`). No new query, no new class.

**Fires only when:**
- `current_user_can('manage_options')` (matches every other admin-only
  surface in the plugin),
- a license key is actually configured (`licenseKey() !== ''`) — a pure free
  install has nothing to renew, so it must stay silent, matching
  `renderLicenseStatus()`'s own early return,
- `status` is `grace`, `expired`, or `perpetual`, **or** `status === 'active'
  && days_left <= 30`,
- **not** already dismissed for this exact state (see below).

**Dismissal, keyed by status + day-bucket, not a boolean.** A plain "seen it,
never show again" dismissal would silently suppress a *worse* later state —
dismissing "renews in 25 days" must not swallow "expired" three weeks later.
Store dismissal as a WordPress user-meta value (`fluxfiles_license_notice_dismissed`,
a string) whose value is the **bucket key** that was dismissed — e.g.
`active:30` (day bucket, computed the same way the reminder thresholds in
§D are, so the two surfaces agree on what a "bucket" means: the largest
threshold `days_left` has crossed) or `expired`/`grace`/`perpetual` (state
name alone, since those don't have a day dimension worth bucketing further).
The notice only renders when the *current* bucket differs from the stored
dismissed value — so re-entering a worse bucket (`active:30` dismissed →
`active:7` reached, or any active-bucket dismissal → `expired` reached)
un-suppresses it. Standard WP dismissible-notice markup
(`class="notice notice-warning is-dismissible"` + the site's own admin-notices
JS already handles the dismiss button and fires
`wp.a11y`/`common.js`'s AJAX dismiss event) posts back to a small
`wp_ajax_fluxfiles_dismiss_license_notice` handler that writes the bucket key
to user-meta — this is the same generic pattern every other WP plugin's
dismissible notice uses (there's no FluxFiles-specific precedent in this
plugin today to mirror, since no other `admin_notices` hook exists yet, so
this establishes the pattern rather than copying one).

**Content:** short text + a link to **Settings → FluxFiles** for detail
(reuses `renderLicenseStatus()`'s existing full presentation there — the
notice itself doesn't need to repeat edition/modules, just "your license
[status] — click for detail").

## 5. §C — Laravel: facade helper, no bundled UI

Add `FluxFiles::licenseInfo(): array` to `FluxFilesManager.php` (the class
backing the `FluxFiles` facade), implemented as a **direct in-process call**,
not an HTTP round-trip through `GET /api/fm/license`:

```
public function licenseInfo(): array
{
    return \FluxFiles\LicenseManager::fromEnv()->info();
}
```

This deliberately does **not** proxy the existing HTTP route. That route
(`FluxFilesController::license()`) requires a `Claims` object (it calls
`$this->rateLimit($this->claims($request), false)`), which only exists
inside an authenticated HTTP request — exactly the context a scheduled
Artisan command or a host app's own admin panel *doesn't* have. Since
Laravel's `composer.json` already requires `fluxfiles/fluxfiles` (core is a
regular Composer dependency, not a remote service, in both `proxy` and
`standalone` `config('fluxfiles.mode')`), `\FluxFiles\LicenseManager` is
always available class-side regardless of mode — `licenseInfo()` needs no
mode branching at all, unlike `endpoint()`/`iframeSrc()` which do.

**No bundled admin-notice component, unlike WordPress — stated as a
deliberate difference, not an oversight.** WordPress plugins own wp-admin: a
FluxFiles-authored notice fits the existing all-plugins-post-into-one-screen
convention. A Laravel app owns its *own* admin surface (Nova, Filament, a
hand-rolled panel, or none at all) — FluxFiles has no standard place to
inject UI into, and guessing wrong (a Blade partial nobody `@include`s, a
service-provider-registered notification channel nobody subscribed to) is
worse than shipping nothing. README documents the building block and two
suggested patterns:
- an Artisan scheduled command (`$schedule->call(fn () =>
  Log::warning('FluxFiles license: '.json_encode(FluxFiles::licenseInfo())))`
  or similar, gated on `days_left`), the host app's own choice of alerting;
- surfacing `FluxFiles::licenseInfo()` inside whatever admin panel the host
  app already has.

## 6. §D — license-server: proactive renewal-reminder emails

The only surface that can reach an operator who never logs in, and the only
one needing new persisted state (cron-invoked, not request-driven, so there's
no "last request's response" to piggyback on).

### 6.1 New CLI script

`services/license-server/send-renewal-reminders.php` — **not a daemon.**
Matches the service's existing philosophy: `README.md` documents `php -S` as
the *only* run mode today; this adds a second, equally simple invocation
style (a script that runs once and exits), never a long-running process.
Recommended crontab line (documented in the script's header comment and in
`README.md`), once daily, off-peak:

```
0 6 * * *  cd /path/to/services/license-server && php send-renewal-reminders.php >> /var/log/fluxfiles-license-reminders.log 2>&1
```

The script wires up the same classes `server.php` already constructs
(`LicenseStore`, `LicenseMailer`), reading the same env vars documented in
`services/license-server/README.md` (`FLUXFILES_LICENSE_DB`,
`FLUXFILES_MAIL_TRANSPORT`/`_FROM`/`_FROM_NAME`, etc. — no new mail-transport
plumbing). New env var: `FLUXFILES_LICENSE_REMINDER_DAYS` (default
`30,14,7,1`, comma-separated, descending) — the day-out thresholds that
trigger a "renewal approaching" email.

### 6.2 New `LicenseStore` columns

Two, added via the same guarded-`ALTER TABLE` pattern the file already uses
for `mailed_at`/`checkout_id` (`LicenseStore.php:59-68`, so existing DBs
migrate transparently on next boot, no separate migration runner needed for
a service this small):

| Column | Type | Purpose |
|---|---|---|
| `reminder_stage` | `TEXT NULL` | The smallest threshold-bucket already notified for this row — one of the configured day thresholds (as a string, e.g. `"7"`), or `"grace"` / `"expired"` for the two non-day touch points below. `NULL` = never reminded. Idempotency key: a row is only re-emailed when the *current* bucket is smaller/worse than the stored one, mirroring §B's dismissal-bucket logic so both surfaces treat "worse state supersedes a dismissed/already-sent better state" the same way. |
| `grace_days` | `INTEGER DEFAULT 14` | Mirrors `LicenseSigner::mint()`'s `graceDays` option (`LicenseSigner.php:73`, defaults to 14, and no current `Plans::DEFAULT` entry overrides it — verified). Persisted at issuance time (`LicenseIssuer::issue()` passes it through) so the reminder job can compute grace-window boundaries **exactly**, without hardcoding 14 and silently drifting if a future plan ever sets a custom `graceDays`. Cheap insurance for a column that costs nothing today. |

### 6.3 Query

Parallel to the existing `undelivered()` method (`LicenseStore.php:171`):

```
public function needingReminder(array $thresholdDays, int $now): array
```

Selects `status = 'active'` rows (matching `undelivered()`'s own exclusion of
revoked/refunded — never re-solicit someone whose order was reversed) where
`expires IS NOT NULL` (lifetime licenses, `expires=NULL`, never need a
renewal reminder — see §6.5) and either:
- `days_left := floor((expires - now) / 86400)` has crossed a threshold in
  `$thresholdDays` not yet reflected in `reminder_stage` (i.e. `reminder_stage`
  is `NULL` or a larger number than the threshold just crossed), or
- `now > expires` and `now <= expires + grace_days*86400` and
  `reminder_stage !== 'grace'` (grace-window touch point), or
- `now > expires + grace_days*86400` and `reminder_stage !== 'expired'`
  (hard-expired touch point).

One email per row per run at most — the query picks the single worst
(smallest) unfired bucket for that row, not one email per crossed threshold
in a single run (a row that hasn't run this script in 40 days shouldn't get
four backlogged emails for 30/14/7/1).

### 6.4 Email bodies

Two new `render*Body()` methods on `LicenseMailer`, mirroring the existing
`renderBody()`/`renderSupportBody()` split (branch on `trim($record['modules'] ?? '') === ''`,
same as `sendLicense()` already does):

- **Renewal-approaching** (`renderRenewalBody()` / `renderRenewalSupportBody()`):
  "Your FluxFiles {edition} license renews/expires on {date} ({N} days)." Plain
  text, same rationale as the original key-delivery email (a licence key or a
  renewal link is exactly the kind of string that survives forwarding into a
  ticket better as plain text).
- **Expired/grace** (`renderExpiryBody()` / `renderExpirySupportBody()`): see
  §6.5 for how the copy differs by `enforcement`.

`send-renewal-reminders.php` calls `LicenseMailer::sendReminder($record,
$bucket)` (a new public method, parallel to `sendLicense()`) which picks the
subject/body pair the same way `sendLicense()` already picks
`renderBody()`/`renderSupportBody()`.

### 6.5 `enforcement` changes the message, not the trigger

Both `perpetual` and `subscription` licenses get reminded on the same
threshold schedule — the difference is entirely in the **copy**, matching
`LicenseManager::status()`'s own split (`LicenseManager.php:223-236`):

- **`perpetual`** (annual/lifetime self-host): past `expires`, the install
  keeps running (`status()` returns `perpetual`, not `expired`) — only the
  update channel (`updatesAllowed()`) stops. Copy: "…your update access ends
  on {date}; the software keeps working, you just won't get new
  releases/security patches until you renew."
- **`subscription`** (monthly/hosted): past grace, the module genuinely
  stops working (`status()` returns `expired`, and `ModuleRegistry::require()`
  will 402 every gated call). Copy must convey the harder consequence:
  "…on {date} your paid features (Share/Intake/…) will stop working for your
  users."

### 6.6 Lifetime licenses (`expires = NULL`) — excluded, not a bug

`Plans::DEFAULT['lifetime']` sets `ttlDays: null`, which `LicenseSigner::mint()`
skips setting `expires`/`grace` entirely (`LicenseSigner.php:71`) — there is
nothing to remind about. `needingReminder()`'s `expires IS NOT NULL` filter
handles this structurally rather than as a special case in the query logic.

### 6.7 Delivery semantics

**At-least-once, safe to rerun** — same posture the README already documents
for webhook issuance ("Delivery is at-least-once; issuing is idempotent").
`send-renewal-reminders.php` should **only** advance `reminder_stage` *after*
`LicenseMailer::sendReminder()` returns `true` — a failed send (mail outage,
same failure mode `sendLicense()` already tolerates) leaves `reminder_stage`
unchanged, so the next cron run retries that row instead of silently losing
the reminder. A transport that partially succeeds (e.g. SMTP accepts the
message but the operator's inbox never gets it) is outside what any of this
can detect — matches `sendLicense()`'s own documented limit ("mail failure
never changes the HTTP response... the key remains recoverable through the
admin endpoint" — the renewal-reminder analog is: the row stays flagged
`reminder_stage=NULL`/stale, so a manual re-run or the next scheduled day
picks it up).

## 7. Threat / privacy model

Deliberately short — this feature reads existing non-sensitive status and
writes existing-shape outbound mail; it doesn't touch file bytes, JWTs, disk
access, or any request-authenticated surface.

- **No new attacker-reachable input.** §A/§B/§C only *read* `LicenseManager::info()`,
  which is already a documented "non-sensitive summary" (`LicenseManager.php:258`)
  served today over `GET /api/fm/license` with no special guarding. §D reads
  from the vendor's own SQLite DB and writes only to columns it created —
  no user input reaches any of §D's new code (the CLI script takes no
  arguments derived from a request).
- **Email recipient is unchanged from the existing key-delivery path** —
  `record['email']`, already validated by `filter_var(...,
  FILTER_VALIDATE_EMAIL)` at issuance time (`LicenseIssuer::issue()`) and
  again defensively inside `sendLicense()` (`LicenseMailer.php:50`). The new
  `sendReminder()` should apply the identical validation before sending —
  don't skip it just because the record already exists in the store.
- **No secret is ever included in a reminder email.** Unlike the original
  key-delivery email (which necessarily contains the license key itself),
  a renewal reminder never needs to repeat the key — it should link to the
  operator's own account/renewal page (out of scope for this doc — depends
  on whatever billing portal the gateway provides) rather than re-emailing
  the key. This narrows the blast radius of a reminder email landing in the
  wrong inbox compared to the original delivery email.
- **WordPress notice: capability-gated, not public.** `admin_notices` fires
  for every wp-admin screen a capable user views, but the handler itself is
  wrapped in `current_user_can('manage_options')` — same gate
  `renderLicenseStatus()` already assumes implicitly by living under Settings
  (which WordPress itself only lets `manage_options` users reach). No new
  privilege boundary is introduced.
- **Core badge: scoped to non-framed only (§A), which is itself the
  security-relevant design decision here**, not an afterthought — see §3's
  full reasoning. This is the one place in this feature where getting the
  scope wrong would leak operator-internal state to the wrong audience.
- **No SSRF surface.** Nothing here fetches an operator- or request-supplied
  URL. `LicenseMailer`'s existing SMTP/Resend transports are configured
  entirely from server env vars (same trust tier as everywhere else in this
  codebase env vars are treated — see `DB-STORAGE-MIGRATION-DESIGN.md` §11.9
  for the precedent of "operator .env config, not a claim, needs no
  `SsrfGuard`").
- **Rate/volume:** at most one email per license row per day (the cron
  cadence), and at most one email per row per run regardless of how many
  thresholds were skipped (§6.3) — no risk of a reminder storm even if the
  script isn't run for a long stretch and then catches up.
- **Error codes / i18n:** none needed. Nothing here is a JWT-authed API
  route returning an `error_code` to a client — §A's badge has no error
  state of its own (it silently doesn't render if `loadLicense()`'s existing
  failure fallback `{edition:'free',status:'free'}` applies, same as today);
  §B's notice simply doesn't render if the license call fails; §D's CLI
  script logs failures to its own log file, not to any of FluxFiles' 16
  `lang/*.json` locale files (this is vendor back-office tooling, not
  end-user-facing UI copy).

## 8. `docs/CONFIG.md` — no changes required

**Zero new JWT claims.** None of §A-§D introduce a per-tenant claim: §A's
badge visibility is derived entirely from existing `licenseInfo` state (no
claim gates it, per §3's "no opt-out claim in v1" decision); §B/§C/§D have no
JWT/`Claims` involvement at all (WP admin capability, a Laravel facade call,
and a back-office cron script respectively). `tests/unit/test-config-doc.php`
needs no changes — it only checks `Claims.php`'s `$payload->` reads, and none
are added.

**`FLUXFILES_LICENSE_REMINDER_DAYS` is *not* a `docs/CONFIG.md` entry.**
CONFIG.md's §3 (server env vars) documents **core**'s env vars (the
embeddable product) — it already lists `FLUXFILES_LICENSE_KEY` because core
itself reads that to *verify* a license. `FLUXFILES_LICENSE_REMINDER_DAYS`
belongs to `services/license-server/`, a wholly separate vendor back-office
process with its own env-var documentation in
`services/license-server/README.md` (which already documents
`FLUXFILES_MAIL_TRANSPORT`/`_FROM`/etc. — this fits the same table, not
CONFIG.md's). Document it there, alongside the existing mail-transport vars.

## 9. Package/file-by-file plan

**Core (`packages/core/`):**
- `assets/fm.js` — add `this.loadLicense();` to the standalone-init branch
  (near `fm.js:505-507`); add a `licenseBadgeVisible` getter next to
  `licenseNeedsAttention` (`fm.js:3325`).
- `public/index.html` — add a badge `<span>` inside the Usage toolbar button
  (near `index.html:204`), `x-show="licenseBadgeVisible"`.
- `assets/fm.css` — one small rule for the badge dot (no new class family
  beyond what already exists for `.ff-pro-pill`/`.ff-doctor-dot`-style
  indicators).
- No PHP changes in core at all — `GET /api/fm/license` and `LicenseManager`
  are untouched; this is 100% client-side triggering/placement.

**WordPress (`packages/wordpress/`):**
- `includes/FluxFilesAdmin.php` — register `admin_notices` hook in the
  constructor; add `renderLicenseExpiryNotice()` (reuses
  `FluxFilesPlugin::license()`); add the dismiss AJAX handler
  (`wp_ajax_fluxfiles_dismiss_license_notice`) writing the bucket key to
  user-meta.
- No new files — this is additive methods on the existing admin class.

**Laravel (`packages/laravel/`):**
- `src/FluxFilesManager.php` — add `licenseInfo(): array`.
- `README.md` — document the helper + the two suggested integration
  patterns (scheduled command / host app's own admin panel).
- No controller/route changes — deliberately not exposing this as a new HTTP
  endpoint beyond the existing `GET /api/fm/license`.

**license-server (`services/license-server/`):**
- `LicenseStore.php` — add `reminder_stage`/`grace_days` columns (guarded
  `ALTER TABLE`, same pattern as `mailed_at`/`checkout_id`); add
  `needingReminder(array $thresholdDays, int $now): array`; add
  `markReminderSent(string $jti, string $bucket): bool`.
- `LicenseIssuer.php` — thread `graceDays` (from `Plans`/mint options) into
  the stored row so `grace_days` is populated at issuance, not just on the
  signed key.
- `LicenseMailer.php` — add `sendReminder(array $record, string $bucket):
  bool`, `renderRenewalBody()`/`renderRenewalSupportBody()`,
  `renderExpiryBody()`/`renderExpirySupportBody()` (the `enforcement`-aware
  copy from §6.5).
- `send-renewal-reminders.php` — new CLI entry point (constructs
  `LicenseStore`/`LicenseMailer`, reads `FLUXFILES_LICENSE_REMINDER_DAYS`,
  calls `needingReminder()`, sends, calls `markReminderSent()` only on
  success).
- `README.md` — document the new env var, the cron line, and the new
  columns/idempotency model (mirroring how it already documents
  `undelivered()`'s idempotency-on-`(gateway,order_id)`).

## 10. Testing plan

**Core (`packages/core/tests/unit` or `tests/browser`):**
- A Playwright spec (extending `tests/browser`'s existing suite) asserting:
  the badge is absent when `licenseInfo` is `null`/`free`/`active` with
  `days_left > 30`; present for `grace`/`expired`/`perpetual`/`active &&
  days_left<=30`; and — the framing regression this design is built around —
  **absent when the page is loaded inside an iframe** even with a
  `grace`/`expired` license payload mocked, proving §A's `window.parent`
  gate actually holds (a test double serving the standalone page framed
  inside a wrapper page, matching how `tests/browser` likely already tests
  other framed-vs-standalone distinctions like `proGate`'s `'hidden'` state
  if such a test exists — otherwise this is the first one and should
  establish the pattern).

**WordPress (`packages/wordpress/tests/`):**
- Unit test with a stubbed `FluxFilesPlugin::license()` returning each
  status: assert the notice renders/doesn't render per the trigger rules in
  §4 (no key configured → silent; free install → silent; `active` with
  `days_left=45` → silent; `days_left=25` → rendered).
- Dismissal-bucket test: dismiss `active:30`, assert the notice stays
  suppressed while still in that bucket, then simulate `days_left` dropping
  into a worse bucket (`active:7`) or status changing to `expired`, and
  assert the notice **reappears** despite the earlier dismissal — this is
  the one behavior this design most needs a regression test for, since a
  plain boolean dismissal would pass every other test but silently fail this
  one.

**license-server (`services/license-server/tests/`):**
- `needingReminder()` threshold/idempotency test (extends the existing
  `test-license-server.php`): seed rows at various `days_left`, assert only
  rows crossing a threshold in `$thresholdDays` and not already at that
  `reminder_stage` (or a stricter one) are returned; assert a row already
  reminded at `reminder_stage='7'` is NOT re-selected for `days_left=6`
  falling into the same or a *less* urgent bucket re-run, but IS selected
  once it crosses `1`.
- Grace/expired touch-point test: a row past `expires` but within
  `grace_days` is selected once for `'grace'`, not repeatedly; a row past
  `expires + grace_days` is selected once for `'expired'`.
- Lifetime-license exclusion test: a row with `expires IS NULL` is never
  returned by `needingReminder()` regardless of `now`.
- At-least-once/idempotent-rerun test: mock `LicenseMailer::sendReminder()`
  to fail once then succeed; assert `reminder_stage` is unchanged after the
  failed attempt and only advances after the successful retry — this is the
  regression test for §6.7's "safe to rerun" claim.
- `enforcement`-aware copy test: assert `perpetual` vs `subscription` records
  render the two different expiry-body variants from §6.5.

## 11. Out of scope for v1

- SMS or any non-email channel.
- In-app push / browser notifications (beyond the toolbar badge itself,
  which is passive — no OS-level notification permission requested).
- Webhook-based reminder events (a `license.expiring` webhook payload)
  — the existing `webhooks` paid module dispatches on *storage* events
  (upload/delete/etc.) inside a request; a license-expiry cron tick has no
  such request to piggyback a dispatch on, and inventing a
  server-initiated webhook call for exactly one event type is a
  disproportionate addition for this feature. Revisit only if there's a
  concrete operator ask.
- Configurable per-operator reminder cadence beyond the single, server-wide
  `FLUXFILES_LICENSE_REMINDER_DAYS` env var — every license record on a
  given license-server instance shares the same threshold schedule. A
  per-license override would need a new store column and a reason to want
  it; none exists yet.
- A renewal/self-service "pay now" link embedded in the reminder email —
  depends entirely on whichever payment gateway (Polar today) exposes a
  customer-portal URL; wiring that up is a `LicenseIssuer`/gateway-specific
  follow-up, not part of this notification design.
- Laravel bundled admin-notice component (§5 explains why this is a
  considered exclusion, not a gap).
- A `hide_license_badge` opt-out claim for core (§3 explains why deferred).

## 12. Open questions

1. **§3's badge styling** — a plain dot vs. a numeric "days left" pill was not
   settled here; a dot is recommended (simpler, no i18n string, matches
   `.ff-doctor-dot`'s existing precedent for "something needs your attention,
   click to see what") but a reviewer should sign off before implementation.
2. **§4's AJAX dismiss handler** — this design assumes WordPress's own
   generic dismissible-notice JS (already loaded core-side in every wp-admin
   page) is sufficient without FluxFiles shipping any bespoke JS. If that
   assumption is wrong (e.g. the generic handler doesn't pass through a
   custom bucket-key payload cleanly), a small inline script may be needed —
   flagged here rather than assumed away.
3. **§6.4 email copy exact wording** — drafted at a "shape" level (what each
   variant must convey) here, not final marketing copy. Whoever implements
   this should get the actual sentences reviewed, especially the
   `subscription`-expiry variant given how consequential its message is
   (features stopping is a much harder thing to say than "you won't get
   updates").
4. **Should `send-renewal-reminders.php` also catch up a WordPress/Laravel
   install that has no cron of its own?** Out of scope as posed — §D's cron
   only reminds license *purchasers* (email on file at the vendor
   back-office), which is orthogonal to whether a given install's admin ever
   logs into wp-admin/the Laravel app to see §B/§C. A purchaser who ignores
   both the email and never opens the admin UI is not solvable by this
   design and isn't attempted here.
