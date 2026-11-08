# FluxFiles License Server (vendor back-office)

Issues + stores FluxFiles license keys when a customer buys. **Separate from the
stateless core** — this is your stateful back-office (the customer DB). The core only
ever *verifies* keys offline; this service *mints* them with your private key and
records who bought what.

```
purchase → gateway webhook → this service:
  1. verify webhook signature
  2. plan → sign an Ed25519 license (same key the core trusts)
  3. store the record in SQLite (email│plan│key│expires│sites│order)
  4. email the key to the buyer (and show it on the success page via /claim)
     → customer sets FLUXFILES_LICENSE_KEY
```

## Setup

```bash
# 1. Your Ed25519 signing secret (the one matching the core's embedded k1).
#    It's in docs/license-signing-key.key (gitignored). NEVER commit it.
export FLUXFILES_LICENSE_PRIVATE_KEY_FILE=/secure/path/license-signing-key.key
export FLUXFILES_LICENSE_DB=/var/lib/fluxfiles/licenses.sqlite   # customer DB
export FLUXFILES_LICENSE_ADMIN_TOKEN=$(openssl rand -hex 24)     # /issue, /licenses, /revoke
export FLUXFILES_UPDATE_STATUS_TOKEN=$(openssl rand -hex 24)     # update server only; not an admin token

# 2. Polar (the store)
export FLUXFILES_POLAR_WEBHOOK_SECRET=whsec_...              # Polar → Settings → Webhooks
export FLUXFILES_POLAR_PLAN_MAP='{"<product_id>":"pro","<product_id>":"studio"}'   # + lifetime/renewal ids when the deal is on

# 3. Licence delivery. Default transport is 'log' — an unconfigured server writes the
#    message to the error log instead of silently pretending it sent mail.
export FLUXFILES_MAIL_TRANSPORT=resend        # resend | smtp | sendmail | log
export FLUXFILES_MAIL_FROM=licenses@your-domain.com
export FLUXFILES_MAIL_FROM_NAME=FluxFiles
export FLUXFILES_MAIL_REPLY_TO=support@your-domain.com   # optional
export FLUXFILES_RESEND_API_KEY=re_...        # resend.com → API Keys
# — or, transport=smtp instead:
# export FLUXFILES_SMTP_HOST=smtp.provider.com
# export FLUXFILES_SMTP_PORT=587
# export FLUXFILES_SMTP_USER=...
# export FLUXFILES_SMTP_PASS=...

# run (dev) — behind nginx/caddy in prod; bind loopback, never 0.0.0.0
php -S 127.0.0.1:9000 server.php
```

Point the Polar webhook at `https://your-host/webhook/polar`, subscribed to
**`order.paid`**. Polar sends `order.created` first with `status: pending`; issuing
there would hand out a licence for a payment that can still fail, so that event is
acknowledged and ignored.

Polar follows **Standard Webhooks** — the signature covers
`{webhook-id}.{webhook-timestamp}.{raw-body}`, base64, `v1,`-prefixed in the
`webhook-signature` header — which is a different scheme from the plain
`hash_hmac(raw)` most gateways use. `PolarWebhook::verify()` implements it, including
the 5-minute replay window and multiple signatures during a secret rotation. It accepts
either `whsec_` interpretation (the string as-is, or base64-decoded per the spec)
because implementations disagree and guessing wrong silently drops every purchase.

Delivery is at-least-once; issuing is idempotent on `(gateway, order_id)`, so a retry
returns the same key rather than minting a second one.

## Sandbox instance (Polar sandbox)

Polar sandbox purchases must never reach the production instance. Run a second copy of
this server with its own state and three extra env vars:

| Var | Effect |
| --- | --- |
| `FLUXFILES_MAIL_SUBJECT_PREFIX` | Prepended, plus one space, to every outgoing subject (customer and ops), e.g. `[SANDBOX]`. Control characters (CR/LF included) are stripped, so it cannot inject a header; capped at 32 characters. Default empty. |
| `FLUXFILES_MAIL_ALLOW_TO` | Comma-separated exact addresses and/or `@domain` suffixes, case-insensitive. When set, mail to anyone else is **not sent** (licence, reminder, unmatched-renewal and ops mail alike) and the log says `mail suppressed by FLUXFILES_MAIL_ALLOW_TO (to=j***@host)`. Default empty = no restriction. Protects the reputation of the shared sending domain. |
| `FLUXFILES_LICENSE_INSTANCE` | Shown in `GET /health` as `{"ok":true,"instance":"sandbox"}`. Unset keeps the exact `{"ok":true}` body. |

A suppressed mail counts as **handled** (the mailer returns `true`), so `mailed_at` is
stamped and the Polar webhook answers 200 as usual. Returning `false` would leave the row
unmailed and every Polar retry and `/redeliver` run would try (and be suppressed) again,
forever. The consequence is that a suppressed licence is not re-sent later; read the key
through the admin `/licenses` endpoint if you need it.

What makes it a real sandbox, not just a labelled copy:

- **Own DB** (`FLUXFILES_LICENSE_DB`) and own admin/update tokens.
- **Own test signing key.** Generate any Ed25519 keypair that is NOT listed in core's
  `LicenseManager::PUBLIC_KEYS` (never reuse `k1`). Core does not trust it, so sandbox
  keys cannot unlock real modules. Generate it on the sandbox host; the secret never leaves it.
- **Polar sandbox organisation** with its own products (`POLAR_TOKEN_SANDBOX` in `polar-setup.php`),
  its own `FLUXFILES_POLAR_PLAN_MAP` and its **own webhook secret**, pointed at the sandbox host's
  `/webhook/polar`.
- Mail: `FLUXFILES_MAIL_ALLOW_TO` set to your own addresses, `FLUXFILES_MAIL_SUBJECT_PREFIX=[SANDBOX]`.

## FluxFiles Pro download (WordPress add-on)

