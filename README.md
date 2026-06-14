# FluxFiles

[![Packagist Version](https://img.shields.io/packagist/v/fluxfiles/fluxfiles?label=packagist&color=f28d1a)](https://packagist.org/packages/fluxfiles/fluxfiles)
[![Laravel](https://img.shields.io/packagist/v/fluxfiles/laravel?label=laravel&color=ff2d20)](https://packagist.org/packages/fluxfiles/laravel)
[![npm](https://img.shields.io/npm/v/fluxfiles?label=sdk&color=cb3837)](https://www.npmjs.com/package/fluxfiles)
[![npm](https://img.shields.io/npm/v/@fluxfiles/react?label=react&color=61dafb)](https://www.npmjs.com/package/@fluxfiles/react)
[![npm](https://img.shields.io/npm/v/@fluxfiles/vue?label=vue&color=42b883)](https://www.npmjs.com/package/@fluxfiles/vue)
[![npm](https://img.shields.io/npm/v/@fluxfiles/ckeditor4?label=ckeditor4&color=1eb5ff)](https://www.npmjs.com/package/@fluxfiles/ckeditor4)
[![npm](https://img.shields.io/npm/v/@fluxfiles/tinymce?label=tinymce&color=2dc26b)](https://www.npmjs.com/package/@fluxfiles/tinymce)
[![npm](https://img.shields.io/npm/v/@fluxfiles/node?label=node&color=339933)](https://www.npmjs.com/package/@fluxfiles/node)
[![PHP](https://img.shields.io/packagist/php-v/fluxfiles/fluxfiles?color=777bb4)](https://packagist.org/packages/fluxfiles/fluxfiles)
[![License](https://img.shields.io/github/license/thai-pc/fluxfiles)](LICENSE)

Standalone, embeddable file manager built with PHP 8.1+. Multi-storage support (Local, AWS S3, Cloudflare R2), JWT authentication, and a zero-build-step frontend powered by Alpine.js.

Drop it into any web app via iframe + SDK, or use the provided adapters for **Laravel**, **WordPress**, **React**, **Vue / Nuxt**, **CKEditor 4**, and **TinyMCE**.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Production Deployment](#production-deployment)
- [Embedding in Your App](#embedding-in-your-app)
- [Storage Disks](#storage-disks)
- [JWT Token Structure](#jwt-token-structure)
- [Multi-tenant](#multi-tenant)
- [API Reference](#api-reference)
- [Framework Adapters](#framework-adapters)
- [Internationalization](#internationalization)
- [Security](#security)
- [Testing](#testing)
- [Environment Variables](#environment-variables)
- [Project Structure](#project-structure)
- [Customization](#customization)
- [License](#license)

---

## Features

| Category | Details |
|----------|---------|
| **Storage** | Local disk, AWS S3, Cloudflare R2 via Flysystem v3. Cross-disk copy/move with stream transfer. |
| **Auth** | JWT HS256 with granular claims — permissions, disk access, path scoping, upload limits, file type whitelist, storage quota. BYOB (Bring Your Own Bucket) support. |
| **File ops** | Upload, download (presigned URL), move, copy, rename, delete, create folders. Chunk upload (S3 multipart) for large files. Bulk operations (multi-select). |
| **Images** | Auto WebP variants on upload (thumb 150px / medium 768px / large 1920px). Inline crop tool with aspect ratio presets. Variants regenerated after crop. |
| **AI** | Claude or OpenAI vision API — auto-tag, alt text, title, caption on upload or manual trigger. |
| **Metadata** | Title, alt text, caption, tags per file. Stored as S3 object metadata (cloud) or sidecar JSON (local). Full-text search. |
| **Safety** | Duplicate detection (SHA-256). Rate limiting per user. Audit log with rotation. Per-user storage quota. Origin validation. Dangerous extension blocking. |
| **UI** | Dark mode (auto/manual). 16 languages with RTL support. Responsive. Bulk operations (multi-select, shift-select). |
| **Adapters** | Laravel, WordPress, React, Vue/Nuxt, CKEditor 4, TinyMCE |

---

## Requirements

- **PHP** >= 8.1 (Flysystem 3 + Intervention Image v3 + firebase/php-jwt v7; tested with 8.1 — 8.4)
- **Extensions:** `gd`, `curl`, `json`, `openssl`, `mbstring`, `fileinfo`
- **Composer** >= 2.0

---

## Quick Start

### 1. Install

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
  short-lived **presigned** URL (expires, ≤ 24h). Fine for previewing right after
  selection; **do not save it** into content.
- **`permanent_url`** — a stable, non-expiring URL for **embedding in saved
  content** (CMS pages, editor HTML, DB records). Present for local disks, public
  disks, and any disk with a `public_url` (CDN / custom domain). It is **`null`**
  for a private bucket with no public domain — such a disk has no permanent URL.

```js
// Embedding into content you persist:
const embedSrc = file.permanent_url || file.url; // prefer the stable one
```

> To make `permanent_url` available for a private S3/R2 bucket, serve it behind a
> CDN / custom domain and set `public_url` on the disk config. The CKEditor 4 and
> TinyMCE plugins already prefer `permanent_url` automatically and warn when they
> have to fall back to a presigned URL.

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

    // AWS S3
    's3' => [
        'driver' => 's3',
        'region' => $_ENV['AWS_DEFAULT_REGION'],
        'bucket' => $_ENV['AWS_BUCKET'],
        'key'    => $_ENV['AWS_ACCESS_KEY_ID'],
        'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'],
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

> **Quick reference:** sizes are **MB** (`maxUploadMb`, `maxStorageMb`), time is
> **seconds** (`ttl`), and `allowedExt` entries are **bare lowercase extensions**
> with no leading dot.

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

### Node (server-side token SDK)

Mint FluxFiles tokens from any Node backend (Express, Next.js, Nuxt, NestJS) —
byte-compatible with the PHP core, including encrypted BYOB credentials. Zero
runtime dependencies.

```bash
npm install @fluxfiles/node
```

```ts
import { createToken } from '@fluxfiles/node';

const token = createToken({
  userId: 'user-42',
  perms: ['read', 'write'],
  prefix: 'users/42',
});
```

See [`packages/node`](packages/node) for BYOB, `verify`/`decode`, and Next.js /
Express examples.

### Laravel

```bash
composer require fluxfiles/laravel
php artisan vendor:publish --tag=fluxfiles-config
```

Add to `.env`:

```env
FLUXFILES_ENDPOINT=https://fm.yourdomain.com
FLUXFILES_SECRET=your-secret-min-32-chars
```

**Blade component:**

```blade
{{-- Embedded file browser --}}
<x-fluxfiles disk="local" mode="browser" height="600px" />

{{-- Modal file picker --}}
<x-fluxfiles disk="r2" mode="picker" @select="handleFileSelect" />
```

**Generate token in controller:**

```php
use FluxFiles\Laravel\FluxFilesFacade as FluxFiles;

$token = FluxFiles::token(
    userId: (string) auth()->id(),
    perms:  ['read', 'write'],
    disks:  ['local', 's3'],
    prefix: 'users/' . auth()->id() . '/'
);
```

**Config** (`config/fluxfiles.php`):

```php
return [
    'endpoint'    => env('FLUXFILES_ENDPOINT'),
    'secret'      => env('FLUXFILES_SECRET'),
    'disk'        => 'local',
    'disks'       => ['local', 'r2'],
    'prefix'      => 'users/{user_id}',
    'max_upload'  => 50,    // MB — per uploaded file
    'max_storage' => 500,   // MB — total quota per prefix (0 = unlimited)
    'allowed_ext' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'], // lowercase, no dot; null = all safe
];
```

### WordPress

**Install (recommended):** use a **release ZIP** that already includes `vendor/` (GitHub Releases or WordPress.org). Upload via **Plugins → Add New → Upload Plugin**, or extract into `wp-content/plugins/fluxfiles/`. No Composer or SSH on the server.

**Requirements:** PHP **8.1+** on the host (Flysystem 3 needs 8.0.2+; Intervention Image v3 and firebase/php-jwt v7 need 8.1+). Older PHP releases are not supported for the current `fluxfiles/fluxfiles` line.

The Packagist package `fluxfiles/fluxfiles` is **core PHP only**; it does **not** include this WordPress plugin. Source for the plugin folder: [main repository](https://github.com/thai-pc/fluxfiles) (`packages/wordpress`).

**Maintainers / monorepo:** to (re)build `vendor/` before zipping or committing to SVN, run `composer install --no-dev --optimize-autoloader` inside `packages/wordpress`. When developing from the monorepo with `packages/wordpress` next to `packages/core`, you can instead use `composer install -d packages/core` and the plugin will load that `vendor/autoload.php`.

**Activate & Configure:**

1. **Plugins > Installed Plugins** → Activate **FluxFiles**
2. **Settings > FluxFiles** → fill in:
   - **Endpoint:** `https://fm.yourdomain.com`
   - **JWT Secret:** must match `FLUXFILES_SECRET` in `.env`
   - **Default Disk:** `local`, `s3`, or `r2`
   - **Path Prefix:** `wp/{user_id}` (isolates files per WP user)

**Shortcode:**

```
[fluxfiles disk="r2" path="uploads" mode="picker" height="500px" multiple="1"]
```

Attributes: `disk`, `mode` (`picker`/`browser`), `width`, `height`, `multiple`
(`1`/`0` — multi-select).

**Media Button:** A "FluxFiles" button appears in the Classic Editor toolbar — opens a modal file picker.

**REST API:** Available at `/wp-json/fluxfiles/v1/`:

```
GET  /wp-json/fluxfiles/v1/files?disk=local&path=
POST /wp-json/fluxfiles/v1/upload
```

**PHP API:**

```php
$token = FluxFilesPlugin::instance()->generateToken($user_id);
```

### React

```bash
npm install @fluxfiles/react
```

**Components:**

```tsx
import { FluxFiles, FluxFilesModal, useFluxFiles } from '@fluxfiles/react';

// Embedded file browser
function FileBrowser() {
    return (
        <FluxFiles
            endpoint="https://fm.yourdomain.com"
            token={token}
            disk="r2"
            disks={['local', 'r2']}
            locale="en"
            theme="auto"
            onSelect={(file) => console.log(file)}
            style={{ height: '600px' }}
        />
    );
}

// Modal file picker
function FilePicker() {
    const [open, setOpen] = useState(false);

    return (
        <>
            <button onClick={() => setOpen(true)}>Choose File</button>
            <FluxFilesModal
                open={open}
                onClose={() => setOpen(false)}
                endpoint="https://fm.yourdomain.com"
                token={token}
                onSelect={(file) => {
                    console.log(file.url);
                    setOpen(false);
                }}
            />
        </>
    );
}

// Hook for programmatic control
function AdvancedUsage() {
    const { ref, navigate, refresh, setDisk, search, aiTag } = useFluxFiles({
        endpoint: 'https://fm.yourdomain.com',
        token,
        onSelect: (file) => console.log(file),
    });

    return (
        <div>
            <FluxFiles ref={ref} endpoint="..." token="..." />
            <button onClick={() => navigate('/photos')}>Go to Photos</button>
            <button onClick={() => setDisk('r2')}>Switch to R2</button>
        </div>
    );
}
```

**Build from source:**

```bash
cd packages/react
npm install
npm run build       # → dist/index.js, dist/index.mjs, dist/index.d.ts
npm run typecheck   # TypeScript validation
```

### Vue 3 / Nuxt 3

```bash
npm install @fluxfiles/vue
```

```vue
<script setup>
import { ref } from 'vue';
import { FluxFiles, FluxFilesModal } from '@fluxfiles/vue';

const open = ref(false);
const handleSelect = (file) => console.log(file.url);
</script>

<template>
    <!-- Embedded -->
    <FluxFiles
        endpoint="https://fm.yourdomain.com"
        :token="token"
        disk="local"
        @select="handleSelect"
        style="height: 600px"
    />

    <!-- Modal -->
    <button @click="open = true">Choose File</button>
    <FluxFilesModal
        v-model:open="open"
        endpoint="https://fm.yourdomain.com"
        :token="token"
        @select="handleSelect"
        @close="open = false"
    />
</template>
```

**Nuxt 3 auto-import:**

```ts
// nuxt.config.ts
export default defineNuxtConfig({
    plugins: ['@fluxfiles/vue/nuxt'],
});
```

### CKEditor 4

```bash
npm install @fluxfiles/ckeditor4
```

CKEditor 4 loads plugins from a path, so point `addExternal` at the installed
package (`node_modules/@fluxfiles/ckeditor4/`) or copy the folder into your
CKEditor `plugins/` directory. Then load `fluxfiles.js` SDK on the page and:

```js
CKEDITOR.replace('editor', {
    extraPlugins: 'fluxfiles',
    fluxfiles: {
        endpoint: 'https://fm.yourdomain.com',
        token: 'JWT_TOKEN',
        disk: 'local',
        locale: 'en',
        multiple: false,
        maxUploadMb: 10,   // MB per file (optional)
        maxFiles: 0        // max files per batch (0 = unlimited)
    }
});
```

Click the **FluxFiles** toolbar button (inline-SVG folder icon) — images insert
as `<img>`, other files as `<a>`.

### TinyMCE (4.x / 5.x)

```bash
npm install @fluxfiles/tinymce
```

TinyMCE loads the plugin from a URL (`external_plugins`), so reference
`node_modules/@fluxfiles/tinymce/plugin.js` or copy the folder into your TinyMCE
`plugins/` directory. Then load `fluxfiles.js` SDK and:

```js
tinymce.init({
    selector: '#editor',
    plugins: 'fluxfiles',
    toolbar: 'undo redo | bold italic | fluxfiles',
    fluxfiles_endpoint: 'https://fm.yourdomain.com',
    fluxfiles_token: 'JWT_TOKEN',
    fluxfiles_disk: 'local',
    fluxfiles_locale: 'en',
    fluxfiles_multiple: false,
    fluxfiles_max_upload_mb: 10,  // MB per file (optional)
    fluxfiles_max_files: 0        // max files per batch (0 = unlimited)
});
```

Auto-detects TinyMCE 4 vs 5 API.

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

> Install core dependencies first — the PHP tests load `packages/core/vendor/autoload.php`:
>
> ```bash
> composer install -d packages/core
> ```

### Core PHP — unit & integration

```bash
# Run the whole unit + integration suite
for f in packages/core/tests/unit/*.php packages/core/tests/integration/*.php; do php "$f"; done

# Or individually
php packages/core/tests/unit/test-claims.php           # JWT claims parsing + path scoping
php packages/core/tests/unit/test-diskmanager.php      # DiskManager factory
php packages/core/tests/unit/test-ratelimiter.php      # Rate limiter
php packages/core/tests/unit/test-byob.php             # BYOB encryption + token validation
php packages/core/tests/unit/test-i18n.php             # i18n — validates all 16 language files
php packages/core/tests/unit/test-i18n.php --api       # i18n API endpoint tests
php packages/core/tests/integration/test-metadata.php  # Metadata handler

# Generate a token for manual testing
php packages/core/tests/generate-token.php
```

### API & live storage (e2e)

```bash
# Boot the dev server first
cd packages/core && php -S 127.0.0.1:8080 router.php &

# HTTP API suite — list, upload, rename, move, copy, delete, metadata, search (42 checks)
bash packages/core/tests/e2e/test-api.sh

# Live S3/R2 test — env-gated, skips if no bucket. Works against MinIO, AWS S3, or R2.
FXTEST_S3_LABEL=MinIO FXTEST_S3_ENDPOINT=http://127.0.0.1:9000 \
FXTEST_S3_REGION=us-east-1 FXTEST_S3_BUCKET=fluxfiles-test \
FXTEST_S3_KEY=minioadmin FXTEST_S3_SECRET=minioadmin123 \
FXTEST_S3_VISIBILITY=private FXTEST_S3_CREATE_BUCKET=1 \
php packages/core/tests/e2e/test-s3-live.php
```

### Browser e2e (Playwright)

Boots the real PHP server and drives the standalone UI in chromium — render/auth smoke plus full UI interaction flows (upload, folder create + breadcrumb nav, search, dark-mode toggle, delete, inline crop, single-pick `FM_SELECT`, multi-select `multiple:true` returning an `FM_SELECT` array, and bulk operations (multi-select delete + move + download)).

```bash
cd packages/core/tests/browser
npm install && npx playwright install chromium && npm test
```

### Framework wrappers

Each wrapper owns its tests — vitest + jsdom for the JS adapters, stubbed-PHP smokes for the PHP adapters.

```bash
# JS wrappers — postMessage protocol
cd packages/sdk       && npm install && npm test
cd packages/react     && npm install && npm test
cd packages/vue       && npm install && npm test
cd packages/ckeditor4 && npm install && npm test
cd packages/tinymce   && npm install && npm test

# PHP adapters (need `composer install -d packages/core` first)
php packages/wordpress/tests/test-wp-smoke.php
php packages/laravel/tests/test-laravel-smoke.php

# Published-artifact smoke — pack each wrapper, install its tarball into a
# throwaway consumer, and typecheck (verifies dist/types, not just src/)
bash scripts/pack-smoke.sh all
```

### Docker

```bash
make test PHP=8.4     # run the core suite in a clean container on a given PHP version
make test-all         # PHP 8.1 – 8.4 matrix
make up               # dev stack: standalone app (:8080) + MinIO (:9000, console :9001)
```

CI (`.github/workflows/test.yml`) runs all of the above across 7 jobs (core PHP 8.1–8.4, API e2e, live S3 on MinIO, wrappers, browser e2e, pack-smoke, production Docker image).

### Package publishing

Packages are versioned independently. Do not use a plain `v*` monorepo tag for new releases. Use a package-prefixed tag so CI only publishes the package that changed:

```bash
# Composer split packages. The split repo receives the semver tag without the prefix.
git tag core-v0.2.1
git tag laravel-v0.2.0

# npm packages. The tag version must match each package.json.
git tag sdk-v0.2.0
git tag react-v0.2.0
git tag vue-v0.2.0
git tag ckeditor4-v0.2.1
git tag tinymce-v0.2.0
```

`core-v*` and `laravel-v*` trigger `.github/workflows/split.yml`. The npm package tags trigger `.github/workflows/npm-publish.yml` for only the matching package. **Push tags one at a time** — pushing more than three tags in a single `git push` skips the tag-triggered workflows.

#### Packagist auto-update (avoid the crawl lag)

After the split job pushes the new tag to the `fluxfiles-core` / `fluxfiles-laravel`
repos, it pings the Packagist update API so the new version appears **immediately**
instead of waiting for Packagist's slow periodic crawl. Set two repo secrets once:

- `PACKAGIST_USERNAME` — your packagist.org username.
- `PACKAGIST_TOKEN` — packagist.org → **Profile → Show API Token**.

Without them the split still succeeds (Packagist just updates on its own schedule).
As a belt-and-suspenders alternative, enable Packagist's GitHub hook on each split
repo (packagist.org package page → **Settings → integrations**), so a push updates
Packagist even outside CI.

`PACKAGIST_USERNAME` and `PACKAGIST_TOKEN` are **two separate secrets — set both**.
In GitHub's "New repository secret" form the **Name** field is the literal
`PACKAGIST_USERNAME` (GitHub rejects `-` in names), and your `your-packagist-name`
value goes in the **Secret** field below it (a `-` there is fine). Confirm with
`gh secret list -R <owner>/<repo>`. A successful ping returns **HTTP 202**.

#### Release troubleshooting

| Symptom | Fix |
| --- | --- |
| Packagist stuck at the old version, split job green | Set both `PACKAGIST_*` secrets, then `gh run rerun <split-run-id>` to re-ping the already-pushed tag (or click **Update** on the Packagist page once). |
| Split log: `PACKAGIST_TOKEN secret not set` | Only one secret exists — `gh secret list` and add the missing one. |
| Ping `HTTP 403 … invalid username/apiToken` | Username = `packagist.org/users/<name>` (not email/GitHub); token from Profile → Show API Token; set without a trailing newline (`printf %s 'value' \| gh secret set …`). |
| npm publish `404 … PUT …/@fluxfiles/<pkg>` | `NPM_TOKEN` is invalid/expired — rotate the npm **Automation** token and re-run. |
| `Call to undefined method …` after upgrading an adapter | The adapter's `composer.json` `fluxfiles/fluxfiles` floor is older than the core API it now calls — bump it (the `adapter-core-floor` CI job guards this). |

Always confirm the **registry** updated (`composer show fluxfiles/fluxfiles` /
`npm view @fluxfiles/<pkg> version`), not just the job colour.

### Manual browser pages

These pages load the SDK/editor plugins from absolute paths (`/fluxfiles.js`,
`/ckeditor4/`, `/tinymce/`) that the dev router serves, so **open them through
the running server**, not via `file://`:

```bash
cd packages/core && php -S localhost:8080 router.php   # then open in a browser:
#   http://localhost:8080/tests/manual/test-sdk.html        — SDK integration
#   http://localhost:8080/tests/manual/test-ckeditor4.html  — CKEditor 4
#   http://localhost:8080/tests/manual/test-tinymce.html    — TinyMCE
```

Paste a token from `php packages/core/tests/generate-token.php` into the page's
token field.

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
| `AWS_ACCESS_KEY_ID` | No | — | AWS S3 access key |
| `AWS_SECRET_ACCESS_KEY` | No | — | AWS S3 secret key |
| `AWS_DEFAULT_REGION` | No | `ap-southeast-1` | AWS region |
| `AWS_BUCKET` | No | — | S3 bucket name |
| `R2_ACCESS_KEY_ID` | No | — | Cloudflare R2 access key |
| `R2_SECRET_ACCESS_KEY` | No | — | Cloudflare R2 secret key |
| `R2_ACCOUNT_ID` | No | — | Cloudflare account ID |
| `R2_BUCKET` | No | — | R2 bucket name |
| `FLUXFILES_AI_PROVIDER` | No | — | `claude` or `openai` (empty = disabled) |
| `FLUXFILES_AI_API_KEY` | No | — | AI provider API key |
| `FLUXFILES_AI_MODEL` | No | auto | Override AI model (default: `claude-sonnet-4-20250514` / `gpt-4o`) |
| `FLUXFILES_AI_AUTO_TAG` | No | `false` | Auto-tag images on upload |

---

## Project Structure

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
