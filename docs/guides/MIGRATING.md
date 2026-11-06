# Migrating to FluxFiles from an unmaintained file manager

This guide is for developers who embed or run one of the file managers below and
need to replace it. Each section gives the product's status with a dated source, maps
its integration and configuration concepts to FluxFiles, shows a minimal before/after
integration, explains how to bring existing files across, and lists what you gain and
what changes, including what FluxFiles does not do.

Status facts about other products were checked on **2026-11-06**. Each one links its
source. Check those sources for anything newer. FluxFiles facts refer to `core-v1.0.2`.

## Quick pick

| You use today | Status in brief | Go to |
|---|---|---|
| Responsive FileManager (RFM) | Archived; unpatched critical RCE CVE-2026-5482; CC BY-NC licence | [Responsive FileManager](#responsive-filemanager) |
| KCFinder | Archived; last commit 2014 | [KCFinder](#kcfinder) |
| Roxy Fileman | Last SourceForge update 2015; official domain no longer serves the product | [Roxy Fileman](#roxy-fileman) |
| RichFilemanager | Last commit 2019; unpatched upload CVE-2025-63994 | [RichFilemanager](#richfilemanager) |
| Tiny File Manager | No release since 2.6 (2024-11-05); six CVEs open against 2.6 | [Tiny File Manager](#tiny-file-manager) |
| File Browser (filebrowser/filebrowser) | Archived 2026-09-01; no further security fixes | [File Browser](#file-browser) |

## How FluxFiles differs, in one paragraph

The tools above read their settings from a config file, a PHP session or their own
user database. FluxFiles reads them from a **signed JWT** that your backend mints for
each user and request. The token carries the disks the user may open, the folder they
are confined to (`prefix`), their permissions, the upload size and extension limits and
the quota. The server enforces those claims on every API call and stores no per-tenant
configuration ([Multi-tenant](../../README.md#multi-tenant)). The UI runs in an iframe.
The editor plugins, the JS SDK, React/Vue, Laravel and WordPress all embed it, and they
pass the token over `postMessage`, never in a URL
([Token handling](../../README.md#token-handling)). Every claim is listed in the
[configuration reference](../reference/CONFIG.md).

## Steps common to every migration

1. **Check the runtime.** FluxFiles needs PHP 8.1 or newer, with `gd`, `curl`, `json`,
   `openssl`, `mbstring` and `fileinfo`, plus `zip` for extracting archives
   ([Requirements](../../README.md#requirements)). If your host cannot run PHP 8.1, use
   the Docker image ([Run with Docker](../../README.md#run-with-docker-fastest)).
2. **Install and configure the core.** Run `composer require fluxfiles/fluxfiles`, or use
   Docker. Then set `FLUXFILES_SECRET` (32 bytes or longer) and
   `FLUXFILES_ALLOWED_ORIGINS` (the origin of every page that embeds the UI)
   ([Configure](../../README.md#2-configure)). The code that mints tokens must see the
   same `FLUXFILES_SECRET`.
3. **Point a disk at your existing files.** The built-in `local` disk stores files in
   `storage/uploads` inside the package. To keep your current upload directory where it
   is, write your own disks file and name it with `FLUXFILES_DISKS_CONFIG`
   ([Adding a Custom Disk](../../README.md#adding-a-custom-disk)):

   ```php
   <?php
   // /etc/fluxfiles/disks.php — FLUXFILES_DISKS_CONFIG=/etc/fluxfiles/disks.php
   $disks = require '/var/www/app/vendor/fluxfiles/fluxfiles/config/disks.php';

   $disks['local']['root'] = '/var/www/app/public/uploads'; // your existing folder
   $disks['local']['url']  = '/uploads';                    // how the web server serves it

   return $disks;
   ```

   A local root inside the web root also exposes FluxFiles' `_fluxfiles/` bookkeeping
   folder (audit log, index, trash) unless you deny it at the web server. Add the deny
   rule from [`DEPLOYMENT.md`](DEPLOYMENT.md) at the same time. For S3, R2, any
   S3-compatible store or SFTP, see [Storage Disks](../../README.md#storage-disks).
4. **Mint a token on the server** for the logged-in user. This replaces the session
   variables, access keys or user list your old tool used:

   ```php
   require_once '/var/www/app/vendor/fluxfiles/fluxfiles/embed.php';

   $token = fluxfiles_token([
       'user'        => (string) $user->id,
       'perms'       => ['read', 'write', 'delete'],
       'disks'       => ['local'],
       'prefix'      => 'users/' . $user->id . '/', // the folder this user is confined to
       'maxUploadMb' => 10,                         // MB per file
       'allowedExt'  => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'],
       'ttl'         => 3600,                       // seconds
   ]);
   ```

   Laravel apps use `FluxFiles::token()` / `tokenForUser()`
   ([Laravel README](../../packages/laravel/README.md)). WordPress mints tokens itself
   from the user's capabilities ([WordPress README](../../packages/wordpress/README.md#who-gets-a-token)).
   Node and Python backends use [`@fluxfiles/node`](../../packages/node) and
   [`fluxfiles-token`](../../packages/python).
5. **Replace the editor or page integration** with the matching FluxFiles plugin or
   the JS SDK (one snippet per product below).
6. **Index the existing files** so search, folder search and `owner_only` work. See
   [Moving existing files](#moving-existing-files).
7. **Remove the old tool from the web root.** Several of these products carry
   unpatched upload CVEs. They stay exploitable as long as their scripts can be
   reached over HTTP, even when no page links to them any more.

### Moving existing files

Files that already sit on a disk FluxFiles can open appear in listings straight away.
**Search, folder search and ownership** depend on FluxFiles' own index, which is only
written when content arrives through the API. Run the indexer once
([Import Existing Files](../../README.md#import-existing-files)):

```bash
# Composer install: php vendor/bin/fluxfiles …   Monorepo clone: packages/core/bin/fluxfiles …
php vendor/bin/fluxfiles index --disk=local --path=users/42 --dry-run   # report only
php vendor/bin/fluxfiles index --disk=local --path=users/42 --owner=42  # apply
```

| Flag | Effect |
|---|---|
| `--disk=` / `--path=` | Which disk and which sub-tree to walk (default: `local`, whole disk) |
| `--dry-run` | Report what would be indexed and write nothing |
| `--owner=<user id>` | Record that user as the uploader, so `owner_only` protects the files |
| `--readonly` | Record an internal owner that no real user matches |
| `--hash` | Compute SHA-256 for duplicate detection |
| `--variants` | Generate the WebP thumbnail/medium/large variants for images |
| `--overwrite` | Re-index files that already have an index entry |
| `--persist-metadata` | Write metadata sidecars (local) or S3 object metadata instead of index-only (`--owner` and `--readonly` imply this) |

The indexer never modifies or moves the source files. It skips FluxFiles' own
`_fluxfiles/` and `_variants/` folders and any `*.meta.json` file.

**What is carried over:** each file gets a metadata record with a `title` taken from the
file name (without the extension), its size, and its modification time. The creation
time is estimated from that modification time. Folders are added to the folder index.
Ownership, hashes and variants are recorded only when you pass the matching flag.

**What is not carried over:** anything the old tool kept about a file outside the file
itself. That includes users and passwords, per-user or per-folder permission rules,
share links and generated thumbnails. FluxFiles creates its own variants with
`--variants`. A thumbnail folder your old tool wrote inside the upload tree is indexed
as ordinary files, so move it out or delete it before indexing. Alt text, captions and
tags start empty. Files indexed without `--owner` or `--readonly` have no uploader, and
any user who can see them can act on them under `owner_only`. Pass an owner, or treat
those files as shared.

Framework installs have the same workflow:

- **Laravel:** `php artisan fluxfiles:seed --disk=local --path=users/42 --owner=42`. It
  takes the same flags as `bin/fluxfiles index`
  ([Laravel README](../../packages/laravel/README.md#using-an-existing-upload-directory)).
- **WordPress:** `wp fluxfiles seed --disk=local --path=user_1`. It accepts `--disk`,
  `--path`, `--overwrite` and `--dry-run` only: no owner, hash or variants
  ([WordPress README](../../packages/wordpress/README.md#indexing-an-existing-upload-directory)).
  To move Media Library attachments onto a FluxFiles disk, use `wp fluxfiles migrate`
  ([WordPress README](../../packages/wordpress/README.md#migrate-an-existing-site--housekeeping-wp-cli)).

**Changing storage at the same time.** To move files from a local folder into S3 or R2,
copy them with your usual bucket tooling, then index the bucket
(`--disk=s3` or `--disk=r2`; listing is slower because the bucket is paged remotely).
For smaller moves, the UI and API can copy or move a selection between disks, with its
metadata and variants ([Cross-Disk Operations](../../README.md#cross-disk-operations)).

---

## Responsive FileManager

**Status.** The GitHub repository is archived, and the latest release is v9.14.0 from
2019-05-02 ([repo](https://github.com/trippo/ResponsiveFilemanager),
[releases](https://github.com/trippo/ResponsiveFilemanager/releases)). CVE-2026-5482
(critical, CVSS 4.0 9.3, published 2026-06-15) allows unauthenticated upload of any
file type through `dialog.php`, leading to remote code execution. It has no patch
([cvefeed](https://cvefeed.io/vuln/detail/CVE-2026-5482),
[SentinelOne](https://www.sentinelone.com/vulnerability-database/cve-2026-5482/)). The
licence is Creative Commons Attribution-NonCommercial 3.0
([README](https://github.com/trippo/ResponsiveFilemanager/blob/master/README.md)). The
FluxFiles core is MIT.

### Mapping

The RFM settings below are from its
[`config.php`](https://github.com/trippo/ResponsiveFilemanager/blob/master/filemanager/config/config.php)
and [README](https://github.com/trippo/ResponsiveFilemanager/blob/master/README.md).

| RFM | FluxFiles |
|---|---|
| External plugin for TinyMCE 4.x and CKEditor | [`@fluxfiles/tinymce`](../../packages/tinymce/README.md) (TinyMCE 4.x/5.x) or [`@fluxfiles/ckeditor4`](../../packages/ckeditor4/README.md) |
| Standalone file manager (`dialog.php` in a popup or iframe) | JS SDK `FluxFiles.open({ mode: 'picker' })`, or `fluxfiles_embed()` for a server-rendered iframe ([PHP Embed Helper](../../README.md#php-embed-helper)) |
| `session_start()` in `config.php`; optional `USE_ACCESS_KEYS` | A JWT minted by `fluxfiles_token()` on your server |
| "A subfolder as the root … for each user, page or FileManager call" | The `prefix` claim. Mint a different token per user or page. |
| Local upload folder | The `local` disk, with its root set through `FLUXFILES_DISKS_CONFIG` |
| FTP storage (`ftp_host`, `ftp_folder`) | **No equivalent.** FluxFiles has no FTP driver. If the server also accepts SSH, use the [SFTP disk](../../README.md#sftp-disk-vps--shared-hosting). Otherwise copy the files to local or S3 storage. |
| `MaxSizeUpload` (MB) | `max_upload` (MB per file). PHP and the web server must allow at least as much ([Upload size limits](../../README.md#upload-size-limits-three-layers)). |
| `MaxSizeTotal` | `max_storage` (MB under the prefix), plus `max_files` if you want a file count limit |
| File-type restrictions | `allowed_ext`. Executable and server-script extensions are always refused on upload, whatever the token says. |
| `delete_files` | `delete` in `perms` |
| `rename_files`, `copy_cut_files` | `write` in `perms`, which covers upload, rename, copy, move and new folders. FluxFiles has no separate switch for renaming or copying alone. |
| `extract_files` | `allow_extract` (zip archives only) |
| `chmod_files` | `allow_chmod`, on SFTP disks only |
| `edit_text_files` | `allow_code_edit` (default `false`) opens a CodeMirror editor |
| `create_text_files` | **No equivalent.** The editor saves over existing files only. Upload a new file instead. |
| `url_upload` | `allow_url_import` (off by default; SSRF-guarded) ([Import from URL](../../README.md#import-from-url)) |
| TOAST UI image editor (`tui_active`) | Crop with aspect presets and a burn-in watermark editor. FluxFiles has no general image editor. |
| Automatic thumbnails, resize on upload | WebP variants (thumb/medium/large, sized by the `variants` claim) and on-demand resizing via `/img`. The uploaded original is kept as it is. |
| Google Docs viewer preview (`googledoc_enabled`) | Built-in image, video, audio and PDF preview. Office files open in your own Collabora/OnlyOffice through `office_url` ([PDF tools, office and e-sign](../../README.md#pdf-tools-office-and-e-sign-bring-your-own)). |

### Before and after

Before. This is illustrative: the key names come from RFM's `config.php`, and the
structure is simplified.

```php
// filemanager/config/config.php
session_start();
$config = [
    'MaxSizeUpload' => 10,     // MB
    'delete_files'  => true,
    'rename_files'  => true,
    'extract_files' => true,
    'url_upload'    => true,
];
// TinyMCE 4 then loads RFM's external plugin, which opens filemanager/dialog.php.
```

After. Mint the token as in [step 4](#steps-common-to-every-migration), then:

```html
<script src="https://fm.example.com/fluxfiles.js"></script>
<script src="/js/tinymce/tinymce.min.js"></script>
<script>
tinymce.init({
    selector: '#editor',
    external_plugins: { fluxfiles: '/node_modules/@fluxfiles/tinymce/plugin.min.js' },
    toolbar: 'undo redo | bold italic | fluxfiles | link image',
    fluxfiles_endpoint: 'https://fm.example.com',
    fluxfiles_token: <?= json_encode($token) ?>,
    fluxfiles_disk: 'local'
});
</script>
```

The token's claims take over from the `config.php` switches: `perms` from
`delete_files`/`rename_files`, and `allow_extract`/`allow_url_import` in the `claims` map.
Remove RFM's TinyMCE plugin and its `file_picker_callback`. The FluxFiles plugin wires
the native image dialog only when no other callback is set.

### Moving existing files

RFM keeps uploads in a local folder. Point the `local` disk at it (step 3) and run
`bin/fluxfiles index` (see [Moving existing files](#moving-existing-files)). If a user
was confined to a subfolder, index that subfolder with `--path=` and `--owner=`. Files
on an FTP server have to be copied to local, SFTP or S3 storage first.

### What you gain / what changes

- A maintained, MIT-licensed core in place of an archived CC BY-NC one.
- Per-user limits are signed claims, not a shared `config.php` or session.
- Soft-delete trash with restore, full-text search, dark mode, 16 languages with RTL,
  and S3/R2/SFTP storage.
- **Changes:** PHP 8.1 or newer; no FTP storage; extract and download handle zip only;
  no general image editor; no switch to allow renaming without allowing other writes.
- The TinyMCE plugin supports TinyMCE 4.x and 5.x.

---

## KCFinder

**Status.** The GitHub repository is archived. The last commit is from 2014-09-27, and
the last stable release is 3.12 from 2014-07-09
([repo](https://github.com/sunhater/kcfinder),
[releases](https://github.com/sunhater/kcfinder/releases)). CVE-2019-14315, an XSS in
`upload.php` through `CKEditorFuncNum`, affects 3.12 and the 3.20 test builds, and no
fixed release exists
([GitLab Advisory DB](https://advisories.gitlab.com/pkg/composer/sunhater/kcfinder/CVE-2019-14315/)).

### Mapping

KCFinder concepts below are from its
[README](https://github.com/sunhater/kcfinder/blob/master/README.md) and
[commit history](https://github.com/sunhater/kcfinder/commits/master).

| KCFinder | FluxFiles |
|---|---|
| CKEditor "Browse Server" integration | [`@fluxfiles/ckeditor4`](../../packages/ckeditor4/README.md): a toolbar button, plus a **Browse FluxFiles** button inside CKEditor's own Image Properties dialog |
| TinyMCE integration | [`@fluxfiles/tinymce`](../../packages/tinymce/README.md) (TinyMCE 4.x/5.x) |
| FCKeditor integration | **No plugin.** Use the JS SDK's `onSelect` callback. |
| Settings passed through the PHP session | A JWT minted by `fluxfiles_token()` |
| Local upload folder | The `local` disk, with its root set through `FLUXFILES_DISKS_CONFIG` |
| Multiple-file and drag-and-drop upload | Built in. Each file is checked on its own ([Uploading multiple files](../../README.md#uploading-multiple-files)). |
| Download several files or a folder as ZIP | `allow_zip` and `allow_download` (both default `true`) |
| Clipboard copy and move | Copy and move under `write`, plus copy and move between disks |
| Resize on upload (max resolution) | **No equivalent.** The original is kept. WebP variants and `/img` give resized copies. |
| PNG watermark | The burn-in watermark editor (per image, by hand) or the serve-time overlay (`watermark_enabled`). Nothing stamps uploads automatically. |
| Configurable thumbnails | WebP variant widths through the `variants` claim |
| Themes, multilanguage | `light`/`dark`/`auto` theme and CSS custom properties in `fm.css`; 16 languages with RTL |

### Before and after

Before. This is illustrative: CKEditor 4's file browser setting pointed at a KCFinder
install, with KCFinder's settings put in the session by your app.

```js
CKEDITOR.replace('editor', {
    filebrowserBrowseUrl: '/kcfinder/'   // URL of your KCFinder browser page
});
```

After:

```html
<script src="https://fm.example.com/fluxfiles.js"></script>
<script src="/js/ckeditor/ckeditor.js"></script>
<script>
CKEDITOR.plugins.addExternal('fluxfiles', '/node_modules/@fluxfiles/ckeditor4/', 'plugin.min.js');
CKEDITOR.replace('editor', {
    extraPlugins: 'fluxfiles',
    fluxfiles: {
        endpoint: 'https://fm.example.com',
        token: <?= json_encode($token) ?>,
        disk: 'local'
    }
});
</script>
```

Remove `filebrowserBrowseUrl` and the session code that set up KCFinder. The plugin
inserts images with `alt`, `width` and `height` from the file's metadata. It prefers
the file's `permanent_url`, because the `url` on a private disk is a presigned link
that expires ([`permanent_url` vs `url`](../../README.md#embedding-selected-files-permanent_url-vs-url)).

### Moving existing files

KCFinder stores uploads in a local folder. Point the `local` disk at it, delete or move
any thumbnail folder KCFinder generated inside it, and run `bin/fluxfiles index`
(see [Moving existing files](#moving-existing-files)). Add `--variants` to build
FluxFiles thumbnails.

### What you gain / what changes

- A maintained core that runs on PHP 8.1 to 8.5, against an archived 2014 codebase
  whose composer.json asks for PHP 5.3 or newer
  ([composer.json](https://github.com/sunhater/kcfinder/blob/master/composer.json)).
- Per-user scoping (`prefix`), quotas, extension allowlists and an audit log.
- Storage beyond the local disk: S3, R2, any S3-compatible store, SFTP.
- **Changes:** no automatic resize or watermark on upload; no FCKeditor plugin; zip is
  the only archive format.

---

## Roxy Fileman

**Status.** The SourceForge project was last updated on 2015-03-15, and its latest file
is `RoxyFileman-1.4.3-php.zip` ([SourceForge](https://sourceforge.net/projects/roxyfileman/)).
On 2026-11-06 the official domain `www.roxyfileman.com` served unrelated casino
content and no file manager. It is deliberately not linked here. CVE-2022-40797 is an
RCE through a `.phar` upload in 1.4.6, because the default `FORBIDDEN_UPLOADS` in
`conf.json` blocks only `.php`, `.php4` and `.php5`
([CIRCL](https://vulnerability.circl.lu/vuln/CVE-2022-40797)). CVE-2018-20526 is an
unrestricted file upload in 1.4.5 ([CIRCL](https://cve.circl.lu/cve/CVE-2018-20526)).
No maintained release fixes either.

### Mapping

Roxy concepts below are from
[SourceForge](https://sourceforge.net/projects/roxyfileman/) and the
[CVE-2022-40797 record](https://vulnerability.circl.lu/vuln/CVE-2022-40797).

| Roxy Fileman | FluxFiles |
|---|---|
| File browser for CKEditor and TinyMCE | [`@fluxfiles/ckeditor4`](../../packages/ckeditor4/README.md), [`@fluxfiles/tinymce`](../../packages/tinymce/README.md) |
| Use in other apps | JS SDK, React, Vue, or a plain iframe through `fluxfiles_embed()` |
| PHP or ASP.NET server scripts | One PHP 8.1+ core, run inside your app or as a separate service (Docker). Token SDKs exist for PHP, Node and Python. **No .NET SDK**: an ASP.NET app has to call one of those, or sign the HS256 JWT itself following [`CONFIG.md`](../reference/CONFIG.md). The core refuses a token without `exp`. |
| `conf.json`, including `FORBIDDEN_UPLOADS` | Per-token claims. `allowed_ext` is an allowlist. On top of it, an always-on blocklist refuses server-executable types such as `php`, `phtml`, `phar`, `php3`, `php4`, `php5`, `php7`, `php8`, `cgi`, `sh`, `asp`, `aspx` and `htaccess`. |
| Local upload folder | The `local` disk |
| List and thumbnail views, drag-and-drop move, context menus | Built in |

### Before and after

Before. This is illustrative: TinyMCE's file picker opening a Roxy Fileman page.

```js
tinymce.init({
    selector: '#editor',
    file_picker_callback: function (callback, value, meta) {
        // open your Roxy Fileman page in a window; call callback(url) when a file is picked
    }
});
```

After. Delete the custom `file_picker_callback`, because the FluxFiles plugin installs
its own only when none is set:

```js
tinymce.init({
    selector: '#editor',
    external_plugins: { fluxfiles: '/node_modules/@fluxfiles/tinymce/plugin.min.js' },
    toolbar: 'undo redo | bold italic | fluxfiles | link image',
    fluxfiles_endpoint: 'https://fm.example.com',
    fluxfiles_token: '<JWT minted on your server>',
    fluxfiles_disk: 'local'
});
```

Load `https://fm.example.com/fluxfiles.js` on the page before TinyMCE starts.

### Moving existing files

Point the `local` disk at Roxy's upload folder and run `bin/fluxfiles index` (see
[Moving existing files](#moving-existing-files)). Before you index, look for files that
the new blocklist would refuse on upload, such as `.phar` or `.php5`. The indexer lists
what is already on disk without judging it, so remove anything that should not be
there.

### What you gain / what changes

- An extension policy that is an allowlist per token plus a fixed blocklist, instead
  of a single editable deny-list.
- A maintained core with a security advisory process, trash, search and S3/R2/SFTP
  storage.
- **Changes:** PHP 8.1 or newer; no ASP.NET server side; a server you run for the
  FluxFiles core (or the Docker image), next to your app or inside it.

---

## RichFilemanager

**Status.** The repository is not formally archived, but its last commit is from
2019-01-21 and its last release is v2.7.6 from 2018-06-27
([repo](https://github.com/psolom/RichFilemanager),
[releases](https://github.com/psolom/RichFilemanager/releases)). CVE-2025-63994
(CVSS 9.8, published 2025-11-18) is an arbitrary file upload in `/php/UploadHandler.php`
of v2.7.6 that leads to code execution, with no vendor patch reported
([MITRE](https://cveawg.mitre.org/api/cve/CVE-2025-63994),
[vuln.today](https://vuln.today/cve/CVE-2025-63994)). The PHP connector requires
Laravel 5.4–5.8 `illuminate/*` components and Symfony 3.4/4
([connector composer.json](https://github.com/psolom/RichFilemanager-PHP/blob/master/composer.json)).

### Mapping

RichFilemanager concepts below are from its
[README](https://github.com/psolom/RichFilemanager/blob/master/README.md).

| RichFilemanager | FluxFiles |
|---|---|
| CKEditor, TinyMCE integrations | [`@fluxfiles/ckeditor4`](../../packages/ckeditor4/README.md), [`@fluxfiles/tinymce`](../../packages/tinymce/README.md); [`@fluxfiles/summernote`](../../packages/summernote/README.md) for Summernote |
| Imperavi Redactor, colorbox, "simple textfield" | **No Redactor plugin.** Use the JS SDK: `FluxFiles.open({ onSelect })` fills a field or inserts into any editor. |
| Connectors in PHP, Java, ASHX, ASP, Node.js, Python Flask | One PHP core. Your backend only mints tokens: PHP, [Node](../../packages/node) or [Python](../../packages/python). Use the [Laravel](../../packages/laravel/README.md) and [WordPress](../../packages/wordpress/README.md) adapters for those frameworks. |
| Local storage, AWS S3 through the PHP connector | `local`, `s3` (AWS or any S3-compatible store), `r2` and `sftp` disks; per-tenant buckets with [BYOB](../../README.md#byob-bring-your-own-bucket) |
| "Limit max size of the storage (root folder)" | `max_storage` (MB under the prefix) |
| User permissions based on the session | `perms` (`read`/`write`/`delete`) and the `allow_*` claims in a signed token |
| "Pass config user file in URL" | **No equivalent, by design.** The configuration is the signed token, and the token never goes in a URL. |
| "Opening exclusively a given folder" | `prefix` |
| File type and pattern restrictions | `allowed_ext` (extensions only; FluxFiles has no file-name patterns) |
| File size limit | `max_upload` (MB per file) |
| Multiple and chunked uploads with resume | S3/R2 multipart chunks for files over 10 MB; resumable tus uploads on local/SFTP disks for files over 8 MB (standalone core, not through the Laravel/WordPress proxies) |
| Folder download as zip | `allow_zip` |
| CodeMirror text editor | CodeMirror editor through `allow_code_edit` |
| ViewerJS (PDF/OpenOffice), Google Docs viewer (MS Office) | Built-in PDF preview; office files through your own Collabora/OnlyOffice (`office_url`) |
| Text search filter | Full-text search over file names and metadata, plus folder search |

### Before and after

Before. This is illustrative: CKEditor's "Browse Server" pointed at a RichFilemanager
page.

```js
CKEDITOR.replace('editor', {
    filebrowserBrowseUrl: '/richfilemanager/index.html'   // path of your install
});
```

After, for a plain text field (the "simple textfield" case):

```html
<input id="cover" type="text">
<button type="button" onclick="pickCover()">Choose…</button>

<script src="https://fm.example.com/fluxfiles.js"></script>
<script>
function pickCover() {
    FluxFiles.open({
        endpoint: 'https://fm.example.com',
        token: <?= json_encode($token) ?>,
        disk: 'local',
        mode: 'picker',
        allowedTypes: ['image/*'],
        onSelect(file) {
            document.getElementById('cover').value = file.permanent_url || file.url;
        },
        async onTokenRefresh() {
            const res = await fetch('/fluxfiles-token');   // your endpoint that re-mints
            return (await res.json()).token;
        }
    });
}
</script>
```

For CKEditor, use the CKEditor 4 snippet in the [KCFinder](#kcfinder) section.

### Moving existing files

For local storage, point the `local` disk at the connector's root folder. For S3,
configure the `s3` disk with the same bucket. Then run `bin/fluxfiles index` (see
[Moving existing files](#moving-existing-files)). Use `--disk=s3` for a bucket.
Session-based permissions are not carried over: express them as claims when you mint
each user's token.

### What you gain / what changes

- One maintained PHP 8.1+ core in place of a connector pinned to Laravel 5.x and
  Symfony 3/4 components.
- R2 and SFTP storage, per-tenant buckets, trash, quota, audit log.
- **Changes:** no Java/.NET/Python connectors (the core is PHP; only token minting is
  multi-language); extensions only, no file-name patterns; no Redactor plugin.

---

## Tiny File Manager

**Status.** The latest release is 2.6 from 2024-11-05. Commits since then are mostly
translations and small fixes; the last one is from 2026-07-20
([releases](https://github.com/prasathmani/tinyfilemanager/releases),
[commits](https://github.com/prasathmani/tinyfilemanager/commits/master)). Six CVEs
affect "up to"/"through" 2.6 with no fixed release:
[CVE-2025-15138](https://nvd.nist.gov/vuln/detail/CVE-2025-15138),
[CVE-2026-6496](https://nvd.nist.gov/vuln/detail/CVE-2026-6496),
[CVE-2026-6497](https://nvd.nist.gov/vuln/detail/CVE-2026-6497),
[CVE-2026-108801](https://nvd.nist.gov/vuln/detail/CVE-2026-108801),
[CVE-2026-116044](https://nvd.nist.gov/vuln/detail/CVE-2026-116044) (each record notes
that the vendor did not respond) and
[CVE-2025-46651](https://nvd.nist.gov/vuln/detail/CVE-2025-46651). The project's own
README advises against using it as a standard file manager in public spaces and says to
remove it from the server after use
([README](https://github.com/prasathmani/tinyfilemanager)).

### Mapping

Tiny File Manager settings below are from
[`tinyfilemanager.php`](https://github.com/prasathmani/tinyfilemanager/blob/master/tinyfilemanager.php)
and its [README](https://github.com/prasathmani/tinyfilemanager).

| Tiny File Manager | FluxFiles |
|---|---|
| One PHP file copied into the web space | The FluxFiles core (Composer or Docker), embedded in your app through the SDK or an iframe |
| Built-in login (`$auth_users` with `password_hash` hashes) | **No built-in user database.** Your app logs the user in and mints a token. The paid SSO module adds an OIDC login if you need one ([ACTIVATE.md](ACTIVATE.md)). |
| `$readonly_users`, `$global_readonly` | `perms: ['read']`, or `role: 'viewer'` |
| `$directories_users` (a root folder per user) | `prefix` |
| `$root_path` / `FM_ROOT_PATH` | The `local` disk's root |
| `FM_EMBED` (included from another script, with its own auth switched off) | An embed that keeps auth on: the SDK passes a signed token, and every API call is checked against it |
| IP allow/deny ruleset | **No equivalent in FluxFiles.** Use your web server's allow/deny rules. Rate limits are per user (`rate_read`/`rate_write`). |
| zip and tar compress/extract | zip download and zip extract only. **No tar.** |
| Cloud9/ACE editor | CodeMirror editor (`allow_code_edit`). Dotfiles such as `.htaccess` also need `show_hidden`. |
| Google/Microsoft online document viewer | Built-in PDF preview; office files through your own Collabora/OnlyOffice (`office_url`), so files don't go to a third-party viewer |
| Upload from URL | `allow_url_import` (off by default; every redirect is SSRF-checked) |
| Chunked upload | tus resumable upload on local/SFTP disks; multipart chunks on S3/R2 |
| `chmod` | `allow_chmod`, on **SFTP disks only**. FluxFiles cannot chmod local files. |
| Light/dark `$theme` | `theme`: `light`, `dark` or `auto` |
| 35+ languages | 16 languages with RTL |

### Before and after

Before. This is illustrative, using the variable names from `tinyfilemanager.php`.

```php
$auth_users = ['admin' => '<password_hash>', 'user' => '<password_hash>'];
$readonly_users = ['user'];
$directories_users = ['user' => '/var/www/uploads/user'];
$root_path = '/var/www/uploads';

// …or included from your own app, with its own auth switched off:
define('FM_EMBED', true);
include 'tinyfilemanager.php';
```

After: your app decides who the user is and what they may do, mints the token, and
embeds the browser.

```php
<?php
require_once '/var/www/app/vendor/fluxfiles/fluxfiles/embed.php';

$token = fluxfiles_token([
    'user'   => (string) $user->id,
    'role'   => $user->isAdmin ? 'admin' : 'viewer',   // viewer = read-only
    'disks'  => ['local'],
    'prefix' => $user->isAdmin ? '' : 'user/',          // was $directories_users
]);

echo fluxfiles_embed(
    endpoint: 'https://fm.example.com',
    token:    $token,
    disk:     'local',
    mode:     'browser',
    height:   '600px'
);
```

Role presets set `perms`, `owner_only` and a few tool switches. `admin` includes the
code editor and hidden files. Claims you set explicitly win over the preset
([`ACL-ROLE-PRESETS-DESIGN.md`](../design/ACL-ROLE-PRESETS-DESIGN.md)).

### Moving existing files

Point the `local` disk at `$root_path`, then index each user's folder with `--path=`
and `--owner=` (see [Moving existing files](#moving-existing-files)). Passwords, the
read-only lists and IP rules are not carried over. They become your app's login, the
token's claims and web server rules.

### What you gain / what changes

- Access is checked per request against a signed token, not a hard-coded user list in
  a PHP file.
- Trash, search, quotas, image variants and S3/R2/SFTP storage.
- **Changes:** PHP 8.1 or newer, against Tiny File Manager's PHP 5.5; no built-in login
  screen; no tar; no chmod on local disks; no IP rules; fewer UI languages.

---

## File Browser

**Status.** The README of filebrowser/filebrowser says "File Browser is archived on
2026-09-01 … There will be no further releases, bug fixes, or security fixes". The
final release is v2.63.23 from 2026-07-27
([README](https://github.com/filebrowser/filebrowser),
[releases](https://github.com/filebrowser/filebrowser/releases),
[maintainer's post, 2026-07-28](https://hacdias.com/2026/07/28/filebrowser/)). A
separately maintained fork, FileBrowser Quantum, exists
([repo](https://github.com/gtsteffaniak/filebrowser)). It is the closer like-for-like
replacement if you want a standalone app with its own users. FluxFiles is a different
shape: a component your application embeds and authorizes.

### Mapping

File Browser concepts below are from its
[README](https://github.com/filebrowser/filebrowser),
[authentication docs](https://github.com/filebrowser/filebrowser/blob/master/docs/authentication.md)
and [security advisories](https://github.com/filebrowser/filebrowser/security/advisories).

| File Browser | FluxFiles |
|---|---|
| Go binary or container serving one directory | The FluxFiles Docker image ([Run with Docker](../../README.md#run-with-docker-fastest)) or a PHP install; local, S3, R2 and SFTP disks |
| Auth methods `json`, `proxy` (header), `hook` (script), `noauth` | Your app mints a token per user. **No proxy-header or hook auth.** The paid SSO module offers OIDC login. |
| Per-user scope | `prefix` |
| Per-user permissions | `perms` and the `allow_*` claims |
| Sessions are JWTs that "cannot be revoked" | FluxFiles access tokens are also stateless and valid until `exp`, with no server-side session to revoke. Mint them short-lived (`ttl`) and renew them through `onTokenRefresh` ([Token Refresh](../../README.md#token-refresh)). |
| TUS resumable uploads | tus on local/SFTP disks (standalone core); multipart chunks on S3/R2 |
| Public share links with passwords | The paid Share module (`allow_share`); not in the free core |
| Archive download as zip/tar | zip only |
| Command runner and hooks (off by default via `--disable-exec`) | **None.** FluxFiles has no built-in shell. `terminal_pty_url` embeds your own ttyd/gotty/wetty ([Terminal](../../README.md#terminal-bring-your-own-ttyd)). |
| Built-in previews and editor | Image, video, audio and PDF preview; CodeMirror editor (`allow_code_edit`) |
| 34 UI translations | 16 languages with RTL |

### Before and after

Before. This is illustrative: your app links to a separate File Browser instance
behind a reverse proxy that authenticates users (the `proxy` auth method).

```html
<a href="https://files.example.com/">Open files</a>
```

After: the browser opens inside your page, scoped by a token your backend minted.

```html
<div id="files" style="height:600px"></div>
<script src="https://fm.example.com/fluxfiles.js"></script>
<script>
FluxFiles.open({
    endpoint: 'https://fm.example.com',
    token: '<JWT minted on your server>',
    disk: 'local',
    mode: 'browser',
    container: '#files',
    async onTokenRefresh() {
        const res = await fetch('/fluxfiles-token');
        return (await res.json()).token;
    }
});
</script>
```

From a Node backend, mint the token with
`createToken({ userId, perms, prefix, … })` from [`@fluxfiles/node`](../../packages/node).
Run the FluxFiles Docker image as the file service, so your own codebase needs no PHP.

### Moving existing files

If the FluxFiles container can mount the directory File Browser served, point the
`local` disk at it, or copy the files into FluxFiles' storage volume. Then run the
indexer inside the container (see [Moving existing files](#moving-existing-files)).
Users, their scopes and share links are not carried over. Scopes become `prefix`
claims, and shares need the Share module.

### What you gain / what changes

- Per-request scoping by a signed token, with no user database on the file server.
- S3, R2 and SFTP storage next to local, per-tenant buckets, trash, quotas, audit log.
- **Changes:** FluxFiles is a component inside your app, not a standalone multi-user
  site. It has no login screen or user management (except through the paid SSO
  module), no share links in the free core, no tar, no command runner, and fewer UI
  languages.
