# FluxFiles for WordPress

WordPress plugin that embeds [FluxFiles](https://github.com/thai-pc/fluxfiles) — a
multi-storage file manager (Local, AWS S3, Cloudflare R2) — directly in your site
via a shortcode, an editor media button, and a JWT-bridged REST proxy.

Unlike the JS/framework wrappers, this plugin **bundles the FluxFiles core**, so it
runs entirely inside your WordPress install — no separate backend to host.

## Requirements

- WordPress 6.0+
- PHP 8.1+ (matches `fluxfiles/fluxfiles`; 8.1–8.5 tested)
- A build that includes the Composer `vendor/` directory (release ZIP, or run
  `composer install --no-dev --optimize-autoloader` in the plugin dir before zipping)

## Installation

1. Download a release ZIP that includes `vendor/` (WordPress.org or GitHub Releases).
2. **Plugins → Add New → Upload Plugin**, choose the ZIP, install, and activate —
   or unzip into `wp-content/plugins/fluxfiles/` and activate from **Plugins**.
3. Go to **Settings → FluxFiles** to set your JWT secret and storage options.
4. Drop the `[fluxfiles]` shortcode into any page or post.

> **Developers (monorepo):** run `composer install -d packages/core` next to
> `packages/wordpress`, or `composer install --no-dev` inside the plugin dir.

## Usage

### Shortcode

```text
[fluxfiles]
[fluxfiles disk="s3" mode="picker" height="600px" multiple="1"]
```

| Attribute  | Default   | Description                                   |
|------------|-----------|-----------------------------------------------|
| `disk`     | `local`   | Storage disk to open                          |
| `mode`     | `picker`  | `picker` (select files) or `browser` (free browse) |
| `width`    | `100%`    | Iframe width                                  |
| `height`   | `600px`   | Iframe height                                 |
| `multiple` | `0`       | `1` to allow selecting multiple files         |

### Editor media button + Gutenberg block

The plugin adds a **FluxFiles** button to the classic editor and a **FluxFiles Image**
block to the block editor. Both open the picker and insert the chosen file(s).

### Media offload (3-in-1 media manager)

With **Media offload** enabled (Settings → FluxFiles, on by default), a picked file is
registered as a real **WordPress attachment** whose URL points at your FluxFiles storage
(S3 / R2 / SFTP / local) — the file is *not* copied into `wp-content/uploads`.
`wp_get_attachment_url()` / `wp_get_attachment_image_src()` are filtered to serve from
your bucket, so the file shows in the **Media Library** and works in posts, themes and
blocks exactly like a native attachment — but offloaded to your storage.

This makes one plugin do what usually takes three — **folders** (the FluxFiles UI) +
**cloud storage** (S3/R2/SFTP) + **offload/URL-rewrite** — replacing FileBird +
WP Media Folder + WP Offload Media. Disable it with the `fluxfiles_enable_offload`
filter or the settings checkbox. The attachment endpoint (`POST
/wp-json/fluxfiles/v1/api/fm/attach`) requires the `upload_files` capability.

The endpoint takes only the picked file's `key` + `disk` (plus display hints:
width/height/alt/caption). The key is resolved through the **requesting user's own
FluxFiles token** — disk allow-list, read permission, tenant prefix, system/hidden
paths, existence and `owner_only` all apply — and the attachment URL (and srcset
variants) are derived server-side from the disk config; a `url` in the body is
ignored. Only disks with a **stable public URL** can be attached (public S3/R2 or
a non-private local disk); private buckets, gated local storage and SFTP return
422, and the picker falls back to inserting the plain URL. Re-picking a file that
already has an attachment only refreshes its alt text if the user may edit that
attachment.

Picks also **sync alt text + caption** onto the attachment, and the Gutenberg block can
**set the featured image** from a FluxFiles file.

### Native picker integration (experimental)

