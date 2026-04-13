=== FluxFiles ===
Contributors: thaipc
Tags: file-manager, media, s3, r2, upload, cloud-storage
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.22.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Multi-storage file manager with Local/S3/R2 support, image optimization, and full-text search.

== Description ==

FluxFiles is a standalone, embeddable file manager. Drop it into WordPress via this plugin
to get multi-storage support (Local disk, AWS S3, Cloudflare R2), JWT authentication,
image optimization with WebP variants, AI auto-tagging, and full-text search.

**Features:**

* Multi-storage — Local disk, AWS S3, Cloudflare R2
* Image optimization — Auto WebP variants (thumb, medium, large)
* AI tagging — Claude / OpenAI vision API
* Full-text search (SQLite FTS5)
* 16 languages with RTL support
* Shortcode: `[fluxfiles]`
* Classic editor media button integration
* Dark mode with auto-detection

== Installation ==

1. Download a release ZIP that includes the `vendor/` folder (WordPress.org or GitHub Releases)
2. In WordPress go to **Plugins → Add New → Upload Plugin**, choose the ZIP, install, and activate — or unzip into `wp-content/plugins/fluxfiles/` and activate from **Plugins**
3. Go to **Settings > FluxFiles** to configure your JWT secret and storage options
4. Use the shortcode `[fluxfiles]` in any page or post

*(Developers building from Git: run `composer install --no-dev --optimize-autoloader` in the plugin directory before zipping, or use a monorepo checkout with `composer install -d packages/core` next to `packages/wordpress`.)*

== Using an existing upload directory ==

If you already have files under `wp-content/fluxfiles/uploads/` (or any other local-disk path configured in Settings → FluxFiles) from before the plugin was installed, listing and preview work out of the box. **Search** however relies on the FluxFiles metadata index (FTS5) and the directory index (`_fluxfiles/dirs.json`), which are only written when content is created through the API.

To make pre-existing files and folders searchable, use the bundled WP-CLI command:

`# Dry run first — report what would be indexed, no writes
wp fluxfiles seed --disk=local --dry-run

# Apply
wp fluxfiles seed --disk=local

# Only a sub-tree
wp fluxfiles seed --disk=local --path=user_1

# Force re-index (overwrite any existing metadata)
wp fluxfiles seed --disk=local --overwrite`

The command walks the disk recursively (skipping `_fluxfiles/`, `_variants/`, and `*.meta.json`). For each file it creates a metadata record with `title` derived from the filename. For each folder it updates `_fluxfiles/dirs.json` so folder search works. For S3/R2 disks with an existing bucket, pass `--disk=s3` or `--disk=r2`.

If you cannot use WP-CLI, trigger the same indexing by re-uploading files through the FluxFiles UI — new uploads register metadata automatically.

== Frequently Asked Questions ==

= What storage backends are supported? =

Local disk, AWS S3, and Cloudflare R2. You can configure multiple disks and allow users to switch between them.

= Does it require an external server? =

No. The plugin bundles the full FluxFiles backend. Everything runs within your WordPress installation.

= What PHP version is required? =

PHP **8.1 or higher** (Intervention Image v3 and the rest of `fluxfiles/fluxfiles`). Anything below 8.1 is not supported on the current release line.

== Screenshots ==

1. File manager — grid view with image previews
2. File manager — list view with metadata
3. Settings page — storage configuration
4. Dark mode — automatic theme detection

== Changelog ==

= 1.22.0 =
* Requires PHP 8.1+ (core dependencies). Prefer installing from a ZIP that includes `vendor/`.
* Vue / Nuxt adapter added
* 16 languages with RTL support

= 1.21.0 =
* Vue 3 / Nuxt 3 adapter

= 1.20.0 =
* 16 languages (en, vi, zh, ja, ko, fr, de, es, ar, pt, it, ru, th, hi, tr, nl)
* RTL support for Arabic

= 1.19.0 =
* AI auto-tag — Claude / OpenAI vision integration

= 1.18.0 =
* Image crop inline with aspect ratio presets

= 1.17.0 =
* Bulk operations — multi-select with bulk move, copy, delete, download

= 1.13.0 =
* Initial WordPress plugin release
* Settings page, shortcode, media button integration

== Upgrade Notice ==

= 1.22.0 =
Requires **PHP 8.1+** and a build that includes `vendor/` (release ZIP or run `composer install --no-dev` before deploying). Review settings after upgrade.