Set `FLUXFILES_PRO_UPDATE_URL` (the update server's https base URL) and `FLUXFILES_LANDING_ORIGIN`.
`/claim` and `POST /pro-download` then ask the update server for the signed `fluxfiles-pro` manifest
(the licence goes in the Authorization header only) and hand on the expiring download URL. The Polar
webhook and `/redeliver` never call the update server; the licence e-mail only points at the landing
`/download` page, and only when both variables are set. Production needs the loopback vhost
(`/update-status`) on its own php-fpm pool, because `/claim` and `/pro-download` wait on an update server
that calls `/update-status` back.

## Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/webhook/polar` | Standard Webhooks sig | Issue on `order.paid`, then email the key; a full `order.refunded` marks it `refunded` |
| GET | `/claim?checkout_id=` | none (see below) | Hand the key to the checkout success page; for share/intake plans also `pro_download` (cached per licence, 3 s timeout, omitted on any failure) |
| POST | `/pro-download` | none, landing-origin CORS only | `{license_key}` in the body → `{pro_download:{url,version,expires_in}}`, a fresh signed FluxFiles Pro (WordPress) zip link. Generic 404 for any key that is unknown, revoked or without share/intake; throttled per IP (/64 for IPv6) and per licence. Needs `FLUXFILES_PRO_UPDATE_URL` and `FLUXFILES_LANDING_ORIGIN` |
| POST | `/refresh` | the current licence key as `Authorization: Bearer` (never in a URL or body) | Returns the newest key of the same subscription / renewal chain: `{status:"refreshed",license_key,jti,issued,expires}` or `{status:"current"}`. 401 `{error:"invalid key"}` for a bad signature; **one identical** 404 `{error:"not available",hint}` for everything else (unknown, revoked/refunded chain, support-only, no-expiry, stale, ambiguous); 429 + `Retry-After` (10/hour per licence, 120/hour per IP, IPv6 as /64). Never mints, no outbound calls, no CORS. See below |
| POST | `/issue` | Bearer admin | Manual issue `{email, plan, customer?, order_id?}` |
| GET | `/licenses[?email=]` | Bearer admin | List / lookup |
| POST | `/revoke` | Bearer admin | `{jti, status}` or `{subscription_id, status}` with status `revoked`, `refunded` or `active`. The `subscription_id` form applies it to every key of that subscription (so none can refresh) |
| POST | `/redeliver` | Bearer admin | Re-send every licence whose mail failed |
| POST | `/update-status` | Bearer update-status token | `{jti}` → `{active: bool}` for the update server |
| GET | `/health` | — | Liveness |

## Plans

`Plans.php` maps a plan id → `{edition, modules, sites, ttlDays, graceDays, enforcement}`.
Override with a JSON file at `FLUXFILES_LICENSE_PLANS`. Defaults: `pro` (share+intake,
annual perpetual use plus one year of updates/support), `pro-monthly` (subscription,
seven-day payment-recovery grace), `studio`, `enterprise`, and `lifetime` (the legacy
checkout id for lifetime **use** plus one year of updates/support). Existing no-expiry
keys remain valid for lifetime updates; this policy applies only to newly issued keys.

### Lifetime deal and updates renewals (prepared, off by default)

For a limited deal. Nothing is sold until the Polar products exist
(`polar-setup.php --with-lifetime`, see OPERATIONS.md "Switching on the lifetime
deal"); the plans below are inert in the meantime.

| Plan | Price | Grants |
|---|---|---|
| `lifetime` | Studio Lifetime $399 one-time | Studio modules, perpetual use, 12 months of updates (id kept for checkout compatibility) |
| `pro-lifetime` | Pro Lifetime $179 one-time | Pro modules (share, intake), perpetual use, 12 months of updates |
| `pro-renewal` | $29 one-time | +12 months of updates/support for an existing `pro-lifetime` licence |
| `studio-renewal` | $79 one-time | +12 months of updates/support for an existing `lifetime` licence |

**How a renewal works.** It is an ordinary `order.paid` for a renewal product.
`LicenseIssuer` (in one `BEGIN IMMEDIATE` transaction, so concurrent renewals extend
twice and keep a consistent chain) finds the buyer's newest dated, `active` licence
whose plan is in the renewal plan's `renews` list. The match is the lower-cased
**email**, and, because the checkout email is free text, also Polar's **customer id**:
when both the lifetime row and the renewal order carry one they must be equal (the id
is stored on every Polar-issued row; older/manual rows have none and match by email).
It mints a NEW key with that licence's edition and modules and
`expires = max(now, old expires) + 365 days` (renewing early keeps the days already
paid for). The row is stored with the renewal plan id (`pro-renewal`) and
`renews_jti` pointing at the row it extended, which is superseded (only that row).
Renewals chain.

- **The key is mailed to the licence's address, never served by `/claim`** (a renewal
  borrows an entitlement the checkout only claims to own).
- The mail says: replace `FLUXFILES_LICENSE_KEY` with the new key; only it carries the
  extended update window; the previous key keeps running the modules but its updates stop
  on its original date.
- **Refund / `/revoke` cascade.** Revoking or refunding a row sets the same status on
  every renewal descended from it. Refunding a renewal brings the row it extended back to
  `active`, so the buyer can renew again and is reminded again.
- **Cascades can cancel a paid renewal.** Refunding a middle renewal (base, R1, R2) also
  cancels R2 although its own order was not refunded. Each such row is logged (email
  masked) and sent to `FLUXFILES_OPS_EMAIL`, so you can reissue the lost year with
  `POST /issue {"plan":"pro-renewal","email":"<lifetime email>"}`.
- **Un-revoking** (`POST /revoke` with `status: "active"`) restores the chain shape: a row
  that has a live renewal goes back to `superseded`, and an un-revoked renewal supersedes
  the row it extended again. Renewals cancelled by a cascade are NOT revived: un-revoke
  each one individually.
- Two Polar purchases of the same lifetime plan under one email already supersede each
  other at purchase time (pre-existing behaviour, unchanged); a renewal then extends the
  surviving row. Only a manually issued twin stays independently active and renewable.
- Legacy no-expiry keys are not renewable (they already have lifetime updates) and do not
  shadow a newer dated lifetime under the same email.

**A renewal with nothing to renew is refused**, not treated as the base plan: that
would hand a $29 buyer a $179 product. Cases: no licence under that email, only an
annual/monthly plan, the wrong edition, a different Polar customer, a refunded/revoked
base, or only a legacy no-expiry key. The webhook logs `RENEWAL WITHOUT LICENCE`, ACKs
200 (a 5xx would just retry the same outcome and eventually disable the endpoint), and
emails the buyer ("we couldn't match your licence, reply and we'll fix or refund") plus
`FLUXFILES_OPS_EMAIL` if set. Manual fix:
`POST /issue {"plan":"pro-renewal","email":"<the email of the LIFETIME purchase>"}`
(`studio-renewal` for Studio), or refund in Polar. Optional `FLUXFILES_RENEWAL_URL` is
quoted in the reminder mails of one-time plans.

## Key refresh (`POST /refresh`)

A monthly subscription mints a new key on every `order.paid`. Instead of pasting it, the
customer's install (`fluxfiles license refresh`, FluxFiles Pro's updater) presents its
current key and receives the newest one. Design: `docs/design/LICENSE-KEY-REFRESH-DESIGN.md`.

