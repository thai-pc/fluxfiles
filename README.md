# FluxFiles

[![Packagist Version](https://img.shields.io/packagist/v/fluxfiles/fluxfiles?label=packagist&color=f28d1a)](https://packagist.org/packages/fluxfiles/fluxfiles)
[![Laravel](https://img.shields.io/packagist/v/fluxfiles/laravel?label=laravel&color=ff2d20)](https://packagist.org/packages/fluxfiles/laravel)
[![npm](https://img.shields.io/npm/v/fluxfiles?label=sdk&color=cb3837)](https://www.npmjs.com/package/fluxfiles)
[![npm](https://img.shields.io/npm/v/@fluxfiles/react?label=react&color=61dafb)](https://www.npmjs.com/package/@fluxfiles/react)
[![npm](https://img.shields.io/npm/v/@fluxfiles/vue?label=vue&color=42b883)](https://www.npmjs.com/package/@fluxfiles/vue)
[![npm](https://img.shields.io/npm/v/@fluxfiles/ckeditor4?label=ckeditor4&color=1eb5ff)](https://www.npmjs.com/package/@fluxfiles/ckeditor4)
[![npm](https://img.shields.io/npm/v/@fluxfiles/tinymce?label=tinymce&color=2dc26b)](https://www.npmjs.com/package/@fluxfiles/tinymce)
[![npm](https://img.shields.io/npm/v/@fluxfiles/summernote?label=summernote&color=73a839)](https://www.npmjs.com/package/@fluxfiles/summernote)
[![npm](https://img.shields.io/npm/v/@fluxfiles/node?label=node&color=339933)](https://www.npmjs.com/package/@fluxfiles/node)
[![Docker image](https://img.shields.io/badge/ghcr.io-fluxfiles-2496ed?logo=docker&logoColor=white)](https://github.com/thai-pc/fluxfiles/pkgs/container/fluxfiles)
[![PHP](https://img.shields.io/packagist/php-v/fluxfiles/fluxfiles?color=777bb4)](https://packagist.org/packages/fluxfiles/fluxfiles)
[![License](https://img.shields.io/github/license/thai-pc/fluxfiles)](LICENSE)

Standalone, embeddable file manager built with PHP 8.1+. Multi-storage support (Local, AWS S3, Cloudflare R2), JWT authentication, and a zero-build-step frontend powered by Alpine.js.

Drop it into any web app via iframe + SDK, or use the provided adapters for **Laravel**, **WordPress**, **React**, **Vue / Nuxt**, **CKEditor 4**, **TinyMCE**, and **Summernote**.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Production Deployment](#production-deployment)
- [Embedding in Your App](#embedding-in-your-app)
  - [On-demand WebP](#on-demand-webp) · [Responsive `srcset`](#responsive-srcset) · [Watermark](#watermark-preview-protection) · [Usage dashboard](#usage-dashboard)
- [Storage Disks](#storage-disks)
  - [SFTP disk](#sftp-disk-vps--shared-hosting) · [Config / code editor](#config--code-editor) · [Zip / Extract](#zip--extract) · [BYOB](#byob-bring-your-own-bucket) · [Cross-disk operations](#cross-disk-operations)
- [JWT Token Structure](#jwt-token-structure)
  - [Token parameters & units](#token-parameters--units) · [Import from URL](#import-from-url)
- [Multi-tenant](#multi-tenant)
- [API Reference](#api-reference)
- [Framework Adapters](#framework-adapters)
- [Internationalization](#internationalization)
- [Security](#security)
- [Testing](#testing)
- [Environment Variables](#environment-variables)
- [Project Structure](#project-structure)
- [Storage Internals](#storage-internals-_fluxfiles-rate_limitjson)
- [Customization](#customization)
- [Attribution](#attribution)
- [License](#license)

---

## Features

| Category | Details |
|----------|---------|
| **Storage** | Local disk, AWS S3, Cloudflare R2, **SFTP** (VPS / shared hosting) via Flysystem v3. Cross-disk copy/move with stream transfer. BYOB (Bring Your Own Bucket) — encrypted per-token credentials. |
| **Auth** | JWT HS256 with granular claims — permissions, disk access, path scoping, upload limits, file type whitelist, storage quota, owner-only, plus per-feature gates. |
| **File ops** | Upload, download (presigned URL), move, copy, rename, delete, create folders. Chunk upload (S3 multipart). Bulk multi-select. **Import-from-URL** (SSRF-guarded). **Zip download** of a selection, and **Extract** in place (zip-slip / zip-bomb guarded). |
| **Trash** | Soft-delete **files and folders** to a storage-resident trash (restore / list / purge / empty); folders move the whole subtree. |
| **Images** | Auto WebP variants on upload (thumb / medium / large). **On-demand WebP** at any size (`/api/fm/img`) with **responsive `srcset`**. **On-the-fly watermark** (text/logo) for preview protection. Inline crop with aspect presets. |
| **Media** | Inline image / video / audio / PDF preview, with auto-refresh of expiring URLs. **Gated private local media** streamed through the app (Range-capable, nginx `X-Accel-Redirect`). |
| **Config / code** | In-browser **code/config editor** (CodeMirror, syntax by extension) for text files. **SFTP `chmod`** — cPanel-style Unix permissions. |
| **AI** | Claude or OpenAI vision API — auto-tag, alt text, title, caption on upload or manual trigger. |
| **Metadata** | Title, alt text, caption, tags per file. S3 object metadata (cloud) or sidecar JSON (local). Full-text file + folder search. |
| **Insights** | **Storage usage dashboard** — quota + per-type and per-folder breakdown (file-cached). |
| **Safety** | Duplicate detection (SHA-256). Rate limiting per user. Audit log with rotation. Per-user quota. Origin/CSRF validation. Always-on dangerous-extension blocking. SSRF guard (BYOB + URL import). Zip slip/bomb guards. |
| **UI** | Dark mode (auto/manual). 16 languages with RTL support. Responsive (mobile overflow menu). Bulk operations (multi-select, shift-select). |
| **Adapters** | Laravel, WordPress, React, Vue/Nuxt, CKEditor 4, TinyMCE, Summernote, plus **`@fluxfiles/node`** (server-side token SDK). |

---

## Requirements

- **PHP** >= 8.1 (Flysystem 3 + Intervention Image v3 + firebase/php-jwt v7; tested with 8.1 — 8.4)
- **Extensions:** `gd`, `curl`, `json`, `openssl`, `mbstring`, `fileinfo`
- **Composer** >= 2.0

---

## Quick Start

### Run with Docker (fastest)

The standalone app (nginx + php-fpm) is published to GHCR — no PHP/Composer on
your host. Pull and run:

```bash
docker run -p 8080:80 \
  -e FLUXFILES_SECRET="$(openssl rand -hex 32)" \
  ghcr.io/thai-pc/fluxfiles:latest
```

Open **http://localhost:8080/public/index.html**. The container reads every
`FLUXFILES_*`, `AWS_*` and `R2_*` env var (see [Environment Variables](#environment-variables)).
Persist uploads + runtime state with a volume, and add cloud creds as needed:

```bash
docker run -p 8080:80 \
  -e FLUXFILES_SECRET="$(openssl rand -hex 32)" \
  -e AWS_BUCKET=my-bucket -e AWS_ACCESS_KEY_ID=... -e AWS_SECRET_ACCESS_KEY=... \
  -v fluxfiles-data:/app/packages/core/storage \
  ghcr.io/thai-pc/fluxfiles:latest
```

Images are tagged `latest`, the release version (`0.2.18`) and minor (`0.2`), for
`linux/amd64` and `linux/arm64`. To build from the monorepo instead:
`docker compose up` (app + MinIO) or `make up`.

> A JS/non-PHP backend? Run this image as your file service and mint tokens with
> [`@fluxfiles/node`](packages/node) — no PHP in your own codebase.

### 1. Install (from source)

```bash
git clone https://github.com/thai-pc/fluxfiles.git
cd fluxfiles
composer install -d packages/core
```

### 2. Configure

```bash
cp .env.example .env
```

Edit `.env` — at minimum, set these two:

```env
FLUXFILES_SECRET=your-random-secret-key-min-32-chars
FLUXFILES_ALLOWED_ORIGINS=http://localhost:3000,https://yourapp.com
```

### 3. Run

```bash
cd packages/core
php -S localhost:8080 router.php
```

Open in browser:
- **UI:** http://localhost:8080/public/index.html
- **API:** http://localhost:8080/api/fm/list?disk=local&path=

### URL Parameters (Standalone Mode)

When opening FluxFiles directly via `/public/index.html`, configure it with URL parameters:

```
/public/index.html?token=JWT&disk=local&path=photos/&locale=vi&theme=dark
```

| Parameter | Required | Default | Description |
|-----------|----------|---------|-------------|
| `token` | **Yes** | — | JWT authentication token |
| `disk` | No | `local` | Active disk |
| `disks` | No | `local` | Comma-separated available disks (e.g. `local,s3,r2`) |
| `path` | No | `` (root) | Initial directory path |
| `locale` | No | `en` | UI language (`en`, `vi`, `zh`, `ja`, `ko`, `fr`, `de`, `es`, `ar`, `pt`, `it`, `ru`, `th`, `hi`, `tr`, `nl`) |
| `lang` | No | `en` | Alias for `locale` |
| `theme` | No | auto | `light`, `dark`, or auto-detect |
| `multiple` | No | `false` | `1` or `true` to enable multi-select |

> **Security:** a token in the URL can leak via history/logs/`Referer`. For
> production, embed via the SDK (token sent over `postMessage`, never in the URL)
> and keep `ttl` short — see [Token handling](#token-handling).

### 4. Generate a Token

```php
// Composer: vendor/fluxfiles/fluxfiles/embed.php
// Monorepo clone: packages/core/embed.php
require_once 'path/to/fluxfiles/embed.php';

$token = fluxfiles_token(
    userId:       'user-123',
    perms:        ['read', 'write', 'delete'],
    disks:        ['local', 's3', 'r2'],
    prefix:       'user-123/',   // scope user to their own directory
    maxUploadMb:  10,            // MB — max size PER uploaded file
    allowedExt:   ['jpg','png','pdf'], // extensions (lowercase, no dot); null = all safe types
    ttl:          3600,          // SECONDS — token lifetime (3600 = 1 hour)
    ownerOnly:    false,         // true = users only manage files they uploaded
    maxStorageMb: 1000,          // MB — total quota for the prefix; 0 = unlimited
    maxFiles:     0              // max number of files under the prefix; 0 = unlimited
);
```

> **Units at a glance:** `maxUploadMb` and `maxStorageMb` are **megabytes (MB)**,
> `ttl` is **seconds**, `allowedExt` is a list of **extensions** (lowercase, no
> leading dot). See the [parameter reference](#token-parameters--units) below.

Or generate via CLI for testing:

```bash
php packages/core/tests/generate-token.php
```

---

## Production Deployment

### Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name fm.yourdomain.com;
    root /var/www/fluxfiles/packages/core;

    # SSL
    ssl_certificate     /etc/ssl/certs/fm.yourdomain.com.pem;
    ssl_certificate_key /etc/ssl/private/fm.yourdomain.com.key;

    # Max request body — must be >= the largest `max_upload` (MB) you issue in a
    # JWT, or nginx rejects big uploads with 413 BEFORE the request reaches PHP.
    # Set this a bit above your biggest per-file limit (here: 100 MB).
    client_max_body_size 100M;

    # API — rewrite to PHP router
    location /api/ {
        try_files $uri /api/index.php?$query_string;
    }

    # Public HTML — MUST go through PHP so the resolved locale (messages + dir)
    # is injected before the UI boots. A plain `try_files $uri ...` would serve
    # the static index.html first and skip PHP, causing a flash of raw i18n keys.
    location = /public           { rewrite ^ /api/index.php last; }
    location = /public/          { rewrite ^ /api/index.php last; }
    location = /public/index.html { rewrite ^ /api/index.php last; }

    # Static assets (JS, CSS)
    location /assets/ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # SDK file
    location = /fluxfiles.js {
        expires 7d;
        add_header Cache-Control "public";
    }

    # Uploaded files (local disk only).
    # Security: stop MIME-sniffing and neutralize active content (e.g. <script>
    # inside an uploaded SVG/HTML) so user files can't run as same-origin XSS.
    location /storage/uploads/ {
        alias /var/www/fluxfiles/packages/core/storage/uploads/;
        expires 7d;
        add_header Cache-Control "public";
        add_header X-Content-Type-Options "nosniff" always;
        add_header Content-Security-Policy "sandbox" always;
        # HTML/SVG are never safe to render inline — force download.
        location ~* \.(html?|xhtml|shtml|xml|svg)$ {
            add_header X-Content-Type-Options "nosniff" always;
            add_header Content-Security-Policy "sandbox" always;
            add_header Content-Disposition "attachment" always;
        }
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }

    # Block dotfiles and sensitive paths
    location ~ /\. { deny all; }
    location ~ ^/(\.env|composer\.|vendor/) { deny all; }
    location /storage/rate_limit.json { deny all; }
    location /_fluxfiles/ { deny all; }
}
```

### Apache (.htaccess)

```apache
RewriteEngine On

# API routes
RewriteRule ^api/(.*)$ api/index.php [QSA,L]

# Public HTML through PHP for locale injection
RewriteRule ^public/(index\.html)?$ api/index.php [QSA,L]

# Block sensitive files
<FilesMatch "^\.env|composer\.(json|lock)">
    Require all denied
</FilesMatch>
```

### Directory Permissions

```bash
# Set ownership
chown -R www-data:www-data /var/www/fluxfiles/storage/

# Writable directories
chmod -R 755 storage/
chmod 600 .env
chmod 600 storage/rate_limit.json   # if exists
```

### Upload size limits (three layers)

A large upload must pass **three** independent limits — the smallest one wins.
The JWT `max_upload` is the only one FluxFiles controls; the other two are your
server/PHP config and must be **≥** your largest `max_upload`, or files are
rejected *before* reaching the app (often as a confusing `413` or
`400 No file uploaded` instead of FluxFiles' own `413 upload_too_large`).

| Layer | Setting | Where | Note |
|-------|---------|-------|------|
| Web server | `client_max_body_size` (nginx) / `LimitRequestBody` (Apache) | nginx `server`/`http` block | Rejects the request body if too big. |
| PHP | `upload_max_filesize` **and** `post_max_size` | `php.ini` (or php-fpm pool) | `post_max_size` must be ≥ `upload_max_filesize`; both ≥ your `max_upload`. |
| FluxFiles | `max_upload` (JWT claim, **MB, per file**) | issued in your token | Returns `413 upload_too_large` when exceeded. |

```ini
; php.ini — example for a 100 MB per-file limit
upload_max_filesize = 100M
post_max_size       = 110M     ; a little above upload_max_filesize
```

> Files **larger than 10 MB on S3/R2 disks** use chunked (multipart) upload, which
> sends 5 MB parts directly to the bucket — so those bypass `post_max_size`. Local
> disk always uses a single request, so the PHP/nginx limits above apply in full.

---

## Embedding in Your App

### JavaScript SDK (Vanilla)

Include `fluxfiles.js` on your page — zero dependencies, works with any framework
(use the minified `fluxfiles.min.js`, ~5 KB, in production):

```html
<script src="https://fm.yourdomain.com/fluxfiles.js"></script>
<!-- production: <script src="https://fm.yourdomain.com/fluxfiles.min.js"></script> -->

<button onclick="openFilePicker()">Choose File</button>

<script>
function openFilePicker() {
    FluxFiles.open({
        endpoint: 'https://fm.yourdomain.com',
        token: 'eyJhbGci...',        // JWT token from your backend
        disk: 'local',                // default disk
        disks: ['local', 'r2'],       // available disks in sidebar
        mode: 'picker',               // 'picker' = select & close, 'browser' = stay open
        multiple: false,              // true = multi-select returns array
        locale: 'en',                 // default 'en' if omitted
        theme: 'auto',                // 'light', 'dark', or 'auto'
        allowedTypes: ['image/*', '.pdf'],
        maxUploadMb: 10,              // MB — max size per file (deprecated alias: `maxSize` in bytes)
        maxFiles: 20,                 // max files per upload batch (0/omit = unlimited)
        container: '#my-div',         // CSS selector — omit for modal overlay

        onSelect(file) {
            // file = { url, permanent_url, key, name, path, size, disk,
            //          mime, width, height, created, modified, meta, variants }
            // created = stable upload time (folders carry it too); modified = live mtime.
            // For embedding in SAVED content, prefer permanent_url — `url` is a
            // short-lived presigned URL on private disks and will expire.
            const src = file.permanent_url || file.url;
            const img = document.getElementById('image');
            img.src = src;
            img.alt = (file.meta && file.meta.alt_text) || file.name;
            if (file.width)  img.width  = file.width;
            if (file.height) img.height = file.height;
        },
        onClose() {
            console.log('File picker closed');
        },

        // Token refresh — called automatically on 401
        async onTokenRefresh({ reason, disk, path }) {
            const res = await fetch('/api/auth/refresh-fluxfiles-token');
            const { token } = await res.json();
            return token; // return new JWT string, or null to fail
        }
    });
}
</script>
```

#### Embedding selected files (`permanent_url` vs `url`)

The select payload carries two URLs:

- **`url`** — ready to display immediately. On a **private** disk it's a
  short-lived **presigned** URL — it **expires after `url_ttl` seconds (default
  1 hour**, configurable per disk via `AWS_URL_TTL` / `R2_URL_TTL`, max 24h).
  Media files use the longer `preview_url_ttl` claim instead (default 2h). Fine
  for previewing right after selection; **do not save it** into content.
- **`permanent_url`** — a stable, non-expiring URL for **embedding in saved
  content** (CMS pages, editor HTML, DB records). Present for local disks, public
  disks, and any disk with a `public_url` (CDN / custom domain). It is **`null`**
  for a private bucket with no public domain — such a disk has no permanent URL.

```js
// Embedding into content you persist:
const embedSrc = file.permanent_url || file.url; // prefer the stable one
```

> To make `permanent_url` available for a private S3/R2 bucket, serve it behind a
> CDN / custom domain and set `public_url` on the disk config. The CKEditor 4,
> TinyMCE, and Summernote plugins already prefer `permanent_url` automatically and
> warn when they have to fall back to a presigned URL.

### On-demand WebP

FluxFiles generates three **fixed WebP variants** on upload (`thumb` 150 / `medium`
768 / `large` 1920 px) — use `file.variants.<size>.url` and you're already serving
WebP. For **arbitrary sizes on demand** (responsive images, exact widths), each
image entry also carries an **`img_base`** URL:

```js
// From a selected/listed file, build a WebP at any size:
const src = FluxFiles.imgUrl(file, { width: 800, quality: 80 });
// → /api/fm/img?token=…&width=800&quality=80   (a resized WebP, cached in _variants/)
```

- **First request** converts + caches the WebP into the file's `_variants/`
  directory (so the existing delete/trash cleanup invalidates it for free); later
  requests serve the cache. The cache key is stamped with the source mtime, so a
  re-upload never re-matches a stale image.
- **Width** is rounded to 100px and clamped to `webp_max_width`; **quality** snaps
  to `60`/`75`/`80`/`90`. This bounds the number of cache variants per file
  (no unbounded growth from `?width=801,802,…`).
- **Content negotiation:** add `&format=auto` and a browser that doesn't accept
  `image/webp` is served the original untouched.
- **SVG and animated GIFs are never converted** (served as-is); `webp_enabled: false`
  disables the endpoint entirely (no `img_base`).

> `img_base` carries a short-lived per-file token in the query string (an `<img>`
> can't send an `Authorization` header) — the same tradeoff as the media stream
> token: single-file scope + short TTL. It mints only when the disk config wires a
> stream secret, so on-demand WebP is a **core-standalone / Docker** feature (the
> Laravel/WordPress proxies don't expose `/api/fm/img`).

#### Responsive `srcset`

On top of `img_base`, every image entry also gets a ready-to-use **`img_srcset`**
string, so the host can drop a responsive image straight from `list()`:

```html
<img :src="file.url" :srcset="file.img_srcset" :sizes="file.img_sizes || '100vw'" :alt="…">
<!-- img_srcset = "/api/fm/img?token=…&width=400 400w, …&width=800 800w, …&width=1200 1200w" -->
```

- The candidate widths come from the token's **`srcset_widths`** ladder (default
  `[320, 640, 768, 1024, 1366, 1920]`, snapped to 100px and clamped to
  `webp_max_width`). Each is a cached WebP from the same `/api/fm/img` endpoint.
- Widths are **capped at the image's natural width** (read from the stored
  dimensions — no extra I/O), and the source width itself is always offered, so a
  browser never requests an upscale. Images narrower than 100px get no `img_srcset`.
- Set the **`srcset_sizes`** claim to also emit an **`img_sizes`** attribute;
  otherwise the host supplies its own `sizes`. The standalone UI already wires
  `srcset`/`sizes` onto its detail-panel and lightbox previews.
- Rides the exact same gate as `img_base` (so it's also core-standalone / Docker).

### Watermark (preview protection)

For content sellers (photographers, agencies, stock), the on-demand WebP can
overlay a **watermark** so clients see previews but can't grab the clean image.
It's applied **on the fly when serving** — the source file is never modified — so
there's only ever one source of truth. Enable it with token claims (off by default):

```php
$token = fluxfiles_token('user-42', ['read'], ['local'], 'users/42', 10, null, 3600,
    false, 0, 0, null, 0, 0, null, null, null, [
        'webp_enabled'      => true,
        'watermark_enabled' => true,
        'watermark_type'    => 'text',          // or 'logo'
        'watermark_text'    => '© Acme Corp',
        'watermark_position'=> 'bottom-right',
        'watermark_opacity' => 0.6,
        'allow_download'    => false,            // ← the important part (see below)
    ]);
```

- **Logo watermark:** upload a transparent PNG as a normal file (e.g.
  `users/42/.config/logo.png`) and set `watermark_type => 'logo'` +
  `watermark_logo_path`. No DB — the logo is just a file; re-upload to change it.
  A missing/unsafe logo path **falls back to a text watermark** (with an
  `X-FluxFiles-Warning` header) — never to a clean image.
- The watermarked WebP is cached in `_variants/` (keyed by config + logo mtime),
  so a config or logo change produces a fresh result.

> **⚠️ A watermark only protects if the clean original isn't otherwise reachable.**
> By default `list()` returns a clean presigned `url` (and `variants`) for every
> file — a preview client could just use those and bypass the watermark. Set
> **`allow_download => false`** (preview-only): `list()` then withholds
> `url`/`permanent_url`/`variants` and GET presign returns `403`, leaving only the
> watermarked `img_base`. Issue a separate token with `allow_download => true`
> (e.g. after purchase) to grant the clean original. Watermark uses the bundled
> DejaVuSans font for text; like `/api/fm/img` it's a core-standalone feature.

### Usage dashboard

`GET /api/fm/usage` returns a storage breakdown for the token's prefix — quota
status, size/count by type, and the largest folders — computed **on the fly**
(no DB, no history). The UI ships a dashboard panel (a toolbar button) with a
quota meter, a by-type chart, and a clickable top-folders list.

```jsonc
// GET /api/fm/usage?disk=local
{
  "computed_at": "2026-06-20T10:00:00Z",
  "cache_age_seconds": 0,
  "quota": { "used_bytes": 4500000000, "limit_bytes": 5000000000, "percent": 90, "status": "critical" },
  "file_count": 142,
  "by_type":     [{ "type": "image", "size_bytes": 3000000000, "count": 120, "percent": 66.7 }],
  "top_folders": [{ "path": "/products", "size_bytes": 3200000000, "count": 100 }]
}
```

- **One pass**: the breakdown is computed in the same recursive listing the quota
  check already runs (by extension — no per-file MIME lookup); `_fluxfiles/` and
  `_variants/` are excluded. `status` is `ok` / `warning` (≥70%) / `critical` (≥90%),
  configurable via the `usage_*` claims.
- **Cache**: the result is cached per prefix in `_fluxfiles/usage.json` for
  `usage_cache_ttl` seconds (default 15 min; `0` disables it). `?refresh=true`
  recomputes (rate-limited to 2/min) — the UI's Refresh button is debounced 60s.
- **`quota_limit` lives in the JWT** ("token is the config"), so changing a
  tenant's quota only takes effect on their **next** issued token — by design.

### Uploading multiple files

Multi-file upload is always available in the file manager UI — no special option
needed:

- **Drag & drop** several files onto the dropzone at once, or
- Click **Upload** and select multiple files in the OS dialog (the file input is
  `multiple`).

Files upload **sequentially** (one `POST /api/fm/upload` per file) with a
combined progress bar; large files on S3/R2 automatically use multipart chunk
upload. Each file is independently checked for size, extension, quota, and
duplicates, so one rejected file doesn't abort the rest. After the batch the
listing refreshes and an `upload:done` `FM_EVENT` fires.

**Limiting how many files** — there's no built-in cap on the *number* of files
by default. Use:
- the **`max_files`** JWT claim (`maxFiles` token param) to cap the **total**
  files under the user's prefix — enforced server-side (`413 too_many_files`);
- the SDK/component **`maxFiles`** option to cap a single drop/selection batch
  client-side (a friendlier early rejection).

And **`max_upload`** (`maxUploadMb`, MB) caps the size of each individual file.

> Don't confuse this with the SDK's `multiple: true` option — that controls
> **picker selection** (returning an array of already-stored files via
> `FM_SELECT`), not uploading. The two are independent. Selecting several files
> and using the bulk bar also enables **bulk move / delete / download**.

### SDK Commands

Control the file manager programmatically after opening:

```js
FluxFiles.navigate('/photos/2024');       // Navigate to path
FluxFiles.setDisk('s3');                  // Switch disk
FluxFiles.refresh();                      // Reload current directory
FluxFiles.search('invoice');              // Trigger search
FluxFiles.crossCopy('s3', 'backups/');    // Copy selected file to another disk
FluxFiles.crossMove('r2', 'archive/');    // Move selected file to another disk
FluxFiles.aiTag();                        // AI-tag selected image
FluxFiles.setLocale('vi');                // Change language
FluxFiles.close();                        // Close file manager
FluxFiles.updateToken('eyJ...');          // Push new token (e.g. background refresh)
```

### SDK Events

```js
FluxFiles.on('FM_READY', (payload) => {
    console.log('Version:', payload.version);
    console.log('Capabilities:', payload.capabilities);
});

FluxFiles.on('FM_SELECT', (file) => {
    // Single file: { url, key, name, path, size, disk, meta, variants }
    // Multiple:    [{ url, key, ... }, ...]
});

FluxFiles.on('FM_EVENT', (event) => {
    // event.event: 'upload:done', 'delete:done', 'rename:done',
    //              'move:done', 'copy:done', 'folder:created',
    //              'crop:done', 'ai_tag:done'
    console.log(event.event, event.key);
});

FluxFiles.on('FM_CLOSE', () => {
    console.log('Closed');
});

// Token refresh events
FluxFiles.on('FM_TOKEN_REFRESH', (ctx) => {
    console.log('Token refresh requested:', ctx.reason);
});

// Unsubscribe
const unsub = FluxFiles.on('FM_SELECT', handler);
unsub(); // remove listener
```

### Token Refresh

FluxFiles automatically handles JWT expiration. When the API returns 401:

1. The iframe sends `FM_TOKEN_REFRESH` to the host app
2. The SDK calls your `onTokenRefresh` callback
3. You fetch a new JWT from your backend and return it
4. The SDK sends `FM_TOKEN_UPDATED` back to the iframe
5. The failed request is automatically retried with the new token

**Behavior details:**
- Multiple concurrent 401s are coalesced into a single refresh request
- After 2 consecutive refresh failures, the auth expired screen is shown
- 10-second timeout — if no response, falls back to expired screen
- `auth:refreshed` and `auth:expired` events are emitted via `FM_EVENT`

**Proactive refresh:** Call `FluxFiles.updateToken(newJwt)` to push a new token before it expires (e.g. on a timer).

### PHP Embed Helper

For server-rendered pages, use the PHP helper to generate the iframe HTML:

```php
// Composer: vendor/fluxfiles/fluxfiles/embed.php
// Monorepo clone: packages/core/embed.php
require_once 'path/to/fluxfiles/embed.php';

// Generate token
$token = fluxfiles_token(
    userId: (string) $currentUser->id,
    perms:  ['read', 'write', 'delete'],
    disks:  ['local', 'r2'],
    prefix: 'users/' . $currentUser->id . '/'
);

// Render inline embed
echo fluxfiles_embed(
    endpoint: 'https://fm.yourdomain.com',
    token:    $token,
    disk:     'local',
    mode:     'browser',
    width:    '100%',
    height:   '600px'
);
```

### TypeScript Support

TypeScript declarations are included in `fluxfiles.d.ts`:

```ts
import type { FluxFilesInstance, FluxFilesOpenOptions, FluxFile } from './fluxfiles';
```

---

## Storage Disks

### Configuration

Disks are defined in `config/disks.php`:

```php
return [
    // Local filesystem
    'local' => [
        'driver' => 'local',
        'root'   => __DIR__ . '/../storage/uploads',
        'url'    => '/storage/uploads',  // public URL prefix
    ],

    // AWS S3 (or any S3-compatible — set AWS_ENDPOINT for MinIO / DO Spaces)
    's3' => [
        'driver'   => 's3',
        'region'   => $_ENV['AWS_DEFAULT_REGION'],
        'bucket'   => $_ENV['AWS_BUCKET'],
        'key'      => $_ENV['AWS_ACCESS_KEY_ID'],
        'secret'   => $_ENV['AWS_SECRET_ACCESS_KEY'],
        'endpoint' => $_ENV['AWS_ENDPOINT'] ?? '',  // empty = native AWS S3
    ],

    // Cloudflare R2 (S3-compatible)
    'r2' => [
        'driver'   => 's3',
        'endpoint' => 'https://' . $_ENV['R2_ACCOUNT_ID'] . '.r2.cloudflarestorage.com',
        'region'   => 'auto',
        'bucket'   => $_ENV['R2_BUCKET'],
        'key'      => $_ENV['R2_ACCESS_KEY_ID'],
        'secret'   => $_ENV['R2_SECRET_ACCESS_KEY'],
    ],
];
```

> **Note:** R2 uses the S3-compatible API. ACL operations are not supported — FluxFiles automatically disables `retain_visibility` for endpoint-based disks.

### SFTP disk (VPS / shared hosting)

A 3rd disk driver (after local and S3/R2) that turns FluxFiles into a file
manager for an SFTP server — a VPS, shared host, or anything behind SSH. Set the
`SFTP_*` env vars (see [Environment Variables](#environment-variables)) and an
`sftp` disk is registered automatically; the tree UI is disk-agnostic, so listing,
upload, rename, move and delete just work.

```env
SFTP_HOST=vps.example.com
SFTP_USERNAME=deploy
SFTP_PASSWORD=…                       # password auth, OR key auth below
SFTP_PRIVATE_KEY=<PEM/OpenSSH key>    # RSA or ED25519 (key wins when both are set)
SFTP_PRIVATE_KEY_PASSPHRASE=…         # set if the key is passphrase-protected
SFTP_ROOT=/var/www
```

> **Key auth:** password OR private key (key wins when both are set). The key may be
> **RSA or ED25519**, in PEM or OpenSSH format, and **may be passphrase-protected** —
> set `SFTP_PRIVATE_KEY_PASSPHRASE` (phpseclib decrypts it at connect time). The same
> works for **BYOB** tokens: pass `private_key` + `private_key_passphrase` in the disk
> config (they're AES-256-GCM-encrypted into the JWT, never stored).

How it differs from S3 (by design):

- **Connect/disconnect per request** — no connection pool, no DB. Adds ~200–500 ms
  per action (SSH handshake); the UI shows a loading state.
- **No static or presigned URL** — every download/preview is **streamed through
  the app** via a tokened `/api/fm/stream` link (the same mechanism gated local
  media uses). So uploads/downloads use app-server bandwidth; **keep per-file size
  modest** with the token's `max_upload` claim (e.g. 100–200 MB). HTTP Range isn't
  advertised for SFTP (browse/edit, not media seeking).
- **Chunk upload and presign are rejected** for an SFTP disk (S3-only) — uploads
  go direct.
- **File permissions (chmod)**: SFTP has native Unix permissions (S3 doesn't), so
  the UI offers a cPanel-style permissions dialog (Owner/Group/World × rwx) on
  SFTP files. `GET /api/fm/chmod?disk=&path=` reads the octal mode; `POST` with
  `{disk, path, mode}` sets it (gated by the `allow_chmod` claim, default true).
- **SSRF-guarded**: the SFTP host must resolve to a public address, unless you
  list it in `FLUXFILES_SSRF_ALLOW_HOSTS` (legit when the box is on your own
  private network / a VPN'd VPS).
- **Per-tenant (BYOB)**: mint a token with the user's own SFTP credentials —
  `fluxfiles_byob_token($u, ['my-vps' => ['driver' => 'sftp', 'host' => …,
  'username' => …, 'password' => …]])`, or use `'private_key' => …` (RSA/ED25519)
  with an optional `'private_key_passphrase' => …`. Credentials are
  AES-256-GCM-encrypted into the JWT and SSRF-checked on decode, never stored.
- Like gated media and on-demand WebP, SFTP **serving is a core-standalone /
  Docker feature** (it streams bytes through the app); the Laravel/WordPress
  proxies don't expose it.

### Config / code editor

Edit a file's **text** content in place — the cPanel "Edit" use case for
`wp-config.php`, `.env`, `nginx.conf`, `deploy.sh`, etc. Works on **any** disk
(local / S3 / R2 / SFTP), so unlike chmod it **is** proxied by Laravel/WordPress.

- `GET /api/fm/content?disk=&path=` → `{ path, content, size }` (read perm;
  binary file → 415, file > 5 MB → 413).
- `PUT /api/fm/content {disk, path, content}` overwrites an **existing** file
  (write perm; missing file → 404, oversize → 413).
- In the UI, an **Edit** button appears in the file detail panel for text files,
  opening a CodeMirror editor (syntax-highlighted by extension; `Ctrl/⌘+S` saves).

> ⚠️ **`allow_code_edit` defaults to `false`.** Editing config/executable files
> is powerful — a token that can rewrite `wp-config.php`, `.htaccess`, or a
> deploy script is effectively code execution on that storage. Only mint
> `allow_code_edit => true` for **trusted admin** tokens, ideally narrowed with a
> `prefix` and/or an `ext` allowlist (the editor still honours `ext` — `allowed_ext`).
> The always-on dangerous-extension *upload* block is deliberately **not** applied
> to editing (that's the whole point), so the claim is the lock.

```php
// trusted admin token — can edit configs under this tenant's prefix only
$token = fluxfiles_token('admin-7', ['read', 'write'], ['sftp'], 'sites/acme/', 50, null, 3600,
    false, 0, 0, null, 0, 0, null, null, null, [
        'allow_code_edit' => true,
    ]);
```

### Zip / Extract

Download a multi-file/-folder selection as a zip, or extract a zip in place —
both synchronous (no queue/worker; that needs a DB, which FluxFiles doesn't have).

- **`POST /api/fm/zip`** `{disk, paths[], name?}` streams a `.zip` of the selected
  files **and folders** (recursive), constant-memory via ZipStream (each entry
  piped through Flysystem, so it works on local/S3/R2/SFTP). Needs read perm +
  `allow_download`; a pre-flight rejects (`413`) anything over `zip_max_mb` /
  `zip_max_files` **before** a byte is streamed. It streams binary through the app,
  so — like media `stream`/`img` — it's a **core-standalone / Docker** endpoint
  (the Laravel/WordPress proxies don't expose it).
- **`POST /api/fm/extract`** `{disk, path, dest?}` extracts a `.zip` in place
  (default dest: a folder named after the archive). Returns JSON, so it **is**
  proxied by Laravel/WordPress. Two-pass = atomic (validates every entry, then
  writes — a single bad entry aborts the whole extract).

> 🔒 **Extract is hardened against the classic archive attacks:** **zip-slip**
> (absolute / `..` / drive-letter entries rejected), **zip-bomb** (uncompressed
> size + entry-count caps, and the per-token storage **quota** on the total), and
> the always-on **dangerous-extension** block (a zip can't smuggle a `.php`/`.sh`)
> plus the token's `ext` allowlist. Nothing is written when any entry fails.

In the UI, a **Download ZIP** button appears in the selection toolbar (and on a
folder), and an **Extract** action on `.zip` files. Claims: `allow_zip` /
`allow_extract` (default true), `zip_max_mb` (1024), `zip_max_files` (10000).

### Adding a Custom Disk

Add a new entry to `config/disks.php`:

```php
'minio' => [
    'driver'   => 's3',
    'endpoint' => 'http://minio.local:9000',
    'region'   => 'us-east-1',
    'bucket'   => 'my-bucket',
    'key'      => 'minioadmin',
    'secret'   => 'minioadmin',
],
```

Then include `'minio'` in the JWT `disks` claim.

### S3-compatible providers (no extra code)

Any S3-compatible object store works **today** through the `s3` driver — just set
`endpoint` (and `region`). No new dependency or driver is needed; the same
chunked-upload / presign / visibility logic applies.

| Provider | `endpoint` example | Notes |
|----------|--------------------|-------|
| **DigitalOcean Spaces** | `https://nyc3.digitaloceanspaces.com` | `region` = the Space's region (e.g. `nyc3`) |
| **Backblaze B2** | `https://s3.us-west-004.backblazeb2.com` | Use the S3-compatible endpoint from the bucket page |
| **Wasabi** | `https://s3.us-east-1.wasabisys.com` | Region-specific endpoint |
| **MinIO (self-hosted)** | `http://minio.local:9000` | `region` can be any value (e.g. `us-east-1`) |
| **Cloudflare R2** | `https://<account>.r2.cloudflarestorage.com` | First-class — see above |

```php
'spaces' => [
    'driver'   => 's3',
    'endpoint' => 'https://nyc3.digitaloceanspaces.com',
    'region'   => 'nyc3',
    'bucket'   => $_ENV['DO_SPACES_BUCKET'],
    'key'      => $_ENV['DO_SPACES_KEY'],
    'secret'   => $_ENV['DO_SPACES_SECRET'],
],
```

> Like R2, endpoint-based disks have ACL operations disabled automatically
> (`retain_visibility` off). For native **Google Cloud Storage** or **Azure Blob**
> (non-S3 APIs), a dedicated Flysystem adapter + `DiskManager` driver would be
> needed — not currently bundled.

### BYOB (Bring Your Own Bucket)

Users can connect their own S3/R2 buckets. Credentials are AES-256-GCM encrypted inside the JWT (derived key via HKDF, separate from signing key):

```php
$token = fluxfiles_byob_token(
    userId:    'user-123',
    byobDisks: [
        'my-bucket' => [
            'driver'   => 's3',
            'region'   => 'us-west-2',
            'bucket'   => 'user-personal-bucket',
            'key'      => 'AKIAIOSFODNN7EXAMPLE',
            'secret'   => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ],
    ],
    perms: ['read', 'write', 'delete'],
    ttl:   1800  // SECONDS — shorter lifetime recommended for BYOB tokens (1800 = 30 min)
);
```

Security: BYOB only allows `s3` driver — `local` driver is blocked to prevent path traversal.

### Cross-Disk Operations

Copy or move files between any two disks (e.g., local to R2):

```bash
# API
POST /api/fm/cross-copy
{"src_disk":"local","src_path":"photo.jpg","dst_disk":"r2","dst_path":"backups/photo.jpg"}

# SDK
FluxFiles.crossCopy('r2', 'backups/');
FluxFiles.crossMove('s3', 'archive/');
```

Metadata and image variants are transferred together. Quota is checked on the destination disk.

---

## JWT Token Structure

```json
{
    "sub":          "user-123",
    "iat":          1710500000,
    "exp":          1710503600,     // Unix seconds
    "jti":          "a1b2c3d4e5f6",
    "perms":        ["read", "write", "delete"],
    "disks":        ["local", "s3", "r2"],
    "prefix":       "user-123/",
    "max_upload":   10,             // MB per file
    "allowed_ext":  ["jpg", "png", "pdf"],
    "max_storage":  1000,           // MB total (0 = unlimited)
    "byob_disks":   {}
}
```

| Claim | Type | Unit | Default | Description |
|-------|------|------|---------|-------------|
| `sub` | string | — | `"0"` | User identifier |
| `iat` | int | **Unix seconds** | now | Issued-at timestamp |
| `exp` | int | **Unix seconds** | now + ttl | Expiry timestamp (token invalid after this) |
| `perms` | string[] | — | `["read"]` | Permissions: `read`, `write`, `delete` |
| `disks` | string[] | — | `["local"]` | Allowed storage disks |
| `prefix` | string | path | `""` | Path scope — user can only access files under this prefix |
| `max_upload` | int | **MB** | `10` | Max size **per uploaded file**, in megabytes |
| `allowed_ext` | string[]&#124;null | extensions | `null` | Allowed extensions, lowercase & **no dot** (e.g. `["jpg","png"]`). `null` = allow all non-dangerous types |
| `max_storage` | int | **MB** | `0` | **Total** storage quota for the prefix, in megabytes. `0` = unlimited |
| `max_files` | int | count | `0` | **Total** number of files allowed under the prefix. `0` = unlimited |
| `owner_only` | bool | — | `false` | When `true`, users can only delete/rename/move files they uploaded |
| `byob_disks` | object | — | — | Encrypted BYOB credentials (optional) |
| `ai_auto_tag` | bool | — | inherit env | Per-tenant AI auto-tag toggle. Omit to inherit `FLUXFILES_AI_AUTO_TAG`; `true`/`false` overrides it |
| `rate_read` / `rate_write` | int | req/min | `0` | Per-tenant API rate limits. `0` = inherit the server default |
| `variants` | object | px | — | Per-tenant WebP variant widths, e.g. `{"thumb":150,"medium":768,"large":1920}`. Omit/unknown keys inherit the defaults |
| `allow_url_import` | bool | — | `false` | Enable **Import from URL** (`POST /api/fm/import-url`) for this tenant. Off by default so the server can't be abused as an HTTP proxy |
| `max_import_mb` | int | **MB** | `0` | Max size per URL import, in megabytes (same unit as `max_upload`). `0` = inherit the default (50) |
| `import_url_allowlist` | string[] | hosts | — | Restrict imports to these host globs, e.g. `["*.unsplash.com"]`. Omit = any public host |
| `import_path` | string | path | — | Force imports into this path, ignoring the request path |
| `import_rate_limit` / `import_concurrency` | int | — | `10` / `3` | Import-specific rate limit (its own bucket) and max concurrent imports |
| `media_preview` | bool | — | `true` | Inline video/audio preview. `false` falls back to a download link (image/pdf preview unaffected) |
| `preview_url_ttl` | int | **seconds** | `7200` | Presigned GET-URL TTL for **media** files — longer so a long video doesn't 403 mid-playback. Capped at 24h |
| `max_preview_mb` | int | **MB** | `500` | Max media size eligible for inline preview; larger files show a "too large" placeholder + download |
| `stream_token_ttl` | int | **seconds** | `3600` | TTL of the per-file stream token used by gated-local media (`FLUXFILES_LOCAL_PRIVATE`) |
| `webp_enabled` | bool | — | `true` | Expose the on-demand WebP endpoint (`/api/fm/img`) — image entries gain an `img_base` URL. `false` omits it |
| `webp_max_width` | int | px | `2000` | Max resize width a transform request may ask for (clamped). Bounds the cache-variant count |
| `webp_default_quality` | int | 1–100 | `80` | WebP quality used when a request omits `quality` (snapped to `60`/`75`/`80`/`90`) |
| `srcset_widths` | int[] | px | `[320,640,768,1024,1366,1920]` | Responsive `srcset` ladder. `list()` emits these as `img_srcset` on images (snapped to 100px, clamped to `webp_max_width`, capped at the source width) |
| `srcset_sizes` | string | — | _(unset)_ | When set, emitted as the `img_sizes` attribute to pair with `img_srcset` (e.g. `"(max-width: 600px) 100vw, 50vw"`) |
| `allow_download` | bool | — | `true` | When `false` (preview-only), `list()` withholds `url`/`permanent_url`/`variants` for files and GET presign returns `403` — only the (watermarked) `img_base` remains for images |
| `allow_chmod` | bool | — | `true` | Allow changing Unix file permissions (`POST /api/fm/chmod`) on an SFTP disk. `false` makes the SFTP token read-only for permissions |
| `allow_zip` | bool | — | `true` | Allow zip download of a selection (`POST /api/fm/zip`). Also requires `allow_download`. `false` hides the Download ZIP action |
| `allow_extract` | bool | — | `true` | Allow extracting a zip in place (`POST /api/fm/extract`). `false` hides the Extract action |
| `zip_max_mb` | int | **MB** | `1024` | Max total uncompressed size for a zip/extract (pre-flight 413 / bomb cap) |
| `zip_max_files` | int | — | `10000` | Max file count for a zip/extract |
| `watermark_enabled` | bool | — | `false` | Overlay a watermark on the on-demand WebP (`/api/fm/img`). Off by default; the source file is never modified |
| `watermark_type` | enum | — | `text` | `text` or `logo` (a PNG path in storage) |
| `watermark_text` | string | — | — | Text for `type=text` (e.g. `© Acme`) |
| `watermark_logo_path` | string | path | — | Storage path to the logo PNG for `type=logo`; a missing/unsafe path falls back to a text watermark (never a clean image) |
| `watermark_position` | enum | — | `bottom-right` | `top-left` / `top-right` / `bottom-left` / `bottom-right` / `center` |
| `watermark_opacity` | float | 0–1 | `0.6` | Watermark opacity |
| `watermark_font_size` | int | px | `24` | Font size for `type=text` (8–200) |
| `usage_cache_ttl` | int | **seconds** | `900` | Usage-dashboard cache TTL (`GET /api/fm/usage`). `0` disables the cache |
| `usage_warning_threshold` / `usage_critical_threshold` | int | % | `70` / `90` | Quota % at which the usage status turns `warning` / `critical` |
| `usage_top_folders_count` | int | — | `10` | How many largest folders the dashboard returns |
| `usage_folder_depth` | int | — | `1` | Folder grouping depth for the breakdown (`1` = top-level folders) |

> **Import from URL** fetches a public URL server-side and saves it like an upload
> (`POST /api/fm/import-url` with `{ "url": "…", "path": "…" }`). It's SSRF-guarded
> (blocks private/metadata/CGNAT/IPv6 targets on every redirect hop, magic-byte MIME
> deny-list, streaming size cap) and shares the existing quota/dedup/variants/AI-tag
> pipeline. SVG import is off unless `FLUXFILES_IMPORT_ALLOW_SVG=true`.

> `ttl` is **not** a claim — it's the `fluxfiles_token()` parameter (in **seconds**)
> used to compute `exp` = `iat + ttl`.

### Token parameters & units

`fluxfiles_token()` parameters, with exact units:

| Parameter | Maps to claim | Unit | Default | Notes |
|-----------|---------------|------|---------|-------|
| `userId` | `sub` | — | required | Your app's user id (string). |
| `perms` | `perms` | — | `['read']` | `read` (list/download/search), `write` (upload/rename/move/copy/mkdir/crop/ai-tag), `delete`. |
| `disks` | `disks` | — | `['local']` | Disk names the token may use. |
| `prefix` | `prefix` | path | `''` | Every path is sandboxed under this. `''` = full disk. |
| `maxUploadMb` | `max_upload` | **MB** | `10` | Max size **per file**. A 25 MB file with `maxUploadMb: 10` → 413. |
| `allowedExt` | `allowed_ext` | extensions | `null` | Lowercase, no dot, e.g. `['jpg','png','pdf']`. `null` = all non-dangerous types. Dangerous types (`php`, `exe`, …) are **always** blocked regardless. A disallowed type → **403 `ext_not_allowed`**. |
| `ttl` | `exp` (`= iat + ttl`) | **seconds** | `3600` | Token lifetime. `3600` = 1 hour, `86400` = 1 day. After `exp` the API returns 401 and the SDK triggers token refresh. |
| `ownerOnly` | `owner_only` | — | `false` | Restrict destructive ops to the uploader (use with a shared `prefix`). |
| `maxStorageMb` | `max_storage` | **MB** | `0` | **Total** quota across the prefix (existing files + variants + metadata count). `0` = unlimited. Exceeding it → **413 `quota_exceeded`**. |
| `maxFiles` | `max_files` | count | `0` | **Total** number of files allowed under the prefix (counts user files; skips internal `_fluxfiles/`/`_variants/`). `0` = unlimited. Exceeding it → **413 `too_many_files`**. |
| `aiAutoTag` | `ai_auto_tag` | — | `null` | Per-tenant AI auto-tag on upload. `null` = inherit `FLUXFILES_AI_AUTO_TAG`; `true`/`false` overrides it. (The AI provider/key stay server-side.) |
| `rateRead` / `rateWrite` | `rate_read` / `rate_write` | req/min | `0` | Per-tenant rate limits. `0` = inherit `FLUXFILES_RATE_LIMIT_READ/WRITE`. |
| `variants` | `variants` | px | `null` | Per-tenant WebP variant widths — a map of `thumb`/`medium`/`large` to a width (16–8000 px). Unset names inherit `150`/`768`/`1920`. |
| `import` | `allow_url_import`, … | — | `null` | **Import from URL** config (off unless set). An array: `['allow_url_import' => true, 'max_import_mb' => 20, 'import_url_allowlist' => ['*.unsplash.com'], 'import_path' => 'imports', 'import_rate_limit' => 10, 'import_concurrency' => 3]`. Only the keys you set are embedded; the rest inherit the server defaults. See [Import from URL](#import-from-url). |
| `media` | `media_preview`, … | — | `null` | **Media-preview** config. An array: `['media_preview' => true, 'preview_url_ttl' => 7200, 'max_preview_mb' => 500, 'stream_token_ttl' => 3600]`. Only the keys you set are embedded; the rest inherit the defaults. |
| `webp` | `webp_enabled`, … | — | `null` | **On-demand WebP** + responsive config. An array: `['webp_enabled' => true, 'webp_max_width' => 2000, 'webp_default_quality' => 80, 'srcset_widths' => [320, 640, 1024, 1920], 'srcset_sizes' => '100vw']`. Only the keys you set are embedded. See [On-demand WebP](#on-demand-webp). |
| `usage` | `usage_cache_ttl`, … | — | `null` | **Usage dashboard** config. An array: `['usage_cache_ttl' => 900, 'usage_warning_threshold' => 70, 'usage_critical_threshold' => 90, 'usage_top_folders_count' => 10, 'usage_folder_depth' => 1]`. See [Usage dashboard](#usage-dashboard). |

> **Quick reference:** sizes are **MB** (`maxUploadMb`, `maxStorageMb`), time is
> **seconds** (`ttl`), and `allowedExt` entries are **bare lowercase extensions**
> with no leading dot.

### Import from URL

`POST /api/fm/import-url` fetches a **public** URL server-side and saves it like a
normal upload (sharing the quota / dedup / variants / AI-tag pipeline). It is
**off by default** — a token must carry `allow_url_import` to use it, so the
server can never be abused as an open HTTP proxy. There is **nothing to install
or configure server-side** to enable it per tenant: like every other limit, it
lives in the JWT. Enabling it is the **only** step — just set the import claims
when you mint the token.

```php
// Core (standalone) — the 15th param is an array of import claims
$token = fluxfiles_token(
    'user-42', ['read', 'write'], ['local'], 'users/42', 10, null, 3600, false, 0, 0,
    null, 0, 0, null,
    [
        'allow_url_import'     => true,          // required — enables the feature
        'max_import_mb'        => 20,            // optional — cap per import (MB)
        'import_url_allowlist' => ['*.unsplash.com', 'cdn.example.com'], // optional
        'import_path'          => 'imports',     // optional — force a destination
        'import_rate_limit'    => 10,            // optional — imports/min
        'import_concurrency'   => 3,             // optional — max concurrent
    ]
);
```

```php
// Laravel — FluxFiles facade / FluxFilesManager::token()
$token = FluxFiles::token($user, [
    'allow_url_import'     => true,
    'max_import_mb'        => 20,
    'import_url_allowlist' => ['*.unsplash.com'],
]);
```

```php
// WordPress — FluxFilesPlugin::generateToken() / tokenForCurrentUser()
$token = FluxFilesPlugin::tokenForCurrentUser([
    'allow_url_import' => true,
    'max_import_mb'    => 20,
]);
```

```ts
// Node — @fluxfiles/node (camelCase options)
import { createToken } from '@fluxfiles/node';

const token = createToken({
  userId: 'user-42',
  perms: ['read', 'write'],
  allowUrlImport: true,
  maxImportMb: 20,
  importUrlAllowlist: ['*.unsplash.com'],
  // importPath, importRateLimit, importConcurrency also supported
});
```

Once the token allows it, call the route (the React/Vue/iframe SDKs proxy it for you):

```bash
curl -X POST "$ENDPOINT/api/fm/import-url" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://images.unsplash.com/photo-123.jpg","path":"imports"}'
```

**Server-side defaults** (apply when a token omits the matching claim) are
optional env vars — see [`.env.example`](.env.example):

| Env var | Default | Purpose |
|---------|---------|---------|
| `FLUXFILES_IMPORT_MAX_MB` | `50` | Max MB per import when the token omits `max_import_mb` |
| `FLUXFILES_IMPORT_RATE_LIMIT` | `10` | Imports/min when the token omits `import_rate_limit` |
| `FLUXFILES_IMPORT_TIMEOUT` | `30` | Seconds per import fetch |
| `FLUXFILES_IMPORT_ALLOW_SVG` | `false` | SVG import is off by default (XML can carry script) |

> **Security:** every redirect hop is SSRF-guarded (private/loopback/link-local/
> CGNAT/metadata/IPv6 targets are rejected, including decimal/hex-obfuscated IPs),
> the response is MIME-checked by magic bytes against a deny-list, and the body is
> streamed with a hard size cap. `import_url_allowlist` further restricts which
> hosts may be fetched.

### Permissions explained

| Permission | Allows |
|-----------|--------|
| `read` | List files, view metadata, search, download (presign), get quota |
| `write` | Upload, rename, copy, move, mkdir, save metadata, crop, AI-tag |
| `delete` | Delete files and directories |

### Path prefix

The `prefix` claim isolates users to their own directory:

```
prefix: "users/42/"
→ User can only access: users/42/*, users/42/photos/*, etc.
→ Path traversal (../) is stripped before prefix is applied
→ Null bytes are removed
```

### User isolation

FluxFiles provides two layers of user isolation that can be used independently or combined:

**Layer 1: Path prefix (recommended)** — Each user gets a unique `prefix` so they physically cannot see or touch other users' files:

```php
$token = fluxfiles_token(
    userId:   $user->id,
    perms:    ['read', 'write', 'delete'],
    prefix:   'users/' . $user->id . '/',  // user 42 → users/42/*
);
```

**Layer 2: Owner-only mode** — When multiple users share the same prefix (e.g., a shared team folder), `owner_only` restricts destructive operations (delete, rename, move, crop) to the user who uploaded the file:

```php
$token = fluxfiles_token(
    userId:    $user->id,
    perms:     ['read', 'write', 'delete'],
    prefix:    'team/shared/',
    ownerOnly: true,  // can only delete/rename own files
);
```

| Scenario | Use |
|----------|-----|
| Each user has their own space | `prefix: 'users/{id}/'` |
| Shared folder, users can only manage own files | `prefix: 'shared/'` + `owner_only: true` |
| Admin with full access | `prefix: ''` (no prefix, no owner_only) |
| Shared folder, everyone can manage all files | `prefix: 'shared/'` (no owner_only) |

> **Warning:** `owner_only` is a safety layer, NOT a replacement for `prefix` isolation. Always use `prefix` to scope users to their own directory. `owner_only` only protects against delete/rename/move — it does NOT prevent users from reading or downloading each other's files.

> **Note:** Files uploaded before `owner_only` was enabled lack ownership metadata and will be accessible to all users unless you import them with owner assignment or readonly mode.

### Import Existing Files

If a disk or bucket already contains files before FluxFiles is installed, run the indexer so existing content becomes searchable and folder search works. The default mode is **index-only**: it writes FluxFiles index files under `_fluxfiles/` but does not modify source files/objects.

```bash
cd packages/core
bin/fluxfiles index --disk=local --path=users/42 --dry-run
bin/fluxfiles index --disk=local --path=users/42
```

Useful options:

| Option | Effect |
|--------|--------|
| `--owner=user-123` | Persist ownership metadata so `owner_only` can protect imported files |
| `--readonly` | Persist an internal owner that no normal user matches |
| `--hash` | Compute SHA-256 hashes for duplicate detection |
| `--variants` | Generate WebP image variants during indexing |
| `--overwrite` | Re-index files that already have index entries |
| `--persist-metadata` | Write local sidecars / S3 object metadata instead of index-only |

Laravel proxy mode also exposes the same workflow:

```bash
php artisan fluxfiles:seed --disk=local --path=users/42 --dry-run
php artisan fluxfiles:seed --disk=local --path=users/42 --owner=user-123
```

---

## Multi-tenant

FluxFiles is **stateless** — there is no per-tenant configuration stored on the
server. Instead, **each token _is_ the configuration for that tenant.** Your
backend mints a short-lived JWT per customer, and the
[claims](#jwt-token-structure) are enforced server-side on every request. So each
tenant gets its own storage location, limits and permissions **with no config
files and no restarts** — you just issue a different token.

| What you set per tenant | Claim |
|-------------------------|-------|
| Where their files live (their own sub-folder) | `prefix` |
| Which storage they use — or **their own bucket** | `disks` / `byob_disks` |
| Max size of each uploaded file | `max_upload` |
| Total storage quota | `max_storage` |
| Max number of files | `max_files` |
| Allowed file types | `allowed_ext` |
| What they're allowed to do | `perms`, `owner_only` |
| Whether AI auto-tags their uploads | `ai_auto_tag` |
| Their API rate limits | `rate_read` / `rate_write` |
| Their image variant sizes | `variants` |

Drive the numbers from your plans / database — e.g. a free tier vs. a pro tier:

```php
// Free tier — 5 MB/file, images only, 500 MB quota, 200 files
$token = fluxfiles_token(
    userId:       "tenant_{$tenant->id}",
    perms:        ['read', 'write', 'delete'],
    disks:        ['local'],
    prefix:       "tenant_{$tenant->id}/",
    maxUploadMb:  5,
    allowedExt:   ['jpg', 'jpeg', 'png', 'webp'],
    ttl:          3600,
    maxStorageMb: 500,
    maxFiles:     200,
);

// Pro tier — 100 MB/file, any safe type, 50 GB quota, unlimited files
$token = fluxfiles_token(
    userId:       "tenant_{$tenant->id}",
    perms:        ['read', 'write', 'delete', 'audit'],
    disks:        ['s3'],
    prefix:       "tenant_{$tenant->id}/",
    maxUploadMb:  100,
    allowedExt:   null,            // all non-dangerous types
    ttl:          3600,
    maxStorageMb: 51200,           // 50 GB
    maxFiles:     0,               // unlimited
);
```

> Issuing tokens from a JS backend instead? `@fluxfiles/node`'s `createToken()`
> takes the same claims. The Laravel adapter wraps this as
> `FluxFiles::tokenForUser([...])` — see its README's *Per-tenant configuration*.

**Bring Your Own Bucket (BYOB)** is the strongest isolation: give each tenant
their own S3/R2 bucket. The credentials are AES-256-GCM encrypted **inside** the
token (`byob_disks`) and decrypted only at runtime, so you never store the
tenant's data or their keys. See [Storage Disks](#storage-disks).

> **Still global (not per-token):** the AI provider + API key (security — keys
> stay server-side; only the on/off `ai_auto_tag` is per tenant) and the
> always-blocked dangerous-extension list. Everything else above — including
> image variant sizes, AI auto-tag and rate limits — is now per tenant.

---

## API Reference

Base path: `/api/fm/`

All responses follow the format: `{ "data": { ... }, "error": null }`
On error: `{ "data": null, "error": "Error message" }` with appropriate HTTP status.

### Public Endpoints (no auth)

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/fm/lang` | List available locales → `[{code, name, dir}]` |
| `GET` | `/api/fm/lang/{code}` | Get translation messages for a locale |

### File Operations (JWT required)

| Method | Path | Body / Params | Description |
|--------|------|---------------|-------------|
| `GET` | `/list?disk=&path=` | — | List directory contents |
| `POST` | `/upload` | `multipart: disk, path, file, force_upload?` | Upload file |
| `DELETE` | `/delete` | `{disk, path}` | **Permanently** delete a file or directory (recursive) |
| `POST` | `/trash` | `{disk, path}` | Soft-delete a **file or folder** to trash (move-based, restorable; folders move the whole subtree) |
| `POST` | `/trash/restore` | `{disk, trash_id, path?}` | Restore a trashed file/folder (→ 409 if the target is occupied) |
| `GET` | `/trash/list?disk=` | — | List trash entries (scoped to the token's prefix/owner) |
| `POST` | `/trash/purge` | `{disk, trash_id}` | Permanently delete one trash item |
| `POST` | `/trash/empty` | `{disk}` | Permanently delete all visible trash items |
| `POST` | `/rename` | `{disk, path, name}` | Rename file or directory (a file's extension is fixed — base name only) |
| `POST` | `/move` | `{disk, from, to}` | Move within same disk (file extension must not change; `allowedExt` enforced) |
| `POST` | `/copy` | `{disk, from, to}` | Copy within same disk (file extension must not change; `allowedExt` enforced) |
| `POST` | `/mkdir` | `{disk, path}` | Create directory |
| `POST` | `/cross-copy` | `{src_disk, src_path, dst_disk, dst_path}` | Copy between disks (file extension must not change; `allowedExt` enforced) |
| `POST` | `/cross-move` | `{src_disk, src_path, dst_disk, dst_path}` | Move between disks (file extension must not change; `allowedExt` enforced) |
| `POST` | `/presign` | `{disk, path, method, ttl, size?}` | Generate presigned URL (GET or PUT, max 86400s). `size` is required for PUT. |
| `POST` | `/crop` | `{disk, path, x, y, width, height, save_path?}` | Crop image |
| `POST` | `/ai-tag` | `{disk, path}` | AI-analyze image (requires AI config) |
| `POST` | `/import-url` | `{disk, path, url, filename?}` | Server-side fetch a URL into storage (opt-in via `allow_url_import`; SSRF-guarded) |

### Archives, editor & permissions

| Method | Path | Body / Params | Description |
|--------|------|---------------|-------------|
| `POST` | `/zip` | `{disk, paths[], name?}` | Stream a **zip** of the selected files/folders (read + `allow_download` + `allow_zip`; pre-flight size caps). Core-standalone (unproxied) |
| `POST` | `/extract` | `{disk, path, dest?}` | **Extract** a zip in place — atomic, zip-slip / zip-bomb / quota / dangerous-ext guarded (`allow_extract`) |
| `GET` | `/content?disk=&path=` | — | Read a text file's content (read perm; binary → 415, > 5 MB → 413) |
| `PUT` | `/content` | `{disk, path, content}` | Overwrite a text/config file (`allow_code_edit`, default **false**; existing files only) |
| `GET` | `/chmod?disk=&path=` | — | Read an SFTP file's octal mode (SFTP disks only) |
| `POST` | `/chmod` | `{disk, path, mode}` | Set an SFTP file's mode (write + `allow_chmod`) |

> **Tokened media endpoints** (query-string token, no `Authorization` header — for `<img>`/`<video>`): `GET /img?token=&width=&quality=&format=` (on-demand WebP, see [On-demand WebP](#on-demand-webp)) and `GET /stream?token=` (gated private media). Both are core-standalone / Docker features.

### Metadata

| Method | Path | Body / Params | Description |
|--------|------|---------------|-------------|
| `GET` | `/meta?disk=&path=` | — | File info: size, mime, modified |
| `GET` | `/metadata?disk=&key=` | — | SEO metadata: title, alt_text, caption, tags |
| `PUT` | `/metadata` | `{disk, key, title, alt_text, caption, tags}` | Save metadata |
| `DELETE` | `/metadata` | `{disk, key}` | Delete metadata |

### Search, Quota, Audit

| Method | Path | Params | Description |
|--------|------|--------|-------------|
| `GET` | `/search?disk=&q=&limit=` | `limit` default 50 | Full-text search across file names + metadata |
| `GET` | `/search-folders?disk=&q=&limit=` | `limit` default 50 | Search folder names via the directory index |
| `GET` | `/quota?disk=` | — | Storage usage: used_mb, max_mb, percentage |
| `GET` | `/usage?disk=&refresh=` | — | **Usage dashboard** — quota + per-type and per-folder breakdown (file-cached; `refresh=true` recomputes, tight bucket) |
| `GET` | `/audit?limit=&offset=&action=&from=&to=&path=&actor=` | `limit` default 100 | Activity log, **scoped to the token's prefix**. Requires the `audit` permission (403 otherwise). |
| `GET` | `/disk/doctor?disk=&origin=` | — | **Bucket Doctor** — diagnose an S3/R2 disk (credentials, read/write/delete, presign, CORS, multipart, versioning) and return a report + IAM/CORS remediation. Requires `write`. |

### Chunk Upload (S3 multipart, files > 10MB)

| Method | Path | Body | Description |
|--------|------|------|-------------|
| `POST` | `/chunk/init` | `{disk, path, size}` | Initiate → `{upload_id, key, chunk_size}` |
| `POST` | `/chunk/presign` | `{disk, key, upload_id, part_number}` | Presign URL for part |
| `POST` | `/chunk/complete` | `{disk, key, upload_id, parts}` | Complete upload |
| `POST` | `/chunk/abort` | `{disk, key, upload_id}` | Abort upload |

### Upload Response Example

```json
{
    "data": {
        "key": "users/42/photo.jpg",
        "url": "https://bucket.r2.cloudflarestorage.com/photo.jpg",
        "name": "photo.jpg",
        "size": 245760,
        "variants": {
            "thumb":  { "url": "...", "key": "..._thumb.webp",  "width": 150, "height": 100 },
            "medium": { "url": "...", "key": "..._medium.webp", "width": 768, "height": 512 },
            "large":  { "url": "...", "key": "..._large.webp",  "width": 1920, "height": 1280 }
        },
        "ai_tags": {
            "tags": ["landscape", "mountain", "sunset"],
            "title": "Mountain sunset landscape",
            "alt_text": "A mountain range silhouetted against an orange sunset sky",
            "caption": "Beautiful sunset over mountain peaks with warm orange and purple tones."
        }
    },
    "error": null
}
```

### Duplicate Detection

If a file with the same SHA-256 hash exists, upload returns:

```json
{
    "data": {
        "key": "existing/path/photo.jpg",
        "url": "...",
        "duplicate": true,
        "message": "File already exists. Use force_upload to override."
    }
}
```

Send `force_upload=true` (in form data) to upload anyway.

---

## Framework Adapters

FluxFiles ships an official adapter for every major stack. **Each has its own
README** with full examples, props/options, and build steps — this section is the
install + the gist; follow the package link for details.

| Adapter | Install | What it does | Docs |
|---|---|---|---|
| **Node** (token SDK) | `npm i @fluxfiles/node` | Mint JWTs (+ encrypted BYOB) from any Node backend — byte-compatible with the PHP core, zero deps | [`packages/node`](packages/node) |
| **Laravel** | `composer require fluxfiles/laravel` | `<x-fluxfiles>` Blade component + `FluxFiles::token()` facade + publishable config + route proxy | [`packages/laravel`](packages/laravel) |
| **WordPress** | release ZIP (bundles `vendor/`) | Plugin: Settings page, `[fluxfiles]` shortcode, Classic-editor media button, REST API at `/wp-json/fluxfiles/v1/` | [`packages/wordpress`](packages/wordpress) |
| **React** | `npm i @fluxfiles/react` | `<FluxFiles>` / `<FluxFilesModal>` + `useFluxFiles()` hook (TypeScript) | [`packages/react`](packages/react) |
| **Vue 3 / Nuxt 3** | `npm i @fluxfiles/vue` | `<FluxFiles>` / `<FluxFilesModal>` + composable; Nuxt auto-import plugin | [`packages/vue`](packages/vue) |
| **CKEditor 4** | `npm i @fluxfiles/ckeditor4` | Toolbar button + native Image-Properties "Browse" | [`packages/ckeditor4`](packages/ckeditor4) |
| **TinyMCE 4/5** | `npm i @fluxfiles/tinymce` | Toolbar button + native image-dialog file picker | [`packages/tinymce`](packages/tinymce) |
| **Summernote** | `npm i @fluxfiles/summernote` | Toolbar button (inserts `<img>`/`<a>` at the cursor) | [`packages/summernote`](packages/summernote) |

The **token-minting** adapters (Node, Laravel, WordPress) emit the **same JWT** as
the PHP `fluxfiles_token()` helper — identical claims + BYOB encryption. The
**browser** adapters (React, Vue, the editor plugins) embed the core UI over the
same iframe + `postMessage` SDK, so anything the standalone UI does, they do too.

**Minimal example** — mint a scoped token server-side, then embed/pick:

```php
// Laravel controller
$token = FluxFiles\Laravel\FluxFilesFacade::token(
    userId: (string) auth()->id(), perms: ['read', 'write'],
    disks: ['local', 's3'], prefix: 'users/'.auth()->id().'/'
);
```
```blade
<x-fluxfiles disk="s3" mode="browser" height="600px" />   {{-- Blade --}}
```
```tsx
// React (same token)
<FluxFiles endpoint="https://fm.example.com" token={token} disk="s3"
           onSelect={(file) => console.log(file.url)} style={{ height: 600 }} />
```

---

## Internationalization

16 languages built in. Translation files in `lang/*.json`.

| Code | Language | Dir | | Code | Language | Dir |
|------|----------|-----|-|------|----------|-----|
| `en` | English | LTR | | `pt` | Portugues | LTR |
| `vi` | Tieng Viet | LTR | | `it` | Italiano | LTR |
| `zh` | Chinese | LTR | | `ru` | Русский | LTR |
| `ja` | Japanese | LTR | | `th` | ไทย | LTR |
| `ko` | Korean | LTR | | `hi` | हिन्दी | LTR |
| `fr` | Francais | LTR | | `tr` | Turkce | LTR |
| `de` | Deutsch | LTR | | `nl` | Nederlands | LTR |
| `es` | Espanol | LTR | | `ar` | Arabic | RTL |

**Locale priority:** SDK `locale` option > URL param (`?locale=` or `?lang=`) > `FLUXFILES_LOCALE` env > `en`

**Default is English.** No auto-detection from browser. To use a different language, set it explicitly.

**Set locale via URL (standalone mode):**

```
/public/index.html?token=...&locale=vi
/public/index.html?token=...&lang=ja
```

**Set locale via SDK:**

```js
FluxFiles.open({ locale: 'vi', ... });
// or change at runtime:
FluxFiles.setLocale('ja');
```

**Set locale server-wide (env):**

```env
FLUXFILES_LOCALE=vi
```

**Add a new language:** See [`packages/core/lang/CONTRIBUTING.md`](packages/core/lang/CONTRIBUTING.md) — copy `packages/core/lang/en.json`, translate, submit PR.

---

## Security

### Built-in Protections

| Protection | How |
|-----------|-----|
| **JWT HS256** | Algorithm pinned — prevents algorithm confusion attacks |
| **CORS whitelist** | Only configured origins receive `Access-Control-Allow-Origin` |
| **Origin validation** | POST/PUT/DELETE requests are rejected if Origin header doesn't match whitelist |
| **postMessage origin** | SDK and iframe validate `e.origin` to prevent cross-origin message injection |
| **Path traversal** | `..` and `.` segments stripped, null bytes removed, paths normalized before use |
| **Extension blocking** | Dangerous extensions (php, exe, sh, bat, etc.) blocked even in double-extension filenames (e.g. `shell.php.jpg`) |
| **Extension immutability** | A file's extension is fixed at upload. Rename/move/copy/cross-disk **cannot change it** (→ `400 ext_changed`), and move/copy re-check the `allowedExt` policy (→ `403 ext_not_allowed`) — so a scoped token can't relocate a file out of its allowed types. Folders are unaffected. |
| **Path scoping** | Users confined to their `prefix` directory — cannot access files outside scope |
| **Owner-only mode** | `owner_only` JWT claim restricts delete/rename/move to files the user uploaded |
| **System path protection** | `_fluxfiles/` and `_variants/` directories blocked from list/delete/rename/move, hidden in file listing, **and excluded from file/folder search results** |
| **Disk whitelist** | Per-token disk access — users can only access disks listed in JWT |
| **Permission model** | Granular `read`, `write`, `delete` checked on every operation |
| **BYOB encryption** | AES-256-GCM with HKDF-derived key (separate from signing key) |
| **BYOB local blocked** | BYOB tokens cannot use `local` driver — only S3-compatible storage |
| **BYOB SSRF guard** | BYOB endpoints must be http(s) and are rejected if they resolve to loopback, link-local, private/reserved ranges, or the cloud metadata IP (`169.254.169.254`) |
| **Rate limiting** | Token bucket per user, file-locked, configurable (default: 60 read, 10 write/min) |
| **Quota enforcement** | Per-user storage limits checked before upload and cross-disk copy |
| **Duplicate detection** | SHA-256 hash prevents redundant uploads |
| **Audit trail** | All write actions logged with user ID, action, IP, user agent. Rotation at 5MB. |
| **Presign validation** | Method restricted to GET/PUT only, TTL capped at 86400 seconds |
| **Gated media stream** | `/api/fm/stream` reads disk/path from the **signed** token (never the query), `realpath`-contains the file in the disk root (symlink/traversal guard), serves only `local` `private` disks, and forces non-inline types to download with `nosniff` |
| **Error handling** | Generic errors to client, detailed errors to server log only |
| **Search XSS** | HTML entities escaped before highlight `<mark>` tags applied |

### Token handling

The JWT is a **bearer credential** — anyone holding it has the access it grants.
How you deliver it to the file manager matters:

- **Embedding (recommended):** the SDK and framework wrappers pass the token over
  `postMessage` (`FM_CONFIG`), so it never appears in a URL. Use this for production.
- **Standalone mode** opens `/public/index.html?token=<JWT>`. A token in the URL
  can leak through **browser history, server access logs, and the `Referer`
  header** of any outbound request the page makes. Treat standalone URLs as
  sensitive and prefer it for local dev / short-lived links only.

Mitigations regardless of delivery:

- **Keep TTLs short** — `ttl` is in seconds; issue the smallest lifetime that
  fits the session (e.g. `900`–`3600`). Expired tokens return `401` and the SDK
  triggers your `onTokenRefresh` to mint a fresh one.
- **Scope tightly** — set `prefix`, the minimum `perms`, and `disks` so a leaked
  token grants as little as possible.
- **Serve over HTTPS** so tokens aren't exposed on the wire.
- Don't log full request URLs (which may contain `?token=`) at your proxy.

> **Gated media stream tokens** (`FLUXFILES_LOCAL_PRIVATE=true`): an `<video>`/
> `<audio>` element can't send an `Authorization` header, so the per-file
> `/api/fm/stream` token rides the **query string** — and like any URL token, it
> can leak via access logs / `Referer`. It is deliberately **scoped to a single
> file** with a short TTL (`stream_token_ttl`, default 1h), so a leak exposes only
> that one file, briefly. Lower `stream_token_ttl` for a tighter window, serve over
> HTTPS, and avoid logging full URLs. (This is **not** rate-limited per request:
> HTTP Range seeking fires many requests per playback, so a per-request limit would
> throttle legitimate seeking — the single-file scope + short TTL is the control.)

### Production Checklist

- [ ] Set `FLUXFILES_SECRET` to a cryptographically random string (min 32 chars)
- [ ] Set `FLUXFILES_ALLOWED_ORIGINS` to your production domain(s)
- [ ] Use HTTPS everywhere
- [ ] Deliver tokens via the SDK/`postMessage` (not `?token=` URLs) in production; keep `ttl` short
- [ ] Block public access to `.env`, `vendor/`, `storage/rate_limit.json`
- [ ] Set `storage/` directory permissions to 755, `.env` to 600
- [ ] Never commit `.env` with real credentials to git
- [ ] Review and rotate API keys periodically

---

## Testing

> First: `composer install -d packages/core` — the PHP tests load
> `packages/core/vendor/autoload.php`.

```bash
# Core PHP — unit + integration suite
for f in packages/core/tests/unit/*.php packages/core/tests/integration/*.php; do php "$f"; done

# API e2e (boots a dev server) + env-gated live S3/R2 (MinIO/AWS/R2)
cd packages/core && php -S 127.0.0.1:8080 router.php &
bash packages/core/tests/e2e/test-api.sh

# Browser e2e (Playwright drives the real UI in chromium)
cd packages/core/tests/browser && npm install && npx playwright install chromium && npm test

# Framework wrappers — vitest for JS adapters, stubbed-PHP smokes for PHP adapters
cd packages/react && npm install && npm test        # (and sdk / vue / ckeditor4 / tinymce / summernote)
php packages/laravel/tests/test-laravel-smoke.php   # (and wordpress)
bash scripts/pack-smoke.sh all                       # verifies the published dist/types

# Docker — clean-container runs across a PHP matrix
make test PHP=8.4   # one version  ·  make test-all  # 8.1–8.4  ·  make up  # app:8080 + MinIO:9000
```

`.github/workflows/test.yml` runs all of this (10 jobs). For the CI map, the
adapter↔core floor guard, and the tag→registry release flow, see
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

---

## Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `FLUXFILES_SECRET` | **Yes** | — | JWT signing secret (min 32 chars) |
| `FLUXFILES_ALLOWED_ORIGINS` | **Yes** | — | Comma-separated CORS origins |
| `FLUXFILES_LOCALE` | No | `en` | UI language (`en`, `vi`, `zh`, `ja`, etc.) |
| `FLUXFILES_RATE_LIMIT_READ` | No | `60` | Max read requests per minute per user |
| `FLUXFILES_RATE_LIMIT_WRITE` | No | `10` | Max write requests per minute per user |
| `FLUXFILES_STORAGE_PATH` | No | `packages/core/storage` | Dir for runtime state (rate-limit counter). Point at a writable volume for read-only deployments |
| `FLUXFILES_LOCAL_PRIVATE` | No | `false` | Serve `local` disk files through per-file `/api/fm/stream` tokens (Range-capable) instead of static URLs. The disk root must then not be served statically |
| `FLUXFILES_XACCEL` | No | — | Internal nginx location (e.g. `/_ff_media`) for `X-Accel-Redirect` streaming — nginx serves the bytes with native Range, PHP never copies the file |
| `FLUXFILES_IMPORT_MAX_MB` | No | `50` | Max MB per URL import when the token omits `max_import_mb` |
| `FLUXFILES_IMPORT_RATE_LIMIT` | No | `10` | Imports/min when the token omits `import_rate_limit` |
| `FLUXFILES_IMPORT_TIMEOUT` | No | `30` | Seconds per import fetch |
| `FLUXFILES_IMPORT_ALLOW_SVG` | No | `false` | Allow SVG imports (off — XML can carry script) |
| `AWS_ACCESS_KEY_ID` | No | — | AWS S3 access key |
| `AWS_SECRET_ACCESS_KEY` | No | — | AWS S3 secret key |
| `AWS_DEFAULT_REGION` | No | `ap-southeast-1` | AWS region |
| `AWS_BUCKET` | No | — | S3 bucket name |
| `AWS_ENDPOINT` | No | — | Custom S3-compatible endpoint (MinIO / DO Spaces / …). Empty = native AWS S3; when set, path-style addressing is used |
| `AWS_VISIBILITY` | No | `private` | `private` = presigned GET URLs; `public` = direct object URLs (public-read ACL) |
| `AWS_PUBLIC_URL` | No | — | CDN / custom-domain base for a public disk, e.g. `https://cdn.example.com` |
| `AWS_URL_TTL` | No | `3600` | Presigned GET-URL lifetime (seconds) on a private S3 disk. Max 24h. Media files override via the `preview_url_ttl` claim |
| `R2_ACCESS_KEY_ID` | No | — | Cloudflare R2 access key |
| `R2_SECRET_ACCESS_KEY` | No | — | Cloudflare R2 secret key |
| `R2_ACCOUNT_ID` | No | — | Cloudflare account ID |
| `R2_BUCKET` | No | — | R2 bucket name |
| `R2_VISIBILITY` | No | `private` | `private` = presigned GET URLs; `public` needs a public bucket + `R2_PUBLIC_URL` |
| `R2_PUBLIC_URL` | No | — | Public base URL for a public R2 disk (r2.dev or custom domain) |
| `R2_URL_TTL` | No | `3600` | Presigned GET-URL lifetime (seconds) on a private R2 disk. Max 24h |
| `SFTP_HOST` | No | — | Register an `sftp` disk pointing at this host (VPS/shared hosting). Empty = no SFTP disk |
| `SFTP_PORT` | No | `22` | SFTP port |
| `SFTP_USERNAME` | No | — | SFTP username |
| `SFTP_PASSWORD` | No | — | SFTP password (or use `SFTP_PRIVATE_KEY`) |
| `SFTP_PRIVATE_KEY` | No | — | Private key (PEM contents) — alternative to password |
| `SFTP_PRIVATE_KEY_PASSPHRASE` | No | — | Passphrase for the private key |
| `SFTP_ROOT` | No | `/` | Remote root directory the disk is scoped to |
| `FLUXFILES_SSRF_ALLOW_HOSTS` | No | — | Comma-separated host[:port] trusted past the SSRF public-IP check (SFTP on a private network). Empty = full protection |
| `FLUXFILES_AI_PROVIDER` | No | — | `claude` or `openai` (empty = disabled) |
| `FLUXFILES_AI_API_KEY` | No | — | AI provider API key |
| `FLUXFILES_AI_MODEL` | No | auto | Override AI model (default: `claude-sonnet-4-20250514` / `gpt-4o`) |
| `FLUXFILES_AI_AUTO_TAG` | No | `false` | Auto-tag images on upload |

---

## Project Structure

> **Contributing?** See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for how the
> engine and adapters relate, the three integration patterns, and the publish flow.

```
FluxFiles/
├── packages/
│   ├── core/                         # Composer package: fluxfiles/fluxfiles
│   │   ├── api/                       # PHP backend (API router + core classes)
│   │   ├── assets/                    # UI JS/CSS served by core
│   │   ├── config/                    # disk definitions
│   │   ├── lang/                      # 16 translations
│   │   ├── public/                    # iframe entrypoint
│   │   ├── storage/                   # local uploads + runtime files (gitignored)
│   │   └── tests/                     # test suite
│   ├── laravel/                      # Composer package: fluxfiles/laravel
│   ├── wordpress/                    # WP plugin
│   ├── react/                        # npm: @fluxfiles/react (TypeScript)
│   ├── vue/                          # npm: @fluxfiles/vue (TypeScript)
│   ├── ckeditor4/                    # CKEditor 4 plugin
│   ├── tinymce/                      # TinyMCE 4/5 plugin
│   ├── summernote/                   # Summernote plugin
│   └── sdk/                          # npm: fluxfiles (SDK)
├── .env.example                      # Environment template
├── CHANGELOG.md
└── LICENSE                           # MIT
```

---

## Storage Internals (`_fluxfiles/`, `rate_limit.json`)

FluxFiles creates a few internal runtime files under your storage root (for local disk: `packages/core/storage/uploads/`).
These files are **not user content** and are **not meant to be edited by hand**.

### `_fluxfiles/` directory

Hidden internal folder used for indexes and logs. The API/UI blocks system paths (`_fluxfiles/`, `_variants/`) from normal list/rename/move/delete operations.

- **`_fluxfiles/index.json`**: Metadata index for files (title/alt/caption/tags) stored alongside objects. Used by `GET /api/fm/search` to perform fast search across the disk.
- **`_fluxfiles/dirs.json`**: Directory index (list of folder paths). Used by `GET /api/fm/search-folders` so searches like `test2` can match folder names without scanning the whole storage.
- **`_fluxfiles/audit.jsonl`**: Append-only audit log (JSON Lines) for user actions. Rotated automatically when it grows too large.
- **`_fluxfiles/index.lock`** (local disks only): Lock file for safely updating indexes on local filesystem. Not used for S3/R2.

If you delete `_fluxfiles/`, FluxFiles will generally recreate it as needed, but you may temporarily lose search results / audit history until indexes are rebuilt by new operations.

### `storage/rate_limit.json`

Local file-backed counter used by the rate limiter (per-user read/write quotas controlled by `.env`: `FLUXFILES_RATE_LIMIT_READ`, `FLUXFILES_RATE_LIMIT_WRITE`).

- Safe to delete during development (it will be recreated), but it may reset rate-limit counters.
- In production, **do not expose this file publicly** (deny access at your web server).

---

## Customization

| What | Where | Notes |
|------|-------|-------|
| **Secrets & CORS** | `.env` | `FLUXFILES_SECRET`, `FLUXFILES_ALLOWED_ORIGINS` |
| **Storage disks** | `packages/core/config/disks.php` | Add/remove disk definitions |
| **Cloud credentials** | `.env` | `AWS_*`, `R2_*` variables |
| **AI tagging** | `.env` | Provider, API key, model, auto-tag on upload |
| **Branding / colors** | `packages/core/assets/fm.css` | CSS custom properties (`--ff-primary`, `--ff-bg`, etc.) |
| **UI behavior** | `packages/core/assets/fm.js` | Alpine.js component — modify any behavior |
| **SDK protocol** | `packages/sdk/fluxfiles.js` | Event names, iframe communication |
| **Token defaults** | `packages/core/embed.php` | Default TTL, claims, signing |
| **Image variants** | `packages/core/api/ImageOptimizer.php` | Change sizes (thumb/medium/large) and quality |
| **Rate limits** | `.env` | `FLUXFILES_RATE_LIMIT_READ`, `FLUXFILES_RATE_LIMIT_WRITE` |
| **Translations** | `packages/core/lang/*.json` | Edit existing or add new locale |
| **Dangerous extensions** | `packages/core/api/FileManager.php` | `DANGEROUS_EXTENSIONS` constant |
| **Packages** | `packages/*/` | Core, adapters, SDK |

---

## Attribution

Created and maintained by **thai-pc**. If you fork or redistribute, please retain the copyright notice:

```
Based on FluxFiles by thai-pc — https://github.com/thai-pc/fluxfiles
```

---

## License

[MIT](LICENSE)
