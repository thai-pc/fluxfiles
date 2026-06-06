=== FluxFiles ===
Contributors: thaipc
Tags: file-manager, media, s3, r2, upload, cloud-storage
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.2.2
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
* Full-text search across file names + metadata
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

If you already have files under `wp-content/fluxfiles/uploads/` (or any other local-disk path configured in Settings → FluxFiles) from before the plugin was installed, listing and preview work out of the box. **Search** however relies on the FluxFiles metadata index (`_fluxfiles/index.json`) and the directory index (`_fluxfiles/dirs.json`), which are only written when content is created through the API.

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

= 0.2.2 =
Bundles FluxFiles core **0.2.5**:
* **Trash / Restore** — deleting a file or folder now moves it (folders move the whole subtree) to a restorable trash instead of destroying it. A new **Trash** panel lets you restore, delete forever, or empty the trash. Permanent delete is still available via the API.

= 0.2.1 =
Bundles FluxFiles core **0.2.4**:
* **Bucket Doctor** — diagnose an S3/R2 disk (credentials, read/write/delete, presign, CORS, multipart) with IAM/CORS remediation; an in-app "Bucket health" panel for tokens that can write.
* **Activity log** with prefix-scoped audit and filters (requires the `audit` permission).
* A file's extension is now immutable across rename/move/copy (security), no flash of untranslated keys on load, and internal folders are excluded from search.

= 0.2.0 =
First public release. WordPress adapter for the FluxFiles file manager:
* Settings page, `[fluxfiles]` shortcode, and editor media button with iframe picker.
* REST proxy at `/wp-json/fluxfiles/v1/` with JWT bridged from the WordPress user.
* `wp fluxfiles seed` WP-CLI command — index pre-existing files/folders under `wp-content/fluxfiles/uploads/` (or any configured S3/R2 disk) so they appear in search.
* Local, AWS S3, and Cloudflare R2 storage; cursor-based pagination; 16 languages with RTL.
* Requires PHP 8.1+. Prefer installing from a ZIP that bundles `vendor/`.

== Upgrade Notice ==

= 0.2.2 =
Bundles FluxFiles core 0.2.5 — deletes now go to a restorable Trash (files and folders). Install from the release ZIP that includes `vendor/`.

= 0.2.1 =
Bundles FluxFiles core 0.2.4 (Bucket Doctor, activity log, extension immutability). Install from the release ZIP that includes `vendor/`, or rebuild with `composer install --no-dev`.

= 0.2.0 =
Requires **PHP 8.1+** and a build that includes `vendor/` (release ZIP or run `composer install --no-dev` before deploying). Review settings after activation.