- **Linkage.** The webhook stores Polar's `subscription_id` and `billing_reason`; a new
  key supersedes the earlier ones **of its subscription** (two subscriptions of one buyer
  no longer retire each other). Rows without a subscription id keep the email+plan
  behaviour, and refresh falls back to it only when unambiguous. One-time lifetime
  renewals link through `renews_jti`. Manual rows have no chain and are always `current`.
- **Never a downgrade.** Only a strictly newer `issued` with an `expires` no earlier than
  the presented key's is returned; `issued` is monotonic per subscription even if this
  host's clock steps back. An edition change inside a subscription is followed.
- **Bounds.** A key stops refreshing `FLUXFILES_REFRESH_MAX_STALE_DAYS` (default 90) after
  `expires` + its grace, and `POST /revoke {"subscription_id":"...","status":"revoked"}` is
  the kill switch. It records the subscription, so a later paid order of it is still
  recorded but stays revoked (not mailed, ops notified): **also cancel the subscription
  in Polar**, revoking here does not stop billing. Keys are never logged; log lines carry the jti and an outcome.
- **Exposure.** Public pool, public vhost, `location = /refresh` with its own
  `limit_req` zone and `client_max_body_size 1k`. Do not route it to the loopback/internal
  pool (sized for the update server), and never add `$http_authorization` to a log format.

### Deploying

Copy the **whole `services/license-server` directory** (minus `tests/`), including
`templates/` and `LicenseMailer.php`. A missing `templates/email.php` no longer breaks
mail (the text part is sent and a loud `LICENSE MAIL: HTML template failed` is logged),
but customers then get plain-text mail only.

**Rollback caveat:** rolling back to pre-refresh code ignores `revoked_subscriptions`; revoked subscriptions' new orders would issue active keys again.

### Migration and backfill (existing server)

The two new columns (`subscription_id`, `billing_reason`) and `idx_subscription` are
added automatically, additively and idempotently, the first time any request opens the
DB. Then fill in the subscription ids of rows issued before the webhook captured them:

```bash
export FLUXFILES_LICENSE_DB=/var/lib/fluxfiles/licenses.sqlite
export POLAR_TOKEN=polar_oat_...        # or --token-file=/secure/path (never an argument)
php backfill-subscription-ids.php            # dry run: asks Polar GET /v1/orders/{id}, writes nothing
php backfill-subscription-ids.php --apply
```

Idempotent; a one-time order is marked via `billing_reason` so it is not asked twice;
exit 2 means some orders could not be read (re-run). Run it before the next billing cycle
and before enabling `/refresh`.
**Refresh is not fully available to legacy customers until the backfill has run** (ambiguous legacy chains are refused): run the backfill before enabling the nginx `/refresh` location. A legacy row whose email+plan spans two subscriptions
stays refused (ambiguous) until you resolve it by hand.

## Notes

- **Idempotent** on `(gateway, order_id)` — a re-delivered webhook returns the existing
  key, never a duplicate.
- **Revoke** sets a DB status. Offline runtime verification cannot retroactively kill
  a key already in the wild, but the update server calls `/update-status` with its
  separate machine credential, so a revoked/refunded key cannot pull new builds.
- **Device limits**: offline can't hard-cap installs, and the key carries no
  site/domain fields (removed 2026-09-26 — nothing read them, every plan is unlimited
  sites). The update-channel gate is the lever; add an online activation counter only
  if you must (breaks the air-gap promise → make it per-license opt-in).

## Renewal reminders (cron)

Delivering the key once at purchase isn't enough — nothing else here proactively
tells a customer their licence is coming up on expiry. `send-renewal-reminders.php`
is a second, equally simple invocation style for this service: a script that runs
once and exits, invoked by cron rather than `php -S`.

```bash
export FLUXFILES_LICENSE_REMINDER_DAYS=30,14,7,1   # day-out thresholds, descending (default shown)

# crontab -e
0 6 * * *  cd /path/to/services/license-server && php send-renewal-reminders.php >> /var/log/fluxfiles-license-reminders.log 2>&1
```

It reuses every env var already documented above (`FLUXFILES_LICENSE_DB`,
`FLUXFILES_MAIL_TRANSPORT`/`_FROM`/`_FROM_NAME`/etc.) — no separate mail
configuration.

Two columns back this, added transparently to existing databases the same way
`mailed_at`/`checkout_id` were:

- **`reminder_stage`** — the smallest/worst bucket already notified for a row: one
  of the configured day thresholds (as a string, e.g. `"7"`), or `"grace"` /
  `"expired"` for the two touch points past the expiry date. `NULL` means never
  reminded. This is the idempotency key, the same way `(gateway, order_id)` is for
  issuance and `mailed_at` is for the first key-delivery mail: `needingReminder()`
  only returns a row when the *current* bucket (computed from `days_left` right
  now) is more urgent than whatever is stored here, so re-running the script
  daily never double-emails for the same bucket — it only fires again once the
  licence has moved into a worse one.
- **`grace_days`** — the grace window in days, persisted at issuance from the
  actual value `LicenseSigner::mint()` used (seven days for recurring plans;
  zero for perpetual update terms), so the reminder job
  computes the grace-window boundary exactly rather than hardcoding 14 and
  silently drifting if a future plan ever sets a custom value.

Only `status = "active"` rows with `expires IS NOT NULL` are ever considered —
revoked/refunded rows are never re-solicited (same exclusion `undelivered()`
applies), and a lifetime licence (`expires` is `NULL`) has nothing to remind
about. At most one email per row per run, and `reminder_stage` only advances
*after* `LicenseMailer::sendReminder()` reports success — a failed send (mail
outage) leaves the row due again on the very next run instead of silently losing
the reminder, the same at-least-once posture the webhook's `mailed_at` tracking
already has.

The `enforcement` on the record changes the wording of the past-expiry email, not
whether it fires: a `perpetual` licence keeps running past expiry (only the
update channel stops, with no runtime grace or shut-off), while a `subscription`
licence's paid features stop after its seven-day payment-recovery grace. Neither
path deletes customer files or module records — see `LicenseMailer::renderExpiryBody()`.

### Recurring subscriptions and duplicate rows

