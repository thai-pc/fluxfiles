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
truth for how many there are — **thirteen** as of core-v0.2.100 (`share`, `intake`,
`versioning`, `webhooks`, `ai`, `ocr`, `virus`, `backup`, `c2pa`, `audit-export`,
`sso`, `dlp`, `legal-hold`; recount from that file rather than trusting this
number, since it has drifted before — it was nine before `audit-export`/`sso`
shipped 2026-08-29, then eleven before `dlp`/`legal-hold` shipped 2026-09-06).
`optimize` is no longer a module (free/core); don't publish a zip for it.

Build the artifacts:

```bash
php scripts/pack-modules.php
# → build/modules/<module>-<version>.zip  ×13 (one zip per ModuleRegistry id)
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

**The WordPress plugin has its own catalogue entry.** A `wordpress-v*` tag runs
`.github/workflows/wordpress-release.yml`, which attaches the plugin ZIP plus a
`wordpress-catalogue.json` (version, zip, sha256, `requires_php`) to the GitHub
release. Merge that entry into `build/modules/catalogue.json` on the update server
(`pack-modules.php` preserves it) — without it the plugin's update check answers
`404 unknown module`, and without `requires_php` WordPress can't hold an update back
from a site whose PHP is too old (the floor is 8.1).

---

## 2. Deploy the update server

`docs/update-server.example.php` is a reference implementation. It verifies the
licence signature, asks the licence server whether the key is still active (so a
refund/revoke blocks updates), then returns a **signed** manifest pointing at the zip.

```bash
FLUXFILES_RELEASE_PRIVATE_KEY=…      # base64 Ed25519 secret; the public half is
                                     # embedded in UpdateClient as kid 'r1'
FLUXFILES_CDN_BASE=https://cdn.example.com/modules
FLUXFILES_CATALOGUE=/path/to/catalogue.json
FLUXFILES_LICENSE_STATUS_URL=https://licenses.example.com
FLUXFILES_UPDATE_STATUS_TOKEN=<distinct machine-to-machine secret>
```

Keep the release signing key **offline and separate from the licence signing key**. They
sign different things: one says "this build is ours", the other says "this customer
bought it".

Point `FLUXFILES_UPDATE_URL` at it (default `https://fluxfiles-updates.thai-pc.com`).

### Core-version compatibility

**Core 1.1.0 and later need current modules.** Core no longer carries its own copy of the code that moved into the modules, so it needs `share` >= 1.4.0, `intake` >= 1.5.0, `legal-hold` >= 1.2.0 and `audit-export` >= 1.1.0 (the Laravel adapter needs `fluxfiles/laravel` >= 1.0.3). Composer enforces this through `conflict` entries in core's `composer.json`; a site that installed modules without Composer gets `501 module_update_required` on the affected routes until it runs `fluxfiles update <module>`. Update the modules before (or together with) core.

A module declares the core it needs in its `composer.json`
(`require["fluxfiles/fluxfiles"]`). Composer enforces that on `composer require`, but
`fluxfiles update <module>` does not go through Composer, so the channel enforces it too:

- `scripts/pack-modules.php` copies that constraint from the **tagged** `composer.json` into
  each catalogue entry as `requires_core`, and keeps earlier builds under `releases` (newest
  first) so a site on an older core can still be offered the newest build it can host.
- The server signs `requires_core` into the manifest. The client sends its core version as
  `?core_version=` (from `Composer\InstalledVersions`, or the `FLUXFILES_CORE_VERSION`
  env var, which git / release-zip / Docker installs must set since Composer reports `dev-*` there) and `UpdateClient::assertCoreCompatible()` refuses a release the running core
  does not satisfy, telling the operator to update core first.
- A client that cannot determine its core (a git checkout, `dev-*`) is refused with a
  warning; `fluxfiles update <module> --force` proceeds, but since the core is unreported the
  server hands it the 1.0.3-compatible build, not the newest. Set `FLUXFILES_CORE_VERSION`
  to get the right one. `--force` never overrides a known mismatch. After download, the
  zip's own `composer.json` constraint is checked too.
- **Clients that predate this (core <= 1.0.5, and every pre-1.0 `0.2.x` core) send no
  `core_version`.** The server treats them as core `1.0.3` (so a `0.2.x` client is also
  assumed to be 1.0.3 and can be offered a build it cannot host) and never offers a release whose floor is above `^1.0.3`; they get
  the newest older build, or `409` when none is compatible. Only a client that reports its
  version can be offered a release with a higher floor.
- The WordPress plugin updater only updates the plugin itself (no `requires_core`), so it is
  unchanged.

Verify without a licence:

```bash
curl -H 'Authorization: Bearer invalid' "https://updates.example.com/update/share?current=0.0.0"
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

FLUXFILES_LANDING_ORIGIN=https://fluxfiles.thai-pc.com   # the ONLY origin /claim answers
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
> LICENSING-PLAN.md` (a local, gitignored note) §C is the canonical source for what the
> prices *should* be — check new figures against it before changing either `CATALOGUE`
> or `Pricing.astro`.

`enterprise` is deliberately not created: it is a custom conversation an instant-buy
button would undercut. The lifetime deal is prepared but off: the default run creates
exactly the six products above.

### Switching on the lifetime deal (limited offer)

