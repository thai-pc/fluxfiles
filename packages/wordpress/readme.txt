=== FluxFiles ===
Contributors: thaipc
Tags: file-manager, media, s3, r2, upload, cloud-storage
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
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

1. Upload the `fluxfiles` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **Settings > FluxFiles** to configure your JWT secret and storage options
4. Use the shortcode `[fluxfiles]` in any page or post

== Frequently Asked Questions ==

= What storage backends are supported? =

Local disk, AWS S3, and Cloudflare R2. You can configure multiple disks and allow users to switch between them.

= Does it require an external server? =

No. The plugin bundles the full FluxFiles backend. Everything runs within your WordPress installation.

= What PHP version is required? =

PHP 7.4 or higher. Compatible with PHP 7.4, 8.0, 8.1, 8.2, and 8.3.

== Screenshots ==

1. File manager — grid view with image previews
2. File manager — list view with metadata
3. Settings page — storage configuration
4. Dark mode — automatic theme detection

== Changelog ==

= 1.22.0 =
* PHP 7.4+ compatibility (supports PHP 7.4 — 8.3)
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
