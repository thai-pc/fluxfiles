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

== Frequently Asked Questions ==

= What storage backends are supported? =

Local disk, AWS S3, and Cloudflare R2. You can configure multiple disks and allow users to switch between them.

= Does it require an external server? =

No. The plugin bundles the full FluxFiles backend. Everything runs within your WordPress installation.

= What PHP version is required? =

PHP **8.1 or higher** (Intervention Image v3 and the rest of `fluxfiles/fluxfiles`). PHP 7.4 / 8.0 are not supported with the current release line.

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
Now supports PHP 7.4+. No breaking changes — safe to upgrade.
