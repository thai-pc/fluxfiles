# Activating a paid FluxFiles licence

You bought Pro, Studio or Enterprise and have a licence key. Two things have to happen
before the buttons appear:

1. **Install the module** — the paid features are separate packages, not part of the
   free core you already have.
2. **Give the key to your server**, so it will load them.

Then mint a token that allows the feature. All three are below, per platform.

> **`composer require fluxfiles/share` will not work.** The paid packages are private,
> so Packagist has nothing to resolve and Composer fails with "package not found". This
> is the most common wrong first step; use `fluxfiles update` instead.

---

## 1. Install the module

### Standalone, Docker or Laravel

```bash
export FLUXFILES_LICENSE_KEY='eyJhbGciOi…'      # or put it in .env, see step 2
php vendor/bin/fluxfiles update share            # --check to look without installing
```

It asks the update server for a **signed** manifest, verifies the signature and the
zip's checksum, and unpacks into `vendor/fluxfiles/share/`. A failed signature or a
mismatched checksum aborts without writing anything.

Module ids: `share`, `intake`, `versioning`, `webhooks`, `ai`, `ocr`, `virus`,
`backup`, `c2pa`, `audit-export`, `sso`, `dlp`, `legal-hold`. Install only the ones your edition includes —
the rest will refuse with `402`. (Optimization used to be a paid module; it is part of
the free core now and needs nothing installed.)

Every module requires PHP 8.1 or newer, like the core.

You do **not** need `composer dump-autoload`: FluxFiles loads installed modules itself
(`packages/core/autoload.php`), precisely because Composer cannot see a directory that
is not in `composer.json`.

### WordPress

The free WordPress plugin ships no paid modules and has no module installer. Paid modules
come with the separate **FluxFiles Pro** add-on plugin, downloaded from the FluxFiles site
after purchase (never from WordPress.org). Install it next to the free plugin, paste the key
under Settings → FluxFiles → Licence (Network Admin on multisite), and the Share and Intake
modules switch on. Until it is installed, paid routes answer `501 module_not_installed` (or
`402 license_required` if a module is present but unlicensed).

---

## 2. Give the key to your server

The key is verified **offline**. Nothing is sent to us at any point, so an air-gapped
server works exactly like a connected one.

| Platform | Where the key goes |
|---|---|
| **Standalone / Docker** | `FLUXFILES_LICENSE_KEY=…` in `.env`, or a real environment variable |
| **Laravel** | the same, in your app's `.env` |
| **WordPress** | The free plugin has no licence setting. Paste the key into the FluxFiles Pro add-on (Settings → FluxFiles → Licence; one network key on multisite), or define `FLUXFILES_LICENSE_KEY` in `wp-config.php` |

Paste the key whole. It is long and contains dots — a line break in the middle is the
usual cause of "not accepted".

Restart PHP-FPM (or your container) after changing an environment variable. WordPress
needs no restart.

Check it took:

```bash
curl -H "Authorization: Bearer <your-jwt>" https://your-host/api/fm/license
# → {"edition":"pro","status":"active","modules":["share","intake"], …}
```

`"edition":"free"` with a key set means the key did not verify.

---

## 3. Allow the feature in the token

FluxFiles is configured by JWT claims, so installing and licensing a module is not
enough — the **token** has to permit it too. This is what lets you sell one tier to some
of your own users and another to the rest.

```php
$token = fluxfiles_token([
    'user'   => 'user-42',
    'perms'  => ['read', 'write'],
    'claims' => [
        'allow_share'  => true,
        'allow_intake' => true,
    ],
]);
```

Or use the tier preset, which sets the claims an edition includes:

```php
$token = fluxfiles_token(['user' => 'user-42', 'edition' => 'pro']);
```

> **Virus and DLP scanning are fail-closed.** The `enterprise` preset turns on
> `allow_virus_scan` and `allow_dlp_scan`. With either claim on, an upload is
> **refused** (not stored unscanned) whenever the scan can't run — module not
> installed, licence missing, or the engine (ClamAV/VirusTotal, Presidio) unreachable.
> Only grant them where the engine is configured.

