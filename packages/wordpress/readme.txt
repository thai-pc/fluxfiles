=== FluxFiles ===
Contributors: thaipc
Tags: media library, media folders, amazon s3, cloudflare r2, offload media
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1.0
Stable tag: 1.1.8
License: MIT
License URI: https://opensource.org/licenses/MIT

Folder-based media & document library with cloud storage (Amazon S3, Cloudflare R2). Sandboxed: no access to WordPress files, no code uploads.

== Description ==

FluxFiles is a folder-based media and document library for WordPress with a modern browser UI. Keep your files in folders on your local disk, Amazon S3 or Cloudflare R2 (and SFTP through a developer-minted token), and insert them into posts: what you pick is registered as a real WordPress attachment whose URL points at your storage, so it is served from your bucket or CDN instead of your web server.

**Features**

* Multi-storage: local disk, Amazon S3, Cloudflare R2; SFTP through a BYOB token
* Folders, drag and drop and chunked uploads, rename, move, copy, a restorable Trash, and a built-in crop editor
* Image optimization with WebP variants (thumb, medium, large)
* Optional AI tagging and alt text with the provider of your choice (see External services)
* Full-text search across file names and metadata
* Duplicate finder, usage dashboard and quota
* 16 languages with right-to-left support, dark mode with auto-detection
* Shortcode `[fluxfiles]`, a "FluxFiles Image" block and a classic editor media button
* WordPress attachment bridge: files picked in FluxFiles appear in the media library, and their URLs (with a responsive `srcset` for images) are served from your storage
* Media offload: `wp fluxfiles migrate` moves existing Media Library attachments to a public S3/R2 bucket; deleting an attachment can optionally delete the stored file too
* WP-CLI: `wp fluxfiles seed` indexes files that already exist on a disk

**What FluxFiles can and cannot access**

FluxFiles manages the files in its **own storage area**. It is not a tool for browsing or editing your WordPress installation.

* **Its own sandbox only.** The local disk is a dedicated folder, `wp-content/uploads/fluxfiles/` (or `wp-content/fluxfiles/` on older installs). It is chosen automatically and cannot be changed from the admin screens. Only a site developer can move it, in code (the `FLUXFILES_STORAGE_DIR` constant or the `fluxfiles_storage_base` filter). FluxFiles cannot open, list or modify WordPress core, plugin or theme files, or `wp-config.php`. Every path is resolved inside the storage root: `..` segments are dropped, and an archive entry containing `..` is rejected.
* **No server-side code can be uploaded.** PHP and every other server-executed type (`php`, `phtml`, `phar`, `php3`, `php4`, `php5`, `php7`, `php8`, `pht`, `cgi`, `pl`, `py`, `rb`, `sh`, `asp`, `aspx`, `jsp`, and more), plus the files `.htaccess`, `.htpasswd`, `.user.ini` and `web.config`, are always refused, including as a middle extension such as `shell.php.jpg`. This built-in list applies to uploads, renames, moves, copies and archive extraction, and no setting can turn it off.
* **Archives are checked entry by entry.** Extracting a ZIP applies the same list, and the upload extension rules, to every file inside it before anything is written. An archive that contains a refused file is rejected as a whole, and hidden dot-files are skipped.
* **No active content for untrusted roles.** A user without the `unfiltered_html` capability can never upload HTML, SVG, XML, XSL, JavaScript or Flash files, the same rule WordPress applies to its own Media Library. On Apache, the plugin also writes a `.htaccess` into the storage folder that turns the PHP engine off there and serves stored HTML, SVG and XML as plain downloads. On nginx, the settings page shows the equivalent rules to add.
* **There is no terminal or shell access.**
* **There is no code or file-content editor in the WordPress plugin; stored files can be previewed and downloaded, never edited in place or executed.** Changing file permissions (`chmod`) is also disabled.
* **Nothing it stores is ever executed.** FluxFiles never includes, requires or evaluates a file from its storage area, and it contains no `eval`.
* **No code is downloaded.** The plugin never downloads or installs plugins, themes or executable code, and it has no updater of its own. Updates come from WordPress.org.
* **Permissions follow WordPress roles.** Read, upload, delete and admin actions are derived from the user's WordPress capabilities (Administrators and Editors manage all files, Authors only their own). Each multisite sub-site gets its own separate storage root.
* **External services are opt-in.** Amazon S3, Cloudflare R2, SFTP and AI tagging are contacted only when an administrator configures them (see External services).

