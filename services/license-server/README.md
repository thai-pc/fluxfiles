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
| POST | `/webhook/polar` | Standard Webhooks sig | Issue on `order.paid` |
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