Enable **Native picker** (Settings → FluxFiles, off by default) to add a *From FluxFiles*
button to WordPress's own media modal — so **Featured Image**, the **core Image block**,
the **Customizer** and third-party pickers can pull from FluxFiles too (each pick becomes
an offloaded attachment). It's opt-in because extending `wp.media` across every context is
fragile; the FluxFiles button + Gutenberg block work without it. Toggle via the setting or
the `fluxfiles_replace_picker` filter. Test on staging first.

**Responsive srcset.** Offloaded images get a real `srcset` built from FluxFiles'
upload-time WebP variants (thumb 150w / medium 768w / large 1920w + the original), so a
`<img>` serves the right size per viewport. Images without stored variants fall back to
the full-size URL with correct dimensions.

**Featured image** can be set from the Gutenberg block, and — with the native-picker
integration on — from the classic-editor featured-image panel and everywhere else the
`wp.media` modal appears.

> **One constraint:** offload needs a disk with **stable public URLs** — a public S3/R2
> bucket (or CDN) or a public local disk. Private buckets, SFTP and gated local serve
> expiring URLs, so they aren't supported for offload (migration rejects them). Everything
> else — Media Library visibility, insert, alt/caption, featured image, srcset, blocks —
> behaves like a native attachment.

### Migrate an existing site + housekeeping (WP-CLI)

```bash
# Move existing local Media Library attachments into a FluxFiles disk (offload them).
# Target must be a public S3/R2 or public local disk.
wp fluxfiles migrate --disk=s3 --path=wp-media --dry-run   # preview
wp fluxfiles migrate --disk=s3 --path=wp-media             # apply
wp fluxfiles migrate --disk=s3 --limit=200 --delete-local  # batch + reclaim local disk

# Verify FluxFiles-backed attachments still point at existing files; remove dead ones.
wp fluxfiles verify            # report
wp fluxfiles verify --delete   # remove attachments whose file is gone
```

**Delete sync.** Deleting an attachment leaves the file safe in your bucket by default;
enable **Delete from storage** (Settings → FluxFiles, or the `fluxfiles_delete_storage`
filter) to also remove it. The storage delete runs as the WordPress user deleting
the attachment, through their own FluxFiles token: it needs the `delete`
permission and honours the tenant prefix, `owner_only` and legal holds, and it is
written to the audit log (`delete`, detail `wp_attachment_delete post=<id>`; a
refusal is logged as `access_denied`). `_fluxfiles/`, `_variants/` and hidden
(dot) paths are never deleted this way, and with no logged-in user (cron, WP-CLI)
the file is kept. The reverse direction (a file deleted in FluxFiles) is handled
on demand by `wp fluxfiles verify`.

## Who gets a token

The shortcode, block editor, media button and media-library integration only
mint a token for users holding the REST routes' capability (`upload_files` by
default — Author and up). The bundle then follows the user's capabilities:
`manage_options` / `delete_others_posts` (Administrator, Editor) → admin;
`upload_files` (Author) → editor (read/write, owner-only); anyone else → viewer.
To let Subscribers browse via `[fluxfiles]`, lower the bar deliberately:

```php
add_filter('fluxfiles_required_capability', fn () => 'read');
```

**Upload types follow WordPress.** For a user without `unfiltered_html` (everyone
but Administrators/Editors on a single site, everyone but Super Admins on
multisite) the minted `allowed_ext` is the intersection of the requested list (or
"everything" when none was set) with the extensions `get_allowed_mime_types()`
grants that user, and never includes `html`/`htm`/`xhtml`/`svg`/`svgz`/`xml`/`js`/
`mjs`/`php*`/`phtml` — whatever the override, the `fluxfiles_token_overrides` filter
or the raw `claims` map says. Those files would otherwise render as active content
in the same origin as wp-admin. An empty result means *nothing* may be uploaded.

An unknown `role` or `edition` override (e.g. a typo like `'veiwer'`) throws
`InvalidArgumentException` instead of silently minting the site-wide default perms.

## Storage protection (Apache / nginx)