Every renewal of a recurring plan (`pro-monthly`, `studio-monthly`,
`support-monthly`) is a new Polar order with a new `order_id`, so `record()`'s
`(gateway, order_id)` idempotency mints a fresh row each cycle rather than
updating one in place. `LicenseIssuer::issue()` calls
`LicenseStore::supersedeActiveForBuyerPlan()` right after storing that new
row, which retires any other still-`active` gateway row for the same `(buyer
email, plan)` — so only the newest one stays eligible for `needingReminder()`.
It matches on the email, never the display name: two buyers who both call
themselves "John Smith" must not retire each other's licences. Manual
(`/issue`) rows are never superseded. Without
this, a customer who just renewed could get an "expired" nag (from the stale
row) in the same run as a "renews soon" nag (from the new one).

This only self-heals on a pair's *next* renewal — rows that were already
duplicated before this fix shipped stay duplicated until then. Run
`php backfill-supersede-duplicates.php` once (dry run by default; pass
`--apply` to actually write) to clean up any pre-existing duplicates
immediately instead of waiting for the next billing cycle. Safe to re-run
(no-op once there's nothing left to supersede).

## Test

```bash
php services/license-server/tests/test-license-server.php   # from the repo root
php services/license-server/tests/test-renewal-http.php
php services/license-server/tests/test-pro-download-http.php   # /claim cache, no-deadlock, /pro-download
```

## Getting the key to the buyer

Two paths, on purpose — one is convenient, the other survives a closed tab:

1. **Email**, sent by this service on first issue. Only on a *first* issue: Polar
   delivers at-least-once, and re-sending on every retry would mail the buyer the same
   key repeatedly for one purchase. A mail failure is logged and never changes the HTTP
   response — the sale succeeded and the record is stored, so a non-2xx here would make
   Polar retry and leave the buyer wondering whether they were charged.

2. **`GET /claim?checkout_id=…`**, for the checkout success page. Set the Polar success URL
   to `https://you/success?checkout_id={CHECKOUT_ID}` and have the page call this.

`/claim` is the only unauthenticated endpoint that returns a secret, so it is narrow by
design: it never issues (the webhook is the only path that mints), it 404s for anything
not found so it cannot be used to probe which order ids exist, and it stops answering
after `CLAIM_WINDOW` (1h). That last part matters because a success URL can survive in
browser history, a screen share or a referrer header, and the window keeps that from
being a permanent handle on the key. After it closes, email is the way — or the operator
looks it up through `/licenses`.

## Private admin UI (all phases)

The implementation follows [the approved design](../../docs/design/LICENSE-ADMIN-UI-DESIGN.md),
§0, §2–6, §7.1/3/4/7, §8.1/3 and §15. There is no new JWT claim, framework,
composer dependency, CDN or build step. Copy the directory with its `client/`,
`admin/`, `ops/` and mail templates intact. PHP 8.1–8.5 is supported; extensions:
PDO SQLite, sodium, curl, mbstring, openssl, json and zlib. The UI does not import
server classes or access the licence database. Its `admin.sqlite` contains only
users, sessions and login throttles.

Production uses **127.0.0.1:9471** for the API and **127.0.0.1:9472** for the UI.
Sandbox uses **:9473 / :9474**, its own users, token files and databases. Set
`FLUXFILES_LICENSE_INSTANCE=sandbox` in every sandbox process. There is no instance
switch or cross-instance operation. Sandbox pages have a fixed banner, amber
accent and `[SANDBOX]` titles, including login and errors; statistics are test data.
Sandbox sessions use the separate `fxadm_sbx` cookie.

Reach the UI through an SSH tunnel, for example:

```text
ssh -N -L 127.0.0.1:8471:127.0.0.1:9472 <licence-host>
```

Set the production UI's `FLUXFILES_ADMIN_HOSTS=prod.localhost` and the sandbox
UI's `FLUXFILES_ADMIN_HOSTS=sbx.localhost`. Mirror these exact Hosts in nginx.
Open `http://prod.localhost:8471`; forward sandbox to laptop port 8473 and open
`http://sbx.localhost:8473`. Modern browsers resolve `.localhost` to loopback.
Cookies are host-only: **ports do not isolate cookies**, so distinct ports on the
same `localhost` cannot protect production sessions from sandbox/local dev apps.
Legacy localhost/127.0.0.1 defaults remain available for single-instance testing.
Configured SSH Hosts may be exact one-label `.localhost` names; arbitrary public
hosts remain forbidden. Neither API port **9471/9473** nor UI port 9472/9474 may
listen on a public interface. Check with `ss -ltnp` in the deploy smoke.


Enroll an account as the appropriate **UI Unix user**, with its UI config loaded:

```text
php admin/cli/admin-user.php add owner
php admin/cli/admin-user.php passwd owner
php admin/cli/admin-user.php totp-reset owner
php admin/cli/admin-user.php disable owner
php admin/cli/admin-user.php logout-all owner
```

The password is read from stdin, hidden on a TTY. Keep the printed `otpauth://` URI
private and enroll it locally. Enrolment is separate per instance. Recovery is SSH
plus `totp-reset`; there is no browser enrolment or recovery code. Password changes,
TOTP resets and disabling a user revoke all their sessions. `admin.sqlite` is not
backed up; loss requires reenrolment.

Login uses password plus six-digit RFC 6238 TOTP, with replay prevention, rolling
username/global failure limits and 15-minute lockouts. Session tokens are random
32-byte values, stored only as SHA-256 hashes. They rotate at login and expire after
30 minutes idle or 12 hours absolute. Reveal, manual issue, restore, archive, replay, CSV, backup, advisory/notice changes
and optional Polar actions require a **separate fresh TOTP**. Login does not
count as step-up, and its code cannot be reused; use the next authenticator code.
A successful step-up lasts ten minutes. Revoke requires the typed final
six jti characters and a server-rendered cascade preview. Read-only pages retain native links and forms;
reveal is an explicit POST and clears after 60 seconds when JS is available. Close
or navigate away after a reveal when scripting is disabled. Never share a screenshot
of a revealed key. Copying to the clipboard is explicit.

The screens are Dashboard, Licences (filters, cycle collapse, lookup by POST, detail,
manual issue, flags, notes, archive and subscription revoke), Webhooks (read/resolve),
Audit, Customers, Mail log, Activity, advisories/notices, System/health/backup and
all revenue, install, version, cohort, download, growth, demo and anomaly statistics.
Vietnamese is the
default; English and light/dark/auto are session preferences. Times use
Asia/Ho_Chi_Minh with UTC on hover. Charts are server-generated SVG with table
fallbacks; each currency has its own chart. `j/k`, Enter, `/`, `x`, `g d/l/s/w/a`,
`?` and Escape provide keyboard navigation.

`licenses.sqlite` migrations are guarded by `PRAGMA user_version=4` and preserve
existing rows. Back up before migrating. History is written by connection-local
SQLite triggers, in the same transaction as the mutation, including bulk supersedes
and cascades. Offline SQLite tools do not depend on a PHP UDF; edits made outside
`LicenseStore` therefore do not create history. Never use an offline editor for
routine licence state changes.

### Process and token configuration

See [.env.example](.env.example) for examples; it is documentation, not an env loader.
Every secret setting below supports `_FILE` (preferred) or a process env value.
Each of the four tokens must be different; duplicates fail closed at API boot.
Tokens must also differ across instances, checked by the operator before installing.

| Setting | Default / purpose |
|---|---|
| `FLUXFILES_LICENSE_ROLE` | `all` for backward-compatible local development; set each deployed pool to `public`, `status` or `admin-api` |
| `FLUXFILES_LICENSE_ADMIN_TOKEN` / `_FILE` | Existing root CLI credential |
| `FLUXFILES_UPDATE_STATUS_TOKEN` / `_FILE` | `/update-status` only |
| `FLUXFILES_LICENSE_UI_TOKEN` / `_FILE` | UI credential; `X-FluxFiles-Actor: ui:<name>` is honoured only with this token |
| `FLUXFILES_LICENSE_OPS_TOKEN` / `_FILE` | Worker; `/admin/ops/*` only |
| `FLUXFILES_POLAR_WEBHOOK_SECRET` / `_FILE` | Existing Polar verification secret |
| `FLUXFILES_RESEND_WEBHOOK_SECRET` / `_FILE` | Unset; Svix delivery webhook verification secret |
| `FLUXFILES_LICENSE_NET_PEPPER_FILE` | Unset; 32 random bytes, owner-only, HMAC of network prefixes; without it no network identifier is stored |
| `FLUXFILES_LICENSE_K1_PUB` | Unset; base64 Ed25519 public key for refresh verification and signing-key comparison; never a signing key |
| `FLUXFILES_LICENSE_BACKUP_STATUS` | `/var/lib/fluxfiles-license/backup-status.json` (sandbox `license-sbx`), root backup JSON described below; the legacy `{"ok":true,"at":<unix seconds>}` is also accepted |
| `FLUXFILES_ADMIN_TRACKED_DISCOUNTS` | Unset; `CODE:CAP[,CODE:CAP]`, e.g. `FOUNDING40:50` |
| `FLUXFILES_ADMIN_TZ` | `Asia/Ho_Chi_Minh`; accounting month boundaries and UI display |

Set the status role without signing-key env. The signer is lazy: issuing initializes it; refresh uses the configured public key and falls back to the signer only when that value is unset. Admin reads and update-status do not require it.
nginx must still route each location to the correct pool; the role check is defence
in depth, not a replacement for that split. No HTTP route downloads a DB or backup.

| UI-only setting | Default / purpose |
|---|---|
| `FLUXFILES_ADMIN_API` | `http://127.0.0.1:9471`, sandbox `:9473`; literal http loopback only, no redirects |
| `FLUXFILES_ADMIN_UI_TOKEN` / `_FILE` | Own 0400 copy of the instance's UI token |
| `FLUXFILES_ADMIN_DB` | `/var/lib/fluxfiles-license-admin/admin.sqlite`, sandbox `/var/lib/fluxfiles-license-sbx-admin/admin.sqlite` |
| `FLUXFILES_ADMIN_HOSTS` | `localhost,127.0.0.1` for local tests; deploy `prod.localhost` / `sbx.localhost`, exact per-instance allowlist |
| `FLUXFILES_ADMIN_LANG` | `vi`, or `en` |
| `FLUXFILES_ADMIN_SESSION_IDLE` / `FLUXFILES_ADMIN_SESSION_ABSOLUTE` | `1800` / `43200` seconds |
| `FLUXFILES_ADMIN_POLAR_ORG` | Unset; links are hidden until configured |
| `FLUXFILES_ADMIN_POLAR_ORDER_URL` | `https://polar.sh/dashboard/{org}/sales/{id}` |
| `FLUXFILES_ADMIN_POLAR_SUBSCRIPTION_URL` | `https://polar.sh/dashboard/{org}/sales/subscriptions/{id}` |
| `FLUXFILES_ADMIN_POLAR_CUSTOMER_URL` | `https://polar.sh/dashboard/{org}/customers/{id}` |

Verify the configurable Polar URL paths in the live dashboard. Links are restricted
to https://polar.sh and use `noreferrer noopener`. Secrets and bearer tokens never
reach browser HTML. The UI's own API calls connect in 2 seconds and time out at 30.
UI-triggered `/redeliver` is capped at 10. CLI `/licenses` remains backward compatible
and audits bulk key access; UI calls receive masked rows. Only the explicit reveal
endpoint returns a key to the UI. Existing public buyer-key and refresh contracts
are unchanged.

### Revenue, telemetry and delivery

Verified paid and refund orders are recorded even if a product is unmapped or its
renewal cannot match. Amounts are strict integer minor units; currencies are never
summed. Net revenue subtracts refund-net in the month of the refund, in
`FLUXFILES_ADMIN_TZ`. MRR uses the newest active, unexpired subscription licence and
its latest non-refunded order; annual plans divide by 12. Historical MRR is labelled
an estimate because subscription state history before migration cannot be reconstructed. Refunded discount
redemptions remain separate and conservatively consume cap slots. Manual orders
and test licences are excluded from accounting.

One-off order backfill uses a token only for the duration of the process:

```text
php backfill-orders.php --token-file=/secure/one-off-polar-token
php backfill-orders.php --token-file=/secure/one-off-polar-token --apply
php backfill-orders.php --token-file=/secure/one-off-polar-token --all --apply
php backfill-orders.php --sandbox --token-file=/secure/sandbox-token --apply
```

Dry-run uses a temporary SQLite copy, including migrations, and never writes the
source. `--all` paginates all paid/refunded orders; otherwise only missing licence
orders are fetched. Options: `--limit=N`, `--delay-ms=N` (150), `--backoff-ms=N`
(1000), `--api-base=URL` (official Polar API or literal http loopback for tests).
`POLAR_TOKEN` / `POLAR_TOKEN_SANDBOX` are also accepted. No token appears in arguments
or output. Apply is idempotent.

`/update-status` accepts optional `module`, `current`, `core_version`, boolean
`install`, and a canonical IPv4 /24 or IPv6 /64 `net` prefix. Invalid optional fields
are discarded. The response bytes are unchanged, emitted before telemetry; under
FPM `fastcgi_finish_request()` releases the response first. Pings are lossy and use
a 250 ms busy timeout. Only an HMAC prefix identifier is persisted, never an IP or
raw prefix. The example update server supplies these fields; root must update the
private deployed copy and keep its URI-only nginx logs.

`LicenseMailer`'s optional fourth constructor argument records send results and
Resend provider IDs, without a mail-body copy. All request and reminder call sites
provide it. POST `/webhook/resend` validates Svix signatures and handles delivered,
bounced, complained and delayed events. Unknown provider IDs are ACKed. Licence
mail bounces enqueue alerts. Failed Polar bodies are compressed, retained only for
unmapped/unmatched/error outcomes for seven days, and never returned by the API.
Resolve clears the body. Bad signatures aggregate by hour/reason with no trusted
payload fields. Recording failures never change Polar's existing response codes.

Retention: history, licences and orders forever; webhook metadata 180 days, bodies
7 days; mail 365 days; audit 730 days; pings 90 days; health and completed outbox
30 days. The worker triggers pruning daily and webhook writes sample it 1 in 50.

### Ops worker and Telegram

Run `php ops/ops-worker.php run` as the separate ops user from a one-minute systemd
timer. It takes a nonblocking file lock, uses atomic 0600 state files, and has a
53-second application budget plus a 55-second systemd timeout. No signing-key or
licence-DB env is passed. It calls its own loopback API, Telegram, configured health targets and the four hardcoded public counter APIs. Health URLs use HTTPS (literal HTTP loopback is allowed for tests),
with no redirects; do not configure any URL that starts an update-status call.

| Worker setting | Default / purpose |
|---|---|
| `FLUXFILES_ADMIN_API` | Same per-instance loopback URL as UI |
| `FLUXFILES_LICENSE_OPS_TOKEN_FILE` | Own 0400 copy; `FLUXFILES_LICENSE_OPS_TOKEN` is also supported |
| `FLUXFILES_OPS_STATE_DIR` | `/var/lib/fluxfiles-license-ops`, sandbox `/var/lib/fluxfiles-license-sbx-ops`; never shared across instances |
| `FLUXFILES_OPS_HEALTH_TARGETS` | `[]`; JSON `[{"name":"license","url":"https://…/health","expect_status":200}]`; operator sets licence, update, demo and landing targets |
| `FLUXFILES_OPS_DISK_PATHS` | `/`, comma-separated traversable mount points |
| `FLUXFILES_TELEGRAM_BOT_TOKEN_FILE` | Unset, Telegram off |
| `FLUXFILES_TELEGRAM_CHAT_ID` | Unset, Telegram off |
| `FLUXFILES_TELEGRAM_THREAD_ID` | Unset, optional forum topic ID |
| `FLUXFILES_TELEGRAM_TAG` | `FluxFiles`, sandbox `FluxFiles SANDBOX`; sandbox suffix cannot be removed |
| `FLUXFILES_TELEGRAM_SANDBOX` | `0`; explicit `1` enables sandbox critical alerts only |
| `FLUXFILES_TELEGRAM_COMMANDS` | `0`; `1` enables read-only `/stats`, `/today`, `/help` |
| `FLUXFILES_TELEGRAM_ADMIN_USER_ID` | Required to accept commands in a supergroup |
| `FLUXFILES_TELEGRAM_API_BASE` | `https://api.telegram.org`; override only to literal http loopback (tests) |
| `FLUXFILES_RESEND_API_BASE` | `https://api.resend.com`; override only to literal http loopback (tests) |
| `FLUXFILES_ADMIN_TEST_CLOCK` | `0`; only `cli-server` with value `1` accepts `X-FluxFiles-Test-Time`; never deploy enabled |

Create **one bot per product** using BotFather; FluxFiles' production and sandbox
may share that product's bot/chat. Keep privacy enabled, then disable joining new
groups. Install the token as 0400 ops-owned, send `/start` privately, and discover
the chat ID using the operator's CLI `telegram-setup` command (one `getUpdates`
call during setup). The runtime never calls `setWebhook`. When commands are off it
never calls `getUpdates` or `getMe`. In groups, use `/stats@<bot>` and configure the
admin user ID and optional thread ID. Other chats, authors, topics and commands are
ignored; the update offset advances. Command replies share the alert budget.