WordPress mints tokens for you. The free plugin has no licence or module-engine settings;
the FluxFiles Pro add-on provides the licence field and per-role toggles for Share and Intake
(Settings → FluxFiles → Paid features). Without Pro, or for any other claim, turn modules on
per token with the `fluxfiles_token_overrides` filter (individual `allow_*` claims, or
`edition` once an add-on registers editions). Engine settings (`FLUXFILES_AIVISION_*`,
`FLUXFILES_DLP_*`, `FLUXFILES_C2PA_MANIFEST`, `FLUXFILES_VIRUSTOTAL_*`,
`FLUXFILES_CLAMSCAN_TIMEOUT`, `FLUXFILES_OCR_MAX_MEGAPIXELS`, `FLUXFILES_WEBHOOK_ALLOW_INTERNAL`,
`FLUXFILES_LEGAL_HOLD_MAX_ACTIVE`) are set as real environment variables or `wp-config.php`
constants on WordPress; Pro v1 has no settings screen for them.

The claim names are in [`CONFIG.md`](../reference/CONFIG.md) — one row per claim, with defaults.

---

## SSO Bridge setup (standalone UI)

`sso` is different from the other modules: it runs **before** an access JWT exists,
so there is no `allow_sso` claim to mint. It puts an OIDC login screen in front of
the standalone `/public/` UI. It is useful when FluxFiles is the login-facing app;
an embed hosted by your own application should normally keep minting tokens there.
It is not available through the Laravel/WordPress proxies.

1. Install the `sso` module and set `FLUXFILES_LICENSE_KEY` as above.
2. Register `https://files.example.com/api/fm/sso/callback` as the exact redirect
   URI with your OIDC provider. Logins use PKCE (S256), so the client must allow it.
3. Configure the server and restart PHP-FPM/container:

```dotenv
FLUXFILES_SSO_ENABLED=true
FLUXFILES_SSO_OIDC_ISSUER=https://id.example.com/realms/acme
FLUXFILES_SSO_OIDC_CLIENT_ID=fluxfiles
FLUXFILES_SSO_OIDC_CLIENT_SECRET=server-side-secret
FLUXFILES_SSO_OIDC_REDIRECT_URI=https://files.example.com/api/fm/sso/callback
FLUXFILES_SSO_CLAIMS_MAP='{"admins":{"perms":["read","write","delete","audit"],"disks":["local"]},"editors":{"perms":["read","write"],"disks":["local"],"prefix":"team/","claims":{"owner_only":true}}}'
```

`FLUXFILES_SSO_CLAIMS_MAP` maps an IdP group/role to the normal
`fluxfiles_token()` options object. Exactly one entry applies: the **first entry in
the order you wrote the map** whose group the user holds — the IdP's own group order
is ignored — so list the most-privileged group first. Values are used as written:
there is no per-user placeholder, so a shared `prefix` plus `owner_only` (as above) is
the way to give a group one folder in which each user manages only their own uploads.
Set `FLUXFILES_SSO_GROUPS_CLAIM` when the IdP uses a different group path, or provide
`FLUXFILES_SSO_DEFAULT_CLAIMS` only when a deliberate fallback is appropriate.
An empty map/default fails closed with `403 sso_no_mapping`.

