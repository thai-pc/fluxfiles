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
  4. return the key → the gateway emails it → customer sets FLUXFILES_LICENSE_KEY
```

## Setup

```bash
# 1. Your Ed25519 signing secret (the one matching the core's embedded k1).
#    It's in docs/license-signing-key.key (gitignored). NEVER commit it.
export FLUXFILES_LICENSE_PRIVATE_KEY_FILE=/secure/path/license-signing-key.key
export FLUXFILES_LICENSE_DB=/var/lib/fluxfiles/licenses.sqlite   # customer DB
export FLUXFILES_LICENSE_ADMIN_TOKEN=$(openssl rand -hex 24)     # /issue, /licenses, /revoke

# 2. Polar (the store)
export FLUXFILES_POLAR_WEBHOOK_SECRET=whsec_...              # Polar → Settings → Webhooks
export FLUXFILES_POLAR_PLAN_MAP='{"<product_id>":"pro","<product_id>":"studio"}'

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

# run (dev) — behind nginx/caddy in prod
php -S 0.0.0.0:9000 server.php
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

## Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/webhook/polar` | Standard Webhooks sig | Issue on `order.paid`, then email the key |
| GET | `/claim?order_id=` | none (see below) | Hand the key to the checkout success page |
| POST | `/issue` | Bearer admin | Manual issue `{email, plan, sites?, domains?}` |
| GET | `/licenses[?email=]` | Bearer admin | List / lookup |
| POST | `/revoke` | Bearer admin | `{jti, status}` mark revoked/refunded |
| GET | `/health` | — | Liveness |

## Plans

`Plans.php` maps a plan id → `{edition, modules, sites, ttlDays, enforcement}`.
Override with a JSON file at `FLUXFILES_LICENSE_PLANS`. Defaults: `pro` (share+intake,
annual), `pro-monthly` (subscription), `studio`, `enterprise`, `lifetime` (no expiry).

## Notes

- **Idempotent** on `(gateway, order_id)` — a re-delivered webhook returns the existing
  key, never a duplicate.
- **Revoke** sets a DB status. Offline verify can't retroactively kill a key already in
  the wild — but it's your source of truth and it gates the **update channel**
  (`updatesAllowed()`), so a revoked/lapsed license can't pull new builds.
- **Device limits**: offline can't hard-cap installs. Use the `sites`/`domains` soft
  warning + the update-channel gate; add an online activation counter only if you must
  (breaks the air-gap promise → make it per-license opt-in).

## Test

```bash
php services/license-server/tests/test-license-server.php   # from the repo root
```

## Getting the key to the buyer

Two paths, on purpose — one is convenient, the other survives a closed tab:

1. **Email**, sent by this service on first issue. Only on a *first* issue: Polar
   delivers at-least-once, and re-sending on every retry would mail the buyer the same
   key repeatedly for one purchase. A mail failure is logged and never changes the HTTP
   response — the sale succeeded and the record is stored, so a non-2xx here would make
   Polar retry and leave the buyer wondering whether they were charged.

2. **`GET /claim?order_id=…`**, for the checkout success page. Set the Polar success URL
   to `https://you/success?checkout_id={CHECKOUT_ID}` and have the page call this.

`/claim` is the only unauthenticated endpoint that returns a secret, so it is narrow by
design: it never issues (the webhook is the only path that mints), it 404s for anything
not found so it cannot be used to probe which order ids exist, and it stops answering
after `CLAIM_WINDOW` (1h). That last part matters because a success URL can survive in
browser history, a screen share or a referrer header, and the window keeps that from
being a permanent handle on the key. After it closes, email is the way — or the operator
looks it up through `/licenses`.