Alerts are plain text (`parse_mode` is absent), prefixed with the instance's tag.
Buyer emails are masked; checkout IDs, keys and tokens never enter alerts. Both the
outbox and worker refuse unmasked text. Claims lease rows for 120 seconds, with at
most 20 rows per call. A flood collapses into 19 messages plus a digest; all sends
and command replies share a persisted 60/hour and 20/run budget with 1.1 seconds
between attempts. HTTP 429 honours retry_after; other failures back off 1/2/4…60
minutes and drop after ten attempts. Errors are status-only and never provider
bodies, curl messages or token URLs. Queued alerts remain available when Telegram
is disabled. Health checks run every five minutes: two failures trigger down, then
recovery; TLS under 14 days, disk under 10%, and backups older than 26 hours alert.
The heartbeat reports Telegram's token fingerprint, never its token.

Phase 2 and Phase 3 are implemented: CSV/export, replay, mail-log screen, customers,
subscription webhooks, public counters, inbox ingest, weekly reports, notices,
anomalies, viewer permissions and optional Access/Polar actions. See
[ADMIN-PR.md](ADMIN-PR.md) for the exact ordered server-change checklist, deviations
and validation. No server configuration or deployment is changed by this branch.

Chromium sends `Origin: null` for native POST navigations under the required
`Referrer-Policy: no-referrer`. `admin.js` submits forms through same-origin fetch
in CORS mode, preserving the actual Origin and the strict policy. The server does
not weaken its Origin check to accommodate native POSTs; browsers with that
behaviour require JS for sign-in and mutations. Read-only pages and server-rendered
confirmation forms remain available. This browser-policy conflict is recorded in
the PR deviations.

