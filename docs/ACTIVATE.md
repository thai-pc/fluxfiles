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
`backup`, `c2pa`, `audit-export`, `sso`. Install only the ones your edition includes —
the rest will refuse with `402`.

You do **not** need `composer dump-autoload`: FluxFiles loads installed modules itself
(`packages/core/autoload.php`), precisely because Composer cannot see a directory that
is not in `composer.json`.

### WordPress

WordPress sites usually have no shell, so unpack it by hand:

1. Download the module zip from your purchase email.
2. Unzip it into `wp-content/plugins/fluxfiles/vendor/fluxfiles/<module>/`, so that
   `…/vendor/fluxfiles/share/src/ShareModule.php` exists.
3. Reload any FluxFiles admin page.

If your host has WP-CLI, `wp plugin install` is not involved — this is a module inside
the plugin, not a plugin of its own.

---

## 2. Give the key to your server

The key is verified **offline**. Nothing is sent to us at any point, so an air-gapped
server works exactly like a connected one.

| Platform | Where the key goes |
|---|---|
| **Standalone / Docker** | `FLUXFILES_LICENSE_KEY=…` in `.env`, or a real environment variable |
| **Laravel** | the same, in your app's `.env` |
| **WordPress** | **Settings → FluxFiles → Licence**. Shared hosting gives you no way to set an environment variable, so the plugin stores it as an option; the screen shows the edition and expiry it verified, and says so plainly if the key was not accepted |

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

WordPress mints tokens for you; set the edition under **Settings → FluxFiles**, or
filter `fluxfiles_token_overrides`.

The claim names are in [`CONFIG.md`](CONFIG.md) — one row per claim, with defaults.

---

## What each error means

The three checks are separate on purpose, so a failure tells you which one to fix.

| Response | Meaning | Fix |
|---|---|---|
| `501 module_not_installed` | the code is not there | step 1 |
| `402 license_required` | installed, but no valid key | step 2 |
| `402 license_expired` | the key is past its term | renew; existing installs keep working, you only lose updates |
| `403 allow_<x>_forbidden` | installed and licensed, but this token does not allow it | step 3 |

---

## Updates

```bash
php vendor/bin/fluxfiles update share --check    # is there a newer build?
php vendor/bin/fluxfiles update share            # install it
```

WordPress checks for plugin updates itself and offers them in **Dashboard → Updates**
like any other plugin.

Updates are gated by the support window on your key, not by whether the software runs.
When a term ends, everything you have keeps working — you simply cannot pull newer
builds until you renew.

---

## If it still does not work

- `GET /api/fm/license` is the fastest signal: it reports what the server actually
  verified, and needs no module installed.
- The module is `vendor/fluxfiles/<module>/src/…` — a zip unpacked one directory too
  deep (`vendor/fluxfiles/share/share/src/…`) looks identical in a file listing and
  loads nothing.
- Check the module id matches the feature: Share is `share`, Upload Portals is
  `intake`.