The FluxFiles user id (what `owner_only`, the audit log and per-user prefixes key on)
is the IdP's `sub` by default. `FLUXFILES_SSO_USER_CLAIM=email` uses the email address
**only when the IdP marks it verified**, falling back to `sub` otherwise; switching it on
an existing install changes every user's id. OIDC is the only protocol; SAML is not
supported. The complete variable reference, including rate limits and token TTL, is in
[`CONFIG.md`](../reference/CONFIG.md#4-server-env-vars-server-wide).

---

## What each error means

The three checks are separate on purpose, so a failure tells you which one to fix.

| Response | Meaning | Fix |
|---|---|---|
| `501 module_not_installed` | the code is not there | step 1 |
| `501 module_update_required` | the module is installed but older than this core needs (it predates the interface core now calls) | run `fluxfiles update <module>` |
| `402 license_required` | installed, but no valid key | step 2 |
| `402 license_expired` | a subscription key is past its payment-recovery grace | renew to re-enable paid-module endpoints; files and module records are retained |
| `403 allow_<x>_forbidden` | installed and licensed, but this token does not allow it | step 3 |

---

## Updates

```bash
php vendor/bin/fluxfiles update share --check    # is there a newer build?
php vendor/bin/fluxfiles update share            # install it
```

`fluxfiles update` first refreshes your licence key (see below), then sends the newest one.
Add `--no-refresh` to skip that for one run.

### Keeping the key current (subscriptions)

A monthly plan mints a new key on every paid renewal. You do not have to paste it:

```bash
php vendor/bin/fluxfiles license refresh            # fetch + store the newest key of your subscription
php vendor/bin/fluxfiles license refresh --check    # ask, write nothing
php vendor/bin/fluxfiles license refresh --print    # print the new key (never stored), for Vault/k8s secrets
```

```cron
17 4 * * * cd /path/to/project && sudo -u www-data php vendor/bin/fluxfiles license refresh
```

**Run it as the web user.** The file must be readable by PHP-FPM, otherwise the refreshed
key is silently ignored and the paid modules switch off at `expires + grace`. A root or
deploy-user CLI creates the file for its own user; FluxFiles then gives it the storage
directory's group (mode 0640) when it can, and warns (also in `fluxfiles about`) when the
web user still cannot read it. The safe pattern is `sudo -u www-data php vendor/bin/fluxfiles ...`
or, in Docker, `docker exec -u www-data <container> php vendor/bin/fluxfiles ...`; or
pre-create the file 0640 with the FPM group. Cron and PHP-FPM must also see the same
`FLUXFILES_STORAGE_PATH` / `FLUXFILES_LICENSE_FILE`. The lock uses `flock()`, which is
unreliable on some NFS mounts: keep the file on a local disk.

The key you pasted stays in `FLUXFILES_LICENSE_KEY`. The new one is kept in a separate
server-local file (`FLUXFILES_LICENSE_FILE`, default `<storage>/license/license-refresh.json` in a directory FluxFiles creates (0750, deny-all `.htaccess`); the file is 0600, or 0640 with the storage directory's group when the CLI runs as another group than PHP-FPM,
never web-served) that is used only while it was derived from that exact key and is newer.
Paste a different key and the file steps aside until the next refresh; delete the file to
revert. On a Composer or Laravel install the default path is inside `vendor/`, which `composer install`
wipes (the CLI warns): set `FLUXFILES_LICENSE_FILE` to a path outside it, for example
`/var/www/app/storage/app/fluxfiles-license.json`. Exit codes: `0` refreshed or current, `1` usage/config,
`2` network/server (retry), `3` not available for this licence (revoked, unknown, too old,
or a plan change that would shorten the term: paste the key from your e-mail).
Refresh never runs inside a web request, and `FLUXFILES_LICENSE_REFRESH=false` stops the
automatic call in `fluxfiles update` for air-gapped servers.

The free WordPress plugin has no updater of its own (updates come from WordPress.org);
paid-module updates will come with the FluxFiles Pro add-on.

Updates are gated by the support window on your key, not by whether the software runs.
Annual/perpetual terms keep every installed module running indefinitely when the term
ends — renewal only restores new builds and support. Monthly subscriptions have a
seven-day payment-recovery grace; after it ends paid-module endpoints are disabled until
renewal, but FluxFiles never deletes files, module records, versions, audit data, or
shares.

---

## If it still does not work

- `GET /api/fm/license` is the fastest signal: it reports what the server actually
  verified, and needs no module installed.
- The module is `vendor/fluxfiles/<module>/src/…` — a zip unpacked one directory too
  deep (`vendor/fluxfiles/share/share/src/…`) looks identical in a file listing and
  loads nothing.
- Check the module id matches the feature: Share is `share`, Upload Portals is
  `intake`.