### Phase 2 and Phase 3 API

The UI credential accesses `/admin/*` reads and operator actions. The UI forwards a
trusted `X-FluxFiles-Role: admin|viewer` derived from its local user record; a viewer
can read but cannot reveal keys, export, issue or mutate. Only login audit writes
are permitted. The API independently enforces this restriction for the UI token.
The root CLI credential ignores spoofed UI actor/role headers. The ops token can
call only `/admin/ops/*`; it cannot read customers, keys or accounting exports.

| Routes | Behaviour |
|---|---|
| `GET /admin/customers`, `GET /admin/customers/{chash}`, `POST …/{chash}/note` | Prefix search, keyset cursor, lower-case email grouping, identity-conflict flag, currency-separated net, timeline and audited 500-character notes. The URL uses the first 16 hex characters of SHA-256(email); collision fails closed. |
| `GET /admin/mail`, `POST /admin/mail/{id}/resend` | Date/kind/failure filters and keyset pagination. Resend only licence/support mail with a linked eligible licence. `/redeliver` is capped at ten and a 20-second send budget; the browser repeats batches until drained or a failure. |
| `POST /admin/events/{id}/replay` | Reuses only a verified, retained, unexpired Polar body through the normal idempotent issue path. No raw webhook body is exposed. Step-up in UI, audit, and resolve associated items on success. |
| `GET /admin/activity`, `GET /admin/ops-items`, `POST …/{id}/resolve` | Aggregated public outcomes and the operator backlog. Unknown requests have empty jti; no IP or key is kept. |
| `GET /admin/stats/{customers,versions,downloads,growth,demo,anomalies}` | Customer/cohort tables, reported field versions, distinct download sources, keyless public counters, demo counts and informational anomalies. |
| `POST /admin/advisories`, `GET /admin/advisories`, `GET …/{id}/affected` | Version constraints support attached `< <= = != >= >`, whitespace AND and `||` OR, with optional `v` versions. Invalid constraints are rejected. Unknown installed versions remain explicit and optionally affected. |
| `GET /admin/export/orders.csv` | Step-up UI download, audited; major-unit amounts per currency, UTC/local dates, UTF-8 BOM, formula prefix protection, no keys. At most 24 months/50,000 rows. Manual/test/archived orders excluded. |
| `GET /admin/health`, `POST /admin/backup` | Uptime/latest TLS/disk/heartbeat; integrity-checked 0600 SQLite snapshot into a private staging directory. Never downloads backups. Six manual snapshots/hour maximum. |
| `GET /admin/notices`, `GET /admin/notices/preview`, `POST /admin/notices/send` | Deduplicated eligible emails, five masked samples, rendered preview, typed advisory confirmation and step-up. A cohort fingerprint detects changes since preview; request receipts prevent duplicate queueing. Approved templates/advisory/versions are snapshotted. |
| `POST /admin/ops/ingest` | Strict counts-only `{type,day,rows}`; types `public_counters`, `downloads_daily`, `demo_daily`. Daily UPSERTs are idempotent. Unknown fields, raw identities, invalid days and negative/noninteger counts fail closed. |
| `POST /admin/ops/mail-jobs`, `POST /admin/ops/report/weekly` | API sends mail with its own credentials; worker never receives a mail token or licence key. Jobs claim up to ten for 120 seconds, bounded to 12 seconds of sends, with retry/backoff and exhaustion after ten attempts. |
| `POST /admin/subscriptions/{id}/cancel` | Optional, off by default. Per-request `subscriptions:write` token, typed subscription id, UI step-up and audit. Requests `cancel_at_period_end=true`; no token is persisted or returned. |