The plugin is free and open source (MIT). Everything in this plugin works without a licence. Optional paid modules (share links, upload portals, compliance tools) are available for the self-hosted FluxFiles server; see [fluxfiles.thai-pc.com](https://fluxfiles.thai-pc.com).

== Installation ==

1. In WordPress go to **Plugins > Add New**, search for FluxFiles, then install and activate it. Or upload the ZIP under **Plugins > Add New > Upload Plugin**, or unzip it into `wp-content/plugins/fluxfiles/`.
2. Go to **Settings > FluxFiles** to choose your storage options and review the defaults.
3. Add the shortcode `[fluxfiles]` to a page or post, or use the "FluxFiles Image" block.

== External services ==

FluxFiles has no telemetry, and it makes no update or licence calls. It contacts an outside service only when an administrator or developer configures one, as listed here.

= Amazon S3 =
When an administrator configures an S3 disk, FluxFiles sends the files you upload, their object keys and metadata tags to the S3 endpoint you set (and reads them back). The Bucket Doctor tool, run from the UI, sends small probe objects, a CORS check and a presign test to the same bucket. Amazon Web Services: [Service Terms](https://aws.amazon.com/service-terms/), [Privacy Notice](https://aws.amazon.com/privacy/).

= Cloudflare R2 =
When an administrator configures an R2 disk, the same data as for S3 goes to the R2 endpoint you set. Cloudflare: [Terms](https://www.cloudflare.com/terms/), [Privacy Policy](https://www.cloudflare.com/privacypolicy/).

= Your SFTP server =
Only when a developer mints a BYOB token for an SFTP server. File bytes and paths go to that server, which you operate.

= AI tagging providers =
Only when an administrator selects a provider and enters its API key, and the token has AI tagging enabled. FluxFiles sends a downscaled copy of the image (at most 1024 px), the prompt and your API key to the provider, and receives tags and alt text back. Supported providers, each under its own terms:

* Anthropic: [Commercial Terms](https://www.anthropic.com/legal/commercial-terms), [Privacy Policy](https://www.anthropic.com/legal/privacy)
* OpenAI: [Terms](https://openai.com/policies/terms-of-use/), [Privacy Policy](https://openai.com/policies/privacy-policy/)
* Google Gemini: [API Terms](https://ai.google.dev/gemini-api/terms), [Privacy Policy](https://policies.google.com/privacy)
* OpenRouter: [Terms](https://openrouter.ai/terms), [Privacy Policy](https://openrouter.ai/privacy)
* Groq: [Terms](https://groq.com/terms-of-use/), [Privacy Policy](https://groq.com/privacy-policy/)
* Mistral AI: [Terms](https://mistral.ai/terms), [Privacy Policy](https://mistral.ai/terms#privacy-policy)
* xAI: [Terms](https://x.ai/legal/terms-of-service), [Privacy Policy](https://x.ai/legal/privacy-policy)
* A compatible or local endpoint that you run: your own terms.

= URL import =
Only when the token allows it: FluxFiles makes an HTTP GET to the URL a user asks it to import. Requests to private and reserved addresses are refused.

= E-mail delivery providers =
E-mail notices (an upload-portal drop, a share-link download, a test e-mail) go through WordPress's own `wp_mail()` by default, so whatever mail setup the site already has delivers them and FluxFiles contacts no one itself. Only when a site owner sets `FLUXFILES_MAIL_TRANSPORT` (in `wp-config.php` or the environment) to one of the providers below does FluxFiles send each notice directly to that provider's API: the recipient addresses, the sender and reply-to addresses, the subject, the plain-text body (which can name the uploaded or downloaded files and the portal or share label) and your API key or AWS signature. Each provider is under its own terms:

* Resend: [Terms](https://resend.com/legal/terms-of-service), [Privacy Policy](https://resend.com/legal/privacy-policy)
* Postmark: [Terms](https://postmarkapp.com/terms-of-service), [Privacy Policy](https://postmarkapp.com/privacy-policy)
* Mailgun: [Terms](https://www.mailgun.com/legal/terms/), [Privacy Policy](https://www.mailgun.com/legal/privacy-policy/)
* Amazon SES: [Service Terms](https://aws.amazon.com/service-terms/), [Privacy Notice](https://aws.amazon.com/privacy/)

= Operator-run embeds =
Only when a developer sets `pdf_tools_url`, `office_url` or `esign_url`: the UI loads your own instance (Stirling-PDF, Collabora/OnlyOffice, DocuSeal) in an iframe. `office_url` and `esign_url` may receive the selected file's URL.

== Source & build ==

* Source code and issue tracker: https://github.com/thai-pc/fluxfiles (this plugin is `packages/wordpress`, the bundled file manager is `packages/core`, the browser SDK is `packages/sdk`).
* Build the plugin ZIP from a checkout with `bash scripts/build-wordpress.sh`. It needs PHP 8.1+, Composer, rsync and zip.
* `assets/fm.js` and `assets/fm.css` are the readable sources of `assets/fm.min.js` and `assets/fm.min.css`, which are produced by `bash scripts/build-ui.sh` (esbuild, minify only).
* [Alpine.js](https://github.com/alpinejs/alpine) 3.15.12 ships unmodified as `assets/vendor/alpine/alpine.min.js`.
* PHP dependencies in `vendor/` are installed from `packages/core/composer.lock`; their licences ship in their folders. See `docs/THIRD-PARTY-NOTICES.md`.

== Using an existing upload directory ==

If you already have files in the local storage folder (`wp-content/uploads/fluxfiles/uploads/` on new installs, `wp-content/fluxfiles/uploads/` on older ones, or the path set with the `FLUXFILES_STORAGE_DIR` constant) from before the plugin was installed, listing and preview work out of the box. **Search** however relies on the FluxFiles metadata index (`_fluxfiles/index.json`) and the directory index (`_fluxfiles/dirs.json`), which are only written when content is created through the API.

To make pre-existing files and folders searchable, use the bundled WP-CLI command:

`# Dry run first — report what would be indexed, no writes
wp fluxfiles seed --disk=local --dry-run

# Apply
wp fluxfiles seed --disk=local

# Only a sub-tree
wp fluxfiles seed --disk=local --path=user_1

# Force re-index (overwrite any existing metadata)
wp fluxfiles seed --disk=local --overwrite`

The command walks the disk recursively (skipping the internal `_fluxfiles/` and `_variants/` folders). For each file it creates a metadata record with `title` derived from the filename. For each folder it updates `_fluxfiles/dirs.json` so folder search works. For S3/R2 disks with an existing bucket, pass `--disk=s3` or `--disk=r2`.

If you cannot use WP-CLI, trigger the same indexing by re-uploading files through the FluxFiles UI — new uploads register metadata automatically.

== Frequently Asked Questions ==

= Is this a file manager for my WordPress installation? =

No. FluxFiles only manages the files in its own storage folder, or in the S3/R2 bucket you connect. It cannot see or edit WordPress, plugin or theme files, it has no code or file-content editor, and it refuses PHP and other executable uploads. Think of it as a folder-based media and document library with cloud storage, not as a server file browser.

= Can I put two [fluxfiles] shortcodes on one page? =
No: the file manager runs in a single frame per page, so only the first one loads and later ones show a notice.

= How is this different from WP Offload Media? =
Both can serve your media from S3 or Cloudflare R2. WP Offload Media hooks into the normal Media Library upload and copies each upload to the bucket for you. FluxFiles works the other way round: you manage files in its own folder-based file manager, and the files you pick are registered as WordPress attachments that point at your storage. Offload needs a public bucket or CDN URL; private buckets and SFTP are not supported for it. `wp fluxfiles migrate` moves existing Media Library attachments to a bucket, and the plugin itself has no per-file limits or fees. If you want every Media Library upload mirrored automatically, check that this workflow fits before switching.

= How is this different from FileBird? =
FileBird adds folders to the native Media Library grid. FluxFiles does not change that grid: it has its own file manager (the `[fluxfiles]` shortcode, the "FluxFiles Image" block and an editor button) with folders, search, trash, a crop editor and multiple storage disks. Files you pick there show up in the Media Library as attachments, but you organise them in the FluxFiles screen, not by dragging items around the Media Library. If folders inside the Media Library grid are what you need, FileBird is built for that.

= What storage backends are supported? =

Local disk, AWS S3, and Cloudflare R2, configured in Settings. You can configure multiple disks and allow users to switch between them. SFTP has no Settings screen: a developer mints a BYOB token that carries the server details.

= Does it require an external server? =

No. The plugin bundles the full FluxFiles backend. Everything runs within your WordPress installation.

= What PHP version is required? =

PHP **8.1 or higher** (Intervention Image v3 and the rest of `fluxfiles/fluxfiles`). Anything below 8.1 is not supported on the current release line.

= Can I use FluxFiles for my WordPress media library? =

Yes. Files picked through the FluxFiles UI register as real WordPress attachments — the media
library, `wp_get_attachment_url()`, and the Gutenberg block all see them, even though the bytes
live on S3/R2 or in FluxFiles' own local storage folder, not in WordPress's dated `uploads/YYYY/MM/` folders.

= Where does FluxFiles store my files? =
New installs use the "fluxfiles" folder inside your WordPress uploads folder (usually wp-content/uploads/fluxfiles/), which most hosts back up and keep across deployments. Sites that already had wp-content/fluxfiles/ keep it; nothing is moved when you update. You can choose another location with the FLUXFILES_STORAGE_DIR constant in wp-config.php or the fluxfiles_storage_base filter. Because FluxFiles stores the location, not a full path, a moved or cloned site follows its own folders. Some media-cleaner plugins may list the files under uploads/fluxfiles as unused media; exclude that folder from them.

= What happens to my data when I delete the plugin? =
By default deleting FluxFiles removes only its settings and rate-limit counters. Your files, their metadata, trash, audit log and legal-hold tables, and the secret key (it signs the audit log) are kept, so reinstalling picks everything up again. To remove everything, tick "Delete all FluxFiles data when the plugin is deleted" in Settings > FluxFiles before deleting the plugin. Then the database tables, the secret, the trash, the generated variants and the other FluxFiles bookkeeping in its storage folder (the `_fluxfiles/` and `_variants/` directories) are permanently removed. The files in the storage folder are never deleted, whoever put them there; remove the folder by hand if you no longer need them. At a custom location set with FLUXFILES_STORAGE_DIR or the fluxfiles_storage_base filter only the rate-limit counters are deleted, and files on S3, R2 or SFTP are never touched.

= Where are warnings logged? =

Operational warnings are written to the PHP error log only when `WP_DEBUG` / `WP_DEBUG_LOG` are enabled; with them off the plugin stays silent.

= My site runs on nginx. Is the uploads folder protected? =

Not automatically: the plugin writes `.htaccess` rules, which nginx ignores. The storage folder is `/wp-content/uploads/fluxfiles/` on new installs and `/wp-content/fluxfiles/` on older ones. While the local disk is public, the Plugins screen and Settings → FluxFiles show the nginx rules to add (no PHP execution, HTML/SVG/XML served inert, `_fluxfiles/` denied) — or turn on private local storage (Settings > FluxFiles) and deny the folder; the notice then shows the one-line rule. Independently of the server, users without the `unfiltered_html` capability can never upload HTML, SVG, XML, JavaScript or PHP files through FluxFiles.

== Screenshots ==

1. The file manager: folders, image thumbnails and PDF/video previews, with one-click switching between storage disks (local, S3-compatible, SFTP).
2. Image details panel with the built-in crop editor: free or fixed aspect ratios, then replace the original or save a copy.
3. Upload several files at once by drag and drop or the Upload button, with overall progress per batch.
4. Insert images from FluxFiles straight into the block editor with the "FluxFiles Image" block.

== Changelog ==

= 1.1.8 =
* Bundles FluxFiles core 1.4.0.
* Operator screen: an "E-mail me" switch and recipient picker when you create a share link or upload portal, a bell to mute or resume e-mails per link, and a "Send test e-mail" button (e-mail notices need FluxFiles Pro).
* Document requests (FluxFiles Pro): list the documents you need, set a due date and e-mail the portal link to your client. The checklist page, reminders and "all received" notes come from Pro.

= 1.1.7 =
* Bundles FluxFiles core 1.3.0.
* E-mail notices for upload portals and share links (FluxFiles Pro) are delivered through WordPress's own wp_mail(), so your SMTP plugin sends them. No mail settings are needed in FluxFiles. Developers can swap or disable the transport with the new `fluxfiles_mail_transport` filter, or name a provider with `FLUXFILES_MAIL_TRANSPORT` in wp-config.php.
* "External services" now lists the e-mail providers that are used only when you configure one.

= 1.1.6 =
* Bundles FluxFiles core 1.2.4.
* Internal classes renamed with the plugin's FluxFiles prefix (WordPress.org Plugin Check). No behaviour change.

= 1.1.5 =
* Bundles FluxFiles core 1.2.4.
* Fix: deleting, trashing, renaming or moving an image now also removes the resized copies FluxFiles had cached for it.
* Settings screen code cleanup (escaping, and the theme code sample now uses wp_enqueue_script).

= 1.1.4 =
* Bundles FluxFiles core 1.2.3.
* Fix: "From FluxFiles" in the WordPress media picker. The Image block no longer stays under a blank window, the classic editor's "Add Media" inserts the picked images (it inserted nothing), Gallery and "Add Media" take several files, and the button works in the Customizer.
* Fix: images picked from FluxFiles now behave as images everywhere in WordPress (featured images in themes, classic inserts, Customizer crops).
* Fix: Amazon S3 and Cloudflare R2 enabled in Settings can now be chosen in the file manager (only Local was offered).
* Fix: image previews and private-storage links work on sites that use plain permalinks.
* Fix: people who may not delete or rename files no longer see those buttons.
* New settings screen: tabs, a status summary, a storage connection test, show/hide and generate for secrets, and validation that stops a 0 or negative TTL, upload size or quota. The nginx notice now checks your server and disappears once the rules are in place.
* New setting: "Pick several files" for the FluxFiles button in the classic editor.

= 1.1.3 =
* Bundles FluxFiles core 1.2.1.
* The file-content editor, chmod and the terminal embed are disabled in the WordPress plugin; clarified what the plugin can and cannot access.

= 1.1.2 =
* Bundles FluxFiles core 1.2.0.
* Security: stricter handling of storage paths in the bundled development router.
* Internal groundwork for licence key renewal in the optional FluxFiles Pro add-on. No change in behaviour for the free plugin.

= 1.1.1 =
* Project links and the description now point at fluxfiles.thai-pc.com; the readme no longer mentions a WordPress Pro add-on that does not exist.
* Bundles FluxFiles core 1.1.8.

= 1.1.0 =
* Fix: on a multisite network, activating FluxFiles network-wide set up only the main site; every other site failed with "Unable to generate token". Network activation now initialises every existing site, new sites are set up when they are created, and a site that was missed is set up on its first request. Deleting the plugin cleans every site.
* Fix: "Delete all FluxFiles data" no longer deletes your files. It removes FluxFiles' own bookkeeping (trash, audit log, index, generated variants, protection files) and never the files in the storage folder, including files that were there before FluxFiles.
* Fix: on private local storage the block and the classic editor button no longer save a link that stops working after an hour. They explain that private files have no permanent link and insert nothing.
* New: Settings has a "Private local storage" switch. On Apache the folder is then denied; on nginx a notice shows the rule to add.
* The JWT secret and the S3/R2 secret keys are no longer written into the Settings page; leave the field empty to keep the stored value.
* Fix: two `[fluxfiles]` shortcodes on one page no longer silently replace each other; the second shows a notice (one file manager per page).
* Fix: file rate-limit counters are removed on uninstall; the block uses block API version 3 and no longer logs a React key warning; Contributors get a message that fits their role instead of "not configured"; Settings and shortcode messages are translatable.
* FluxFiles is now listed on WordPress.org. The plugin no longer checks for updates itself: WordPress.org delivers them.
* The paid modules (Share, Intake, versioning, webhooks, compliance tools) moved to the separate FluxFiles Pro add-on. This plugin keeps every free feature and provides hooks for the add-on.
* Share and Intake links now use the site's REST URL (the old `public-link.php` entry point is gone).
* The code editor uses the CodeMirror bundled with WordPress instead of shipping its own copy.
* The ZIP is smaller: standalone-only files (entry point, licence and SSO code, tus server) and the AWS SDK services FluxFiles does not use are no longer included.
* Admin messages are translatable (text domain `fluxfiles`).

= 1.0.4 =
* Fix: on a multisite network, a Share or Intake link created while another site was active (inside `switch_to_blog()`) opened the wrong site and failed. Links now carry the creating site's id and always open that site.
* Fix: Share and Intake links now work on sites that use plain permalinks.

= 1.0.3 =
* Security: on a multisite network every site now has its own storage root, `wp-content/fluxfiles/sites/<blog_id>/`. Before, all sites shared one root with an empty token prefix, so a sub-site administrator or author could reach the other sites' files, index, audit log and trash. Single-site installs are unchanged.
* Security: `chmod` on an SFTP disk now respects `owner_only` for folders and refuses the root folder; `owner_only` tokens no longer get `allow_chmod` by default.
* Security: BYOB image URLs are no longer cacheable by shared caches; the SSRF guard also refuses documentation, benchmarking, multicast and reserved address ranges.
* Security: the bundled UI pages in `public/` can no longer be opened directly (deny `.htaccess` + 403 `index.php`; on nginx add a `deny all` location for that folder).
* `extract` returns the normalised destination folder instead of echoing the raw input.
* Multisite upgrade, in order (details in the plugin README, "Upgrading to 1.0.3 on multisite"): (1) pick the blog id that owned the files, usually 1; (2) enable maintenance mode and back up `wp-content/fluxfiles/`; (3) delete the freshly generated `fluxfiles/sites/<id>/uploads/_fluxfiles` so the legacy index and audit log win; (4) run `rsync -a fluxfiles/uploads/ fluxfiles/sites/<id>/uploads/`, check that `rsync -ain --checksum fluxfiles/uploads/ fluxfiles/sites/<id>/uploads/` prints nothing, then `rm -rf fluxfiles/uploads`; (5) per site, run `wp search-replace '/wp-content/fluxfiles/uploads/' '/wp-content/fluxfiles/sites/<id>/uploads/' --url=<site-url>` with `--dry-run` first (no `--network`); (6) run `wp fluxfiles seed --url=<site-url>` and leave maintenance mode. Do not `mv` the whole folder: the new one already exists.

= 1.0.2 =
* The `onSelect` payload now includes `created` and `modified` (unix seconds), as documented.

= 1.0.1 =
* Downloads and previews now work on BYOB SFTP disks. Their per-file links carry the disk config encrypted and last at most 30 minutes.
* Search, folder search, quota, usage and attachments now work on BYOB disks.
* `FLUXFILES_SSRF_ALLOW_HOSTS` now applies to the plugin, as a real env var or a `wp-config.php` constant, so an SFTP or S3 server on a private network can be reached.
* An image transform that hits a storage error now returns a plain 502.

= 1.0.0 =
* `/stream` and `/img` now run on core's shared media endpoint: the preview watermark overlay (`watermark_enabled`) works, X-Accel paths are encoded, and the rate limits default to core's (stream 300/min, img 120/min).
* In database storage mode the rate limiter now lives in the database too (new `fluxfiles_rate_limits` table, created on update).
* Audit entries for refused writes and 403s, the FileManager hook wiring and the database metadata handler now share code with core.

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
* Requires PHP 8.1+ (8.1–8.5 tested; keeping PHP patched is the host's job). Prefer installing from a ZIP that bundles `vendor/`.

== Upgrade Notice ==

= 1.1.8 =
Bundles core 1.4.0: e-mail notice controls on the file manager screen and document requests for FluxFiles Pro. No change for sites without Pro.

= 1.1.7 =
Bundles core 1.3.0. E-mail notices for FluxFiles Pro go through your site's own mail (wp_mail). No change for sites without Pro.

= 1.1.6 =
Code-naming cleanup requested by the WordPress.org Plugin Check. No behaviour change.

= 1.1.5 =
Removes resized copies of images you delete or trash, and tidies the settings screen code. Recommended for everyone on 1.1.4.

= 1.1.4 =
Fixes inserting files through the WordPress media picker, lets S3 and R2 be chosen in the file manager, and brings a clearer settings screen. Recommended for everyone.

= 1.1.3 =
Disables the file-content editor, chmod and the terminal embed, and documents what the plugin can and cannot access. Files can still be previewed and downloaded.

= 1.1.2 =
Updates the bundled FluxFiles core to 1.2.0, with storage path hardening. No change in behaviour for the free plugin.

= 1.1.1 =
Updated project links; no functional change from 1.1.0.

= 1.1.0 =
Share, Intake and the compliance tools now come from the separate FluxFiles Pro add-on. The free file manager is unchanged. Multisite network activation now sets up every site, and deleting data leaves your files alone.

= 1.0.4 =
Share and Intake links now open the site that created them on multisite, and work with plain permalinks. No action needed.

= 1.0.3 =
Security release. Multisite: files now live in wp-content/fluxfiles/sites/<blog_id>/uploads/ and existing ones must be moved. Follow "Upgrading to 1.0.3 on multisite" in the plugin README (and the 1.0.3 changelog entry). Single sites: no action.

= 1.0.2 =
Adds `created`/`modified` to the file-select payload.

= 1.0.1 =
Fixes BYOB SFTP downloads and previews, BYOB search and quota, and the private-network allow-list.

= 1.0.0 =
Preview watermarks now work through the plugin. Database-mode sites get a new rate-limit table on update.

= 0.2.2 =
Bundles FluxFiles core 0.2.5 — deletes now go to a restorable Trash (files and folders). Install from the release ZIP that includes `vendor/`.

= 0.2.1 =
Bundles FluxFiles core 0.2.4 (Bucket Doctor, activity log, extension immutability). Install from the release ZIP that includes `vendor/`, or rebuild with `composer install --no-dev`.

= 0.2.0 =
Requires **PHP 8.1+** and a build that includes `vendor/` (release ZIP or run `composer install --no-dev` before deploying). Review settings after activation.