Studio Lifetime $399, Pro Lifetime $179 (one-time; perpetual use, 12 months of
updates), plus one-time updates renewals: Pro $29, Studio $79. How a renewal works and
what happens to one with no licence behind it: `services/license-server/README.md`.

0. **Deploy the licence server first.** An older deploy lacks `pro-lifetime`/`*-renewal`
   (503 for those products) and would mail the old text. Also check any
   `FLUXFILES_LICENSE_PLANS` override: it wins over the repo's plans.
1. `php services/license-server/polar-setup.php --dry-run --production --with-lifetime`
   (look first), then the same without `--dry-run`. `--with-lifetime` creates the two
   lifetime products and the two renewals; `--with-renewals` creates only the renewals
   (safe to re-run any time). Existing products are skipped, and existing lifetime or
   renewal products, archived ones too, are always mapped by name, never recreated.
   An archived lifetime product is reported: unarchive it in Polar to re-open the deal.
2. Paste the printed `FLUXFILES_POLAR_PLAN_MAP` into the licence server's environment
   (order: redeploy, polar-setup, paste, restart). A product missing from the map makes
   the webhook answer 503 and Polar retries.
3. Set the products' success URL (`…?checkout_id={CHECKOUT_ID}`) like the others, then
   test one sandbox purchase of each (`--with-lifetime` without `--production`), plus a
   renewal bought with the same Polar account. Renewal keys arrive by email only.
4. Landing: add the buy links / banner (`PUBLIC_POLAR_*` env vars for the four products)
   and say renewals must use the purchase email and account. This repo does not touch
   the landing.
5. Ending the deal: archive the two LIFETIME products in Polar. Keep the renewal
   products on sale (past buyers renew after the deal ends) and keep them in the plan map;
   `polar-setup.php` maps archived ones by name. Issued keys keep working.
6. An unmatched renewal (wrong email/account) is ACKed, the buyer is mailed, and
   `RENEWAL WITHOUT LICENCE` is logged. Fix with
   `POST /issue {"plan":"pro-renewal","email":"<the LIFETIME purchase email>"}`, or refund.
   A refund or revoke of a licence also cancels its renewals; those whose own order was
   not refunded are logged (masked email) and mailed to `FLUXFILES_OPS_EMAIL`: reissue
   the lost year with the same `POST /issue`. `/revoke` with `status: active` restores the
   chain shape but does not revive cancelled renewals; un-revoke each one.
   Optional env: `FLUXFILES_OPS_EMAIL` (also notified), `FLUXFILES_RENEWAL_URL` (in reminders).

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

Product screenshots for the landing come from `scripts/landing-screenshots/` (see its
README) — regenerate them after UI changes.

---

## 6b. Licence key refresh on customer servers

Customers on monthly plans run `fluxfiles license refresh` from cron. Two things decide
whether it works: the user and the path. Run it as the web user
(`17 4 * * * sudo -u www-data php vendor/bin/fluxfiles license refresh`, or
`docker exec -u www-data ...`), because the overlay file must be readable by PHP-FPM; a
root-owned 0600 file is ignored at runtime and the paid modules switch off at
`expires + grace`. And cron and FPM must see the same `FLUXFILES_STORAGE_PATH` /
`FLUXFILES_LICENSE_FILE` (outside `vendor/` on Composer and Laravel installs). The client's
timeout is 5 s; `flock()` on NFS is unreliable, so keep the file on a local disk.
`fluxfiles about` shows `Licence source` and warns when the file looks unreadable to FPM.

## 7. Check what a server is actually running

`fluxfiles about` (`--json` for scripts) prints the exact core version and where it
was read from (`env` = `FLUXFILES_CORE_VERSION`, `composer`, or `unknown`; this is the
value `fluxfiles update` sends), PHP, the storage backend, each configured disk by
driver only, every paid module (installed / version / licensed / enabled / the core it
needs and whether this core satisfies it), virus and DLP on/off, GD/Imagick, ffmpeg,
Ghostscript (and whether it meets the 10.05.0 floor), Tesseract, ClamAV reachability and
how many SSRF allow-hosts are set. It never prints a licence key, credential, endpoint,
token or path. The same report appears in WordPress under Tools > Site Health > Info
(disks, licence and SSRF rows are marked private so they stay out of "copy to
clipboard") and in `php artisan about` on Laravel versions that have it. Ask a customer
for this output first when debugging a module that "does nothing".

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
  first core release that actually *calls* it — and, since 2026-10-23, PHP `^8.1.0`
  like the core. This cannot be CI-guarded — the packages are gitignored, so CI cannot
  see them. Seven of nine were wrong once (back when there were only nine modules,
  before `audit-export`/`sso` shipped 2026-08-29 brought the total to eleven, and
  `dlp`/`legal-hold` shipped 2026-09-06 brought it to thirteen).
- **WordPress cannot run the CLI installer.** Shared hosting has no shell; those
  customers unpack the module zip by hand — see `ACTIVATE.md`.

---

## Env vars used elsewhere in this doc

This runbook is the canonical home for the *selling* env vars (Polar, licence server,
update server) shown above. Self-hosting **FluxFiles itself** (web server config,
directory permissions, upload-size limits — `client_max_body_size`,
`upload_max_filesize`/`post_max_size`, etc.) is a different job with no overlap
today; see [`DEPLOYMENT.md`](DEPLOYMENT.md) for that instead of duplicating it here.