Subscription canceled/uncanceled events toggle reminder suppression without
revoking a paid period. Past-due subscriptions appear separately and are excluded
from current MRR. A revoked event revokes all subscription rows only when the
paid period has **more than 48 hours** remaining (current_period_end, then ended_at,
with licence expiry only for legacy missing period fields). Unknown subscriptions
ACK 200; older events cannot undo newer state. Refunds leave an operator item until
cancellation is observed. Enable these four subscription events at Polar after
migration. `subscription.created/updated` are also accepted for known subscriptions.

Install/field-version identity follows a subscription or lifetime-renewal root,
so rotating a key does not lose an installation. Versions use its latest component
ping over 30 days. Never-installed rows exclude manual licences and wait seven
days after max(original purchase time, telemetry migration time). Downloads
keep `zip_log`, `pro_link_claim` and `pro_link_download` separate from update checks.
Retention uses recorded licence history at month end; pre-migration history and
historical MRR are estimates. A refund rate with nonpositive net is undefined.
Anomalies show thresholds and **never automatically revoke**. The weekly report
covers the previous completed ISO week at the first run after Monday 08:00 in the
configured timezone, plus current month-to-date data. A per-week receipt and a
Resend idempotency key prevent duplicate sends. An interrupted ambiguous `sending`
receipt requires operator inspection before retry; SMTP cannot guarantee exactly
once after a process crash.

The mail logger always masks real keys and email addresses, including CLI log
transport. The old `FLUXFILES_MAIL_LOG_FULL` diagnostic flag no longer bypasses it.

### Additional configuration and root collector

| API setting | Default / purpose |
|---|---|
| `FLUXFILES_LICENSE_BACKUP_STAGE` | `/var/lib/fluxfiles-license/backup-stage`, sandbox `license-sbx`; private snapshot staging for root's existing backup sweep |
| `FLUXFILES_ADMIN_POLAR_ACTIONS` | `0`; enable the optional cancel button only after choosing a scoped runtime token workflow |
| `FLUXFILES_POLAR_API_BASE` | `https://api.polar.sh/v1`, sandbox `https://sandbox-api.polar.sh/v1`; override only to literal HTTP loopback for tests |
| `FLUXFILES_ADMIN_ANALYTICS_URL` | `https://dash.cloudflare.com/`; link only, HTTPS Cloudflare dashboard host required |
| `FLUXFILES_ADMIN_DEMO_BUDGET_BYTES` | `0` (no gauge denominator); operator's upload-byte budget |

| Worker setting | Default / purpose |
|---|---|
| `FLUXFILES_OPS_PUBLIC_COUNTERS` | `{}`; source-to-subject arrays, e.g. `{"wporg":["fluxfiles"],"github":["owner/repo"],"packagist":["vendor/pkg"],"npm":["@scope/pkg"]}`; at most 20 distinct requests/day, no credentials |
| `FLUXFILES_OPS_COUNTER_API_BASE` | Unset; literal HTTP loopback fake-provider override only |
| `FLUXFILES_ADMIN_TZ` | `Asia/Ho_Chi_Minh`; weekly scheduling |

Failed counter fetches preserve the previous snapshot and record
`counter:<source>` health failures. Sandbox never polls bot commands, so it cannot
consume production's update cursor when sharing the product's bot.

Root runs `collect-ops.php` at 00:10 local with **yesterday's** update/demo logs:

```text
php collect-ops.php --day=2026-11-07 --update-log=/private/update-access.log \
  --demo-log=/private/demo-access.log --cleaner-log=/private/demo-cleaner.log \
  --mint-path=/sandbox/mint --output=/var/lib/fluxfiles-license-ops/inbox/2026-11-07.json
```

