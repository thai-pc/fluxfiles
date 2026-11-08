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
