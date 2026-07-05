# FluxFiles for WordPress

WordPress plugin that embeds [FluxFiles](https://github.com/thai-pc/fluxfiles) — a
multi-storage file manager (Local, AWS S3, Cloudflare R2) — directly in your site
via a shortcode, an editor media button, and a JWT-bridged REST proxy.

Unlike the JS/framework wrappers, this plugin **bundles the FluxFiles core**, so it
runs entirely inside your WordPress install — no separate backend to host.

## Requirements

- WordPress 6.0+
- PHP 8.1+ (Intervention Image v3 and the rest of `fluxfiles/fluxfiles`)
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
| `mode`     | `picker`  | `picker` (select files) or `manager`          |
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

Picks also **sync alt text + caption** onto the attachment, and the Gutenberg block can
**set the featured image** from a FluxFiles file.

### Native picker integration (experimental)

Enable **Native picker** (Settings → FluxFiles, off by default) to add a *From FluxFiles*
button to WordPress's own media modal — so **Featured Image**, the **core Image block**,
the **Customizer** and third-party pickers can pull from FluxFiles too (each pick becomes
an offloaded attachment). It's opt-in because extending `wp.media` across every context is
fragile; the FluxFiles button + Gutenberg block work without it. Toggle via the setting or
the `fluxfiles_replace_picker` filter. Test on staging first.

> **Not a 100% drop-in of the native library.** Responsive `srcset` isn't auto-generated
> for offloaded images (FluxFiles' on-demand `/img` resizer is token-gated by design), so
> WP serves the full-size image with correct dimensions. Everything else — Media Library
> visibility, insert, alt/caption, featured image, blocks — behaves like a native
> attachment.

## REST API

A proxy is exposed at `/wp-json/fluxfiles/v1/api/fm/*` — `list`, `upload`,
`delete`, `rename`, `move`, `copy`, `mkdir`, `cross-copy`, `cross-move`, `search`,
`metadata`, … . The current WordPress user's identity is bridged into a short-lived
FluxFiles JWT server-side; the signing secret never reaches the browser.

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

## SFTP disk & SSH terminal

The WordPress plugin runs FluxFiles **through the WP REST API (proxy mode)**, which
serves **Local / S3 / R2** storage. Managing a **remote VPS** over SFTP — the SFTP
disk, `chmod`, and the SSH **terminal** — streams files through the app and holds a
live SSH connection, so it's **not available through the WordPress plugin**.

For those, run the **standalone FluxFiles server** (or the Docker image) — see the
[core README → SFTP disk](https://github.com/thai-pc/fluxfiles#sftp-disk-vps--shared-hosting)
and [SSH terminal](https://github.com/thai-pc/fluxfiles#ssh-terminal-sftp-disks).
(The **burn-in watermark** does work in WordPress.)

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