Omit `--day` for yesterday in Asia/Ho_Chi_Minh. The reference parser accepts nginx
combined logs or JSON with `uri`/`request_uri`, `status`, `time_iso8601`/`time`.
Cleaner summaries accept JSON counts (optionally `day`) or
`sandboxes_live=N uploads_bytes=N`. Adapt the **root helper**, after reviewing the
private log format; never give log permissions to API/UI/ops. Query strings,
client IPs and identities never enter its output. It writes `<day>.json` atomically
as 0640 ops:ops-group. The root CLI requires PHP POSIX and irreversibly drops
to the inbox owner before filesystem writes, preventing privileged writes in a
worker-writable directory. Make the inbox **ops-owned 0750**, enabling worker
acknowledgement/unlink, unlike an unwritable root-owned inbox. Valid files are
posted then deleted, malformed files renamed `.bad`; API failure preserves input.

Root's backup tool writes status atomically, readable only by the licence API:
`{last_ok_at,file,bytes,integrity:"ok",kept,oldest_at,last_error}`. Timestamps are
integer Unix seconds; preserve `last_ok_at` across failures and set `last_error`.
The operator retains/sweeps staged snapshots and backups; HTTP never serves them.
Retention adds 730 days of daily counters, 90 days activity and 180 days resolved
ops items. Pending outbox rows expire after 30 days even with Telegram off;
notice failure counters are updated consistently. Dedup/notice/report receipts
expire after 730 days; notices/advisories/notes and accounting history persist.

### Optional Cloudflare Access and viewer accounts

Default access remains an SSH tunnel to loopback. Access is an **additional** gate;
password/TOTP and step-up remain required. No public nginx admin vhost is added.
A separately reviewed Tunnel/Access policy may forward only to that loopback UI.

| UI setting | Default / purpose |
|---|---|
| `FLUXFILES_ADMIN_ACCESS_ENABLED` | `0` |
| `FLUXFILES_ADMIN_ACCESS_HOST` | Unset; exact application hostname when Access is enabled |
| `FLUXFILES_ADMIN_ACCESS_ISSUER` | Unset; `https://<team>.cloudflareaccess.com` |
| `FLUXFILES_ADMIN_ACCESS_AUD` | Unset; application audience |
| `FLUXFILES_ADMIN_ACCESS_JWKS_FILE` | Unset; root-refreshed, UI-readable JWKS; stale after 24 hours |

Before enabling, configure the expected issuer/audience and run root's
`php admin/cli/access-keys.php`. Refresh hourly with the same UI-specific config;
failures preserve the last valid file. It validates RS256, kid, signature,
issuer/audience and time; missing/expired/invalid assertion or stale/missing keys
fail closed, including assets. API/UI do not fetch JWKS on requests. Forward the
Access assertion and original HTTPS scheme only through the trusted loopback
proxy. Route assets through the gate too; a direct static nginx asset route would
bypass application validation.

Use `admin-user.php add <name> [admin|viewer]` or
`admin-user.php role <name> <admin|viewer>` under the UI Unix user. Existing users
migrate to admin; changing role invalidates their sessions. Viewers retain language,
theme and logout controls but cannot reveal, export or mutate business data.

Browser smoke starts only local fixtures. It uses `php` from PATH and either a
locally resolvable Playwright or the monorepo browser installation. Install an
isolated test dependency when neither exists:

```sh
npm install --prefix /tmp/fluxfiles-admin-browser --no-audit --no-fund playwright@1.60.0
/tmp/fluxfiles-admin-browser/node_modules/.bin/playwright install chromium
FLUXFILES_TEST_PLAYWRIGHT_MODULE=/tmp/fluxfiles-admin-browser/node_modules/playwright \
  node services/license-server/tests/browser-admin.cjs
```

Optional `FLUXFILES_TEST_PHP_BINARY` selects a PHP binary;
`FLUXFILES_TEST_OUTPUT_DIR` selects screenshot output (default:
`os.tmpdir()/fluxfiles-admin-screenshots`). No machine-specific binary or temp path
is required. `.github/workflows/license-admin.yml` runs every PHP suite on
8.1–8.5, installs isolated browser dependencies, runs Chromium and uploads captures.
It also verifies the root collector's actual privilege drop on the disposable
runner; normal local runs never invoke sudo.


### Review hardening and accounting migration

**All four machine tokens must be nonempty and pairwise unique per instance**;
all eight across production/sandbox must also differ. `MachineAuth::tokens()`
throws on equal configured credentials. Validate this before restarting pools.

The v4 migration creates `order_refunds` and the observation timestamp without
rewriting licence keys. Polar's Order schema supplies `modified_at`, not a refund
timestamp: refund facts use that value, then the webhook envelope's `timestamp`,
then receipt time only if neither exists. Duplicate snapshots preserve the first
recorded date. Each cumulative snapshot allocates its incremental amount/tax to
its own month, including out-of-order delivery. An order-only backfill cannot
reconstruct missing earlier partial-refund history; replay retained original
snapshots to refine it. See the official [Polar refund event schema](https://polar.sh/docs/api-reference/webhooks/order.refunded).

CSV exports redact key-shaped hostile cells instead of failing, record the
redaction count in the audit, and keep the BOM/formula guard. The UI download GET
requires same-origin Fetch Metadata or an exact Origin fallback; initiate it via
the export form. Key reveals are additionally limited by API actor to ten per
ten minutes, with an audited refusal and a burst alert.

Worker heartbeat reports stage names only. Delivery runs immediately after the
initial heartbeat; failures in probes, jobs, ingest, reports or commands do not
abort unrelated stages. Health resumes skipped targets without resetting its
schedule. Telegram 429 stores a global blocked-until for every send and poll.
Weekly report preparation failures clear the lease into failed; failed sends
retry with 1/2/4…60-minute backoff. Vietnamese is the default operational language;
set `FLUXFILES_ADMIN_LANG=en` consistently in API and worker for English.

The optional Polar cancel token grants **organisation-wide**
`subscriptions:write`, not just the selected customer's subscription. Use a
short-expiry token, paste it only for the confirmed action and revoke it after
use. Never keep it in an env file or browser storage.

The PHP 8.5 path uses the native SQLite `createFunction` method and avoids
no-op/deprecated cURL/reflection calls. The legacy method remains only as the
PHP 8.1–8.3 compatibility fallback. See [PHP's SQLite deprecation note](https://www.php.net/manual/en/pdo.sqlitecreatefunction.php).
