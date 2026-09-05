# Operations runbook — going live

For the person **selling** FluxFiles. Customers do not need any of this; they need
[`ACTIVATE.md`](ACTIVATE.md).

Everything below is infrastructure. The code is written and tested — an end-to-end
sandbox purchase has been through the whole chain — but nothing is deployed, so today
a real customer cannot buy.

Order matters: each step depends on the one before.

---

## 0. Rotate anything that has leaked

Do this first and do not skip it because the store is not live yet. A Polar token can
create products and **change prices**; a leaked one is a live problem the moment the
store opens.

1. Polar → **Settings → Developers** → revoke the old token.
2. Create a new one with `products:read` + `products:write`.
3. Put it in the repo-root `.env` (gitignored):

```bash
POLAR_TOKEN=polar_oat_…              # production organisation
POLAR_TOKEN_SANDBOX=polar_oat_…      # sandbox is a SEPARATE org with its own token
```

`polar-setup.php` reads these itself, so the token never has to be typed on a command
line and never lands in shell history. It picks the variable that matches the
environment, so a sandbox token cannot accidentally reach production.

**Never** paste a token into a chat, an issue or a commit message.

---

## 1. Host the module artifacts

The paid modules are tagged and pushed, but a tag is a version, not a download.
`ModuleRegistry::$map` (`packages/core/api/ModuleRegistry.php`) is the source of
truth for how many there are — **eleven** as of this writing (`share`, `intake`,
`versioning`, `webhooks`, `ai`, `ocr`, `virus`, `backup`, `c2pa`, `audit-export`,
`sso`; recount from that file rather than trusting this number, since it has
drifted before — it was nine before `audit-export`/`sso` shipped 2026-08-29).

Build the artifacts:

```bash
php scripts/pack-modules.php
# → build/modules/<module>-<version>.zip  ×11 (one zip per ModuleRegistry id)
# → build/modules/catalogue.json
```

It builds each zip from that module's **git tag** (never the working tree), refuses a
build it cannot reproduce, and rejects a layout `UpdateClient::install()` would
mis-extract.

Upload the zips somewhere they can be fetched over HTTPS. Each module is small,
source-only PHP, so the total download is tiny — this needs no CDN — a private
GitHub release, an R2/S3 bucket, or the same box as the update server is fine.
Integrity is anchored by a signed sha256, so the host does not have to be trusted,
only reachable.

> Serve **exactly** the bytes `pack-modules.php` hashed. `UpdateClient` re-hashes the
> download and refuses a mismatch, so a rebuilt-but-not-rehashed zip breaks every
> install at once.

---

## 2. Deploy the update server

`docs/update-server.example.php` is a reference implementation — one file, no database.
It verifies a licence offline and returns a **signed** manifest pointing at the zip.

```bash
FLUXFILES_RELEASE_PRIVATE_KEY=…      # base64 Ed25519 secret; the public half is
                                     # embedded in UpdateClient as kid 'r1'
FLUXFILES_CDN_BASE=https://cdn.example.com/modules
FLUXFILES_CATALOGUE=/path/to/catalogue.json
```

Keep the release signing key **offline and separate from the licence signing key**. They
sign different things: one says "this build is ours", the other says "this customer
bought it".

Point `FLUXFILES_UPDATE_URL` at it (default `https://updates.fluxfiles.dev`).

Verify without a licence:

```bash
curl "https://updates.example.com/update/share?license=&current=0.0.0"
# → 402. A 200 here would mean the licence check is not running.
```

---

## 3. Deploy the licence server

`services/license-server/` — plain PHP plus one SQLite file. It is the **stateful
back-office**, deliberately outside the stateless core.

```bash
FLUXFILES_LICENSE_PRIVATE_KEY_FILE=/secure/licence-signing-key.key
FLUXFILES_LICENSE_DB=/var/lib/fluxfiles/licenses.sqlite
FLUXFILES_LICENSE_ADMIN_TOKEN=$(openssl rand -hex 24)
FLUXFILES_POLAR_WEBHOOK_SECRET=whsec_…      # from step 4
FLUXFILES_POLAR_PLAN_MAP='{"<product_id>":"pro", …}'   # from step 5

FLUXFILES_MAIL_TRANSPORT=resend             # default is 'log' — it does NOT send
FLUXFILES_MAIL_FROM=licenses@your-domain.com
FLUXFILES_MAIL_FROM_NAME=FluxFiles
FLUXFILES_MAIL_REPLY_TO=support@your-domain.com   # optional
FLUXFILES_RESEND_API_KEY=re_…               # resend.com → API Keys

# or, transport=smtp instead:
# FLUXFILES_SMTP_HOST=…  FLUXFILES_SMTP_PORT=587
# FLUXFILES_SMTP_USER=…  FLUXFILES_SMTP_PASS=…

FLUXFILES_LANDING_ORIGIN=https://fluxfiles.dev   # the ONLY origin /claim answers
```

Two things worth knowing before the first sale:

- **The default mail transport is `log`.** It writes the message to the error log
  instead of sending, so a half-configured deploy is obvious rather than silently
  swallowing every licence email. Set `smtp` when you mean it.