Files live in `wp-content/fluxfiles/uploads/`, which your web server serves
directly. On Apache/LiteSpeed the plugin writes `.htaccess` rules there
automatically (deny `_fluxfiles/` bookkeeping and dotfiles, never execute
uploads, serve HTML/SVG as inert downloads). **nginx ignores `.htaccess`** — add
the equivalent yourself:

```nginx
location ^~ /wp-content/fluxfiles/ {
    location ^~ /wp-content/fluxfiles/uploads/ {
        allow all;   # the outer `deny all` is inherited otherwise
        add_header X-Content-Type-Options "nosniff" always;
        add_header Content-Security-Policy "sandbox" always;
        location ~* /_fluxfiles/ { deny all; }
        location ~ /\. { deny all; }
        location ~* \.(php[0-9]?|pht|phtml?|phar|phps|pgif|shtml?|stm|cgi|pl|py)$ { deny all; }
        location ~* \.(html?|xhtml|xml|svg)$ {
            add_header X-Content-Type-Options "nosniff" always;
            add_header Content-Security-Policy "sandbox" always;
            add_header Content-Disposition "attachment" always;
        }
    }
    deny all;
}
```

…or enable **private local storage** in the settings, which stops serving the
directory statically at all (files stream through the signed REST route).

On nginx the plugins screen and Settings → FluxFiles show an admin notice with
these rules (rooted at your real content path) while the local disk is public.
Once the rules are in place, hide it with
`add_filter('fluxfiles_nginx_storage_notice', '__return_false');`.

## REST API

A proxy is exposed at `/wp-json/fluxfiles/v1/api/fm/*` — `list`, `upload`,
`delete`, `rename`, `move`, `copy`, `mkdir`, `cross-copy`, `cross-move`, `search`,
`metadata`, … . The current WordPress user's identity is bridged into a short-lived
FluxFiles JWT server-side; the signing secret never reaches the browser.

- **Auth.** The iframe calls these routes with `Authorization: Bearer <jwt>` and no
  nonce, which WordPress already treats as a cookie-less request. A Bearer call that
  also carries a *stale* REST nonce (e.g. through `wp.apiFetch`) is let through to
  the JWT check **only** for routes under `/fluxfiles/v1`, and is then run as user 0
  — never as the logged-in cookie user. No other auth error is touched.
- **Rate limits** mirror core: `import-url` has its own bucket (`import_rate_limit`
  claim, else the `fluxfiles_import_rate_limit` filter, default 10/min, checked
  together with the `write` permission before any outbound fetch); `/duplicates`
  and `/usage?refresh=true` share a 2/min bucket (a plain `/usage` is served from a
  cache, `usage_cache_ttl`, default 900 s); `presign` and `c2pa` verify count as
  writes.
- **Audit/webhooks** record tenant-scoped keys, as core does.
- **`/stream` and `/img`** are core's shared `MediaEndpoint`, so they behave exactly
  like standalone: Range requests, the nginx `FLUXFILES_XACCEL` fast path, AVIF/WebP
  negotiation and the preview **watermark overlay**. Mint a token with
  `watermark_enabled` (plus `watermark_type`/`_text`/`_logo_path`/`_position`/
  `_opacity`/`_font_size`) and `/img` composites the mark; a preview-only token is
  never handed the clean original. Their per-sub rate limits default to core's
  (stream 300/min, img 120/min; `FLUXFILES_STREAM_RATE_LIMIT` / `FLUXFILES_IMG_RATE_LIMIT`,
  or the `fluxfiles_rate_limit_stream` / `fluxfiles_rate_limit_img` filters; `0` disables).
- **The UI page** (`/public/index.html`) sends `Referrer-Policy: no-referrer` and
  `Content-Security-Policy: frame-ancestors 'self' <site origins>`. A headless
  front-end on another origin widens it with
  `add_filter('fluxfiles_frame_ancestors', fn ($v) => $v . ' https://app.example.com');`
  (`''` drops the header).

## Updates