- **A mail failure does not fail the webhook.** Polar disables an endpoint after ten
  consecutive non-2xx replies, and a mail outage fails every order at once — so the
  licence is stored, `mailed_at` stays null, and `POST /redeliver` (admin-authed) sends
  the backlog once mail works. Check for a backlog after any mail incident.

Back up the SQLite file. It is the only record of who bought what.

```bash
curl https://licences.example.com/health      # → {"ok":true}
```

---

## 4. Point Polar's webhook at it

Polar → **Settings → Webhooks → Add Endpoint**:

| Field | Value |
|---|---|
| URL | `https://licences.example.com/webhook/polar` |
| Format | Raw (JSON) |
| Events | **`order.paid`** only |
| Secret | generate one, then set `FLUXFILES_POLAR_WEBHOOK_SECRET` |

`order.paid` is the one that means money moved. `order.created` fires first with
`status: pending`, and issuing there hands a licence to a payment that can still fail.
The server ignores every other event with a 200 — a non-2xx would make Polar retry
something it will never act on, and ten of those disable the endpoint.

**Test it before opening the store.** The Polar CLI tunnels real deliveries to a local
server, so the whole chain can be exercised without deploying:

```bash
polar listen http://127.0.0.1:9200/webhook/polar   # prints a secret; use that one
```

---

## 5. Create the production products

```bash
php services/license-server/polar-setup.php --dry-run --production   # look first
php services/license-server/polar-setup.php --production
```

It reads `Plans.php` so the products cannot drift from what the licence server will
issue, skips anything that already exists, and prints the `FLUXFILES_POLAR_PLAN_MAP` to
paste into step 3.

> **Prices live in two places** and both must agree: the `CATALOGUE` table in
> `polar-setup.php`, and the landing's `Pricing.astro`. A static marketing page cannot
> ask the API what something costs. They have already been out of step once — the page
> advertised Studio at $299/yr while the product was created at $249. `docs/
> LICENSING-PLAN.md` §C is the canonical source for what the prices *should* be —
> check new figures against it before changing either `CATALOGUE` or `Pricing.astro`.

`enterprise` and `lifetime` are deliberately not created: Enterprise is a custom
conversation an instant-buy button would undercut, and lifetime is worth offering only
once recurring revenue exists.

`support` and `support-monthly` are created like any other plan, but they unlock no
module (`Plans.php` gives them `modules: []`) — the product is a support relationship,
sellable standalone to a free-core self-hoster who never buys Pro/Studio/Enterprise.

---

## 6. Switch the landing to production

In `fluxfiles-landing`, generate production checkout links and set:

```bash
PUBLIC_POLAR_PRO_YEARLY=…      PUBLIC_POLAR_PRO_MONTHLY=…
PUBLIC_POLAR_STUDIO_YEARLY=…   PUBLIC_POLAR_STUDIO_MONTHLY=…
PUBLIC_POLAR_SUPPORT_YEARLY=…
```

A missing variable degrades to the existing "coming soon" button rather than a dead
link, so a half-filled `.env` is visible instead of broken.

Set the checkout success URL to your success page with `?checkout_id={CHECKOUT_ID}`.
Polar substitutes it; the page exchanges it for the key.

---

## Before announcing

Buy something. Not a smoke test — a real purchase on the real store, refunded
afterwards.

- [ ] Checkout completes and redirects to the success page
- [ ] The success page shows the key (it polls: the redirect usually beats the webhook)
- [ ] The email arrives with the key and the `FLUXFILES_LICENSE_KEY=` line
- [ ] `GET /api/fm/license` on a real install reports the right edition
- [ ] `fluxfiles update share` installs, and the Share button appears
- [ ] Polar's webhook log shows one delivery, 200, no retries

The last one matters most: a 200 with retries means something is failing in a way the
buyer cannot see.

---

## Known limits, so they are not surprises

- **`/claim` stops answering after an hour.** A success URL survives in browser history
  and referrer logs; after the window, email is the way, or look it up with the admin
  token.
- **A cancelled subscription is not revoked early.** The webhook handles `order.paid`
  only. With `perpetual` enforcement a key expires on its own term, so a mid-term
  canceller keeps the year they paid for. Revoking sooner needs a
  `subscription.canceled` handler.
- **Module floors are checked by hand.** A module's `composer.json` must require the
  first core release that actually *calls* it. This cannot be CI-guarded — the packages
  are gitignored, so CI cannot see them. Seven of nine were wrong once (back when there
  were only nine modules, before `audit-export`/`sso` shipped 2026-08-29 brought the
  total to eleven).
- **WordPress cannot run the CLI installer.** Shared hosting has no shell; those
  customers unpack the module zip by hand — see `ACTIVATE.md`.

---

## Env vars used elsewhere in this doc

This runbook is the canonical home for the *selling* env vars (Polar, licence server,
update server) shown above. Self-hosting **FluxFiles itself** (web server config,
directory permissions, upload-size limits — `client_max_body_size`,
`upload_max_filesize`/`post_max_size`, etc.) is a different job with no overlap
today; see [`DEPLOYMENT.md`](DEPLOYMENT.md) for that instead of duplicating it here.