Set `FLUXFILES_UPDATE_URL` (or the `fluxfiles_update_url` option/filter) to an
update server and wp-admin offers new versions. The URL must be **https** — plain
http is accepted for `localhost`/`127.0.0.1`/`::1` only — because the licence key
travels as a Bearer header; otherwise update checks are disabled and an admin
notice says why. The server's manifest is signature-checked, and the downloaded
zip is re-hashed against the manifest's signed `sha256` before WordPress installs
it: a mismatch, a missing checksum, or a package URL the manifest did not sign
fails the update.

## Enable Import from URL

Import-from-URL is **off by default**. The built-in shortcode, media button and
REST proxy mint tokens through `FluxFilesPlugin::tokenForCurrentUser()`, so the
supported way to turn it on (or set any per-tenant claim) is the
`fluxfiles_token_overrides` filter — add this to your theme or a small plugin:

```php
add_filter('fluxfiles_token_overrides', function (array $overrides, int $userId) {
    $overrides['allow_url_import']     = true;            // required — enables it
    $overrides['max_import_mb']        = 20;             // optional — cap per import (MB)
    $overrides['import_url_allowlist'] = ['*.unsplash.com']; // optional — restrict hosts
    return $overrides;
}, 10, 2);
```

Once enabled, the proxy accepts `POST /wp-json/fluxfiles/v1/api/fm/import-url`
(`{ "url": "…", "path": "…" }`) — SSRF-guarded, sharing the quota/dedup/variants
pipeline. Server-wide defaults come from `FLUXFILES_IMPORT_*` env vars on the
core service.

## Indexing an existing upload directory

Listing/preview of pre-existing files works out of the box, but **search** relies on
the FluxFiles index files (written when content is created through the API). To make
an existing tree searchable, use the bundled WP-CLI command:

```bash
# Dry run first — report what would be indexed, no writes
wp fluxfiles seed --disk=local --dry-run

# Apply (or target a sub-tree / force re-index)
wp fluxfiles seed --disk=local
wp fluxfiles seed --disk=local --path=user_1
wp fluxfiles seed --disk=local --overwrite

# Existing S3/R2 bucket
wp fluxfiles seed --disk=s3
```

It walks the disk recursively (skipping `_fluxfiles/`, `_variants/`, `*.meta.json`),
creates metadata per file (title from the filename) and tracks folders in
`_fluxfiles/dirs.json`. No WP-CLI? Re-upload through the UI — new uploads register
metadata automatically.

## SFTP disk & PTY terminal

The WordPress plugin runs FluxFiles **through the WP REST API (proxy mode)**, whose
admin settings page only exposes **Local / S3 / R2** storage — there's no settings
field (or filter) to register an SFTP disk. Managing a **remote VPS** over SFTP is
therefore not available through the WordPress plugin in practice; run the
**standalone FluxFiles server** (or the Docker image) for that — see the
[core README → SFTP disk](https://github.com/thai-pc/fluxfiles#sftp-disk-vps--shared-hosting).

FluxFiles no longer ships an SSH command-runner terminal or one-click Git deploy. If you
run your own [ttyd / gotty / wetty](https://github.com/thai-pc/fluxfiles/blob/master/docs/reference/CONFIG.md),
pass its URL as the `terminal_pty_url` token override and the UI embeds it.
(The **burn-in watermark** works in WordPress too.)

## Features

- Multi-storage — Local disk, AWS S3, Cloudflare R2 (cursor-based pagination)
- Image optimization — automatic WebP variants (thumb / medium / large)
- AI tagging — Claude / OpenAI vision API
- Full-text search across file names + metadata; folder search
- 16 languages with RTL support; dark mode with auto-detection

## License

MIT — see [LICENSE](LICENSE) for details.

## Links

- Main repository: `https://github.com/thai-pc/fluxfiles`
- Documentation: `https://github.com/thai-pc/fluxfiles#wordpress`
- Issues: `https://github.com/thai-pc/fluxfiles/issues`
