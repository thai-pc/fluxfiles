# FluxFiles

[![Packagist Version](https://img.shields.io/packagist/v/fluxfiles/fluxfiles?label=packagist&color=f28d1a)](https://packagist.org/packages/fluxfiles/fluxfiles)
[![Laravel](https://img.shields.io/packagist/v/fluxfiles/laravel?label=laravel&color=ff2d20)](https://packagist.org/packages/fluxfiles/laravel)
[![npm](https://img.shields.io/npm/v/fluxfiles?label=sdk&color=cb3837)](https://www.npmjs.com/package/fluxfiles)
[![npm](https://img.shields.io/npm/v/@fluxfiles/react?label=react&color=61dafb)](https://www.npmjs.com/package/@fluxfiles/react)
[![npm](https://img.shields.io/npm/v/@fluxfiles/vue?label=vue&color=42b883)](https://www.npmjs.com/package/@fluxfiles/vue)
[![PHP](https://img.shields.io/packagist/php-v/fluxfiles/fluxfiles?color=777bb4)](https://packagist.org/packages/fluxfiles/fluxfiles)
[![License](https://img.shields.io/github/license/thai-pc/fluxfiles)](LICENSE)

Standalone, embeddable file manager built with PHP 7.4+. Multi-storage support (Local, AWS S3, Cloudflare R2), JWT authentication, and a zero-build-step frontend powered by Alpine.js.

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

- **PHP** >= 7.4 (tested with 7.4 — 8.3)
- **Extensions:** `gd`, `curl`, `json`, `openssl`, `mbstring`, `fileinfo`
- **Composer** >= 2.0

---

## Quick Start

### 1. Install

```bash
git clone https://github.com/thai-pc/fluxfiles.git
cd fluxfiles
composer install
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

### 4. Generate a Token

```php
require_once 'path/to/fluxfiles/embed.php';

$token = fluxfiles_token(
    userId:      'user-123',
    perms:       ['read', 'write', 'delete'],
    disks:       ['local', 's3', 'r2'],
    prefix:      'user-123/',   // scope user to their own directory
    maxUploadMb: 10,
    allowedExt:  null,          // null = allow all safe extensions
    ttl:         3600           // 1 hour
);
```

Or generate via CLI for testing:

```bash
php tests/generate-token.php
```

---

## Production Deployment

### Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name fm.yourdomain.com;
    root /var/www/fluxfiles;

    # SSL
    ssl_certificate     /etc/ssl/certs/fm.yourdomain.com.pem;
    ssl_certificate_key /etc/ssl/private/fm.yourdomain.com.key;

    # API — rewrite to PHP router
    location /api/ {
        try_files $uri /api/index.php?$query_string;
    }

    # Public HTML — served via PHP for locale injection
    location /public/ {
        try_files $uri /api/index.php?$query_string;
    }

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

    # Uploaded files (local disk only)
    location /storage/uploads/ {
        alias /var/www/fluxfiles/storage/uploads/;
        expires 7d;
        add_header Cache-Control "public";
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

---

## Embedding in Your App

### JavaScript SDK (Vanilla)

Include `fluxfiles.js` on your page — zero dependencies, works with any framework:

```html
<script src="https://fm.yourdomain.com/fluxfiles.js"></script>

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
        maxSize: 10485760,            // 10MB in bytes
        container: '#my-div',         // CSS selector — omit for modal overlay

        onSelect(file) {
            // file = { url, key, name, size, mime, meta }
            console.log('Selected:', file.url);
            document.getElementById('image').src = file.url;
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
    // Single file: { url, key, name, size, mime, meta, variants }
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
    ttl:   1800  // shorter TTL for BYOB tokens
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
    "exp":          1710503600,
    "jti":          "a1b2c3d4e5f6",
    "perms":        ["read", "write", "delete"],
    "disks":        ["local", "s3", "r2"],
    "prefix":       "user-123/",
    "max_upload":   10,
    "allowed_ext":  ["jpg", "png", "pdf"],
    "max_storage":  1000,
    "byob_disks":   {}
}
```

| Claim | Type | Default | Description |
|-------|------|---------|-------------|
| `sub` | string | `"0"` | User identifier |
| `perms` | string[] | `["read"]` | Permissions: `read`, `write`, `delete` |
| `disks` | string[] | `["local"]` | Allowed storage disks |
| `prefix` | string | `""` | Path scope — user can only access files under this prefix |
| `max_upload` | int | `10` | Max file upload size in MB |
| `allowed_ext` | string[]&#124;null | `null` | File extension whitelist (`null` = allow all safe types) |
| `max_storage` | int | `0` | Storage quota in MB (`0` = unlimited) |
| `owner_only` | bool | `false` | When `true`, users can only delete/rename/move files they uploaded |
| `byob_disks` | object | — | Encrypted BYOB credentials (optional) |

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

> **Note:** Files uploaded before `owner_only` was enabled lack ownership metadata and will be accessible to all users. Ownership is recorded from the moment the feature is enabled.

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
| `DELETE` | `/delete` | `{disk, path}` | Delete file or directory (recursive) |
| `POST` | `/rename` | `{disk, path, name}` | Rename file or directory |
| `POST` | `/move` | `{disk, from, to}` | Move within same disk |
| `POST` | `/copy` | `{disk, from, to}` | Copy within same disk |
| `POST` | `/mkdir` | `{disk, path}` | Create directory |
| `POST` | `/cross-copy` | `{src_disk, src_path, dst_disk, dst_path}` | Copy between disks |
| `POST` | `/cross-move` | `{src_disk, src_path, dst_disk, dst_path}` | Move between disks |
| `POST` | `/presign` | `{disk, path, method, ttl}` | Generate presigned URL (GET or PUT, max 86400s) |
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
| `GET` | `/quota?disk=` | — | Storage usage: used_mb, max_mb, percentage |
| `GET` | `/audit?limit=&offset=` | `limit` default 100 | Audit log (filtered to current user) |

### Chunk Upload (S3 multipart, files > 10MB)

| Method | Path | Body | Description |
|--------|------|------|-------------|
| `POST` | `/chunk/init` | `{disk, path}` | Initiate → `{upload_id, key, chunk_size}` |
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
    'max_upload'  => 50,
    'max_storage' => 500,
    'allowed_ext' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'],
];
```

### WordPress

**Install:**

```bash
# Option 1: Copy plugin folder
cp -r adapters/wordpress/ /path/to/wp-content/plugins/fluxfiles/

# Option 2: If using Composer in your WP project
composer require fluxfiles/fluxfiles
cp -r vendor/fluxfiles/fluxfiles/adapters/wordpress wp-content/plugins/fluxfiles
```

**Requires:** Composer dependencies installed in the FluxFiles root (`composer install`).

**Activate & Configure:**

1. **Plugins > Installed Plugins** → Activate **FluxFiles**
2. **Settings > FluxFiles** → fill in:
   - **Endpoint:** `https://fm.yourdomain.com`
   - **JWT Secret:** must match `FLUXFILES_SECRET` in `.env`
   - **Default Disk:** `local`, `s3`, or `r2`
   - **Path Prefix:** `wp/{user_id}` (isolates files per WP user)

**Shortcode:**

```
[fluxfiles disk="r2" path="uploads" mode="picker" height="500px"]
```

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
cd adapters/react
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

1. Copy `adapters/ckeditor4/` to your CKEditor plugins directory
2. Load `fluxfiles.js` SDK on the page

```js
CKEDITOR.replace('editor', {
    extraPlugins: 'fluxfiles',
    fluxfiles: {
        endpoint: 'https://fm.yourdomain.com',
        token: 'JWT_TOKEN',
        disk: 'local',
        locale: 'en',
        multiple: false
    }
});
```

Click the **FluxFiles** toolbar button — images insert as `<img>`, other files as `<a>`.

### TinyMCE (4.x / 5.x)

1. Copy `adapters/tinymce/` to your TinyMCE plugins directory
2. Load `fluxfiles.js` SDK on the page

```js
tinymce.init({
    selector: '#editor',
    plugins: 'fluxfiles',
    toolbar: 'undo redo | bold italic | fluxfiles',
    fluxfiles_endpoint: 'https://fm.yourdomain.com',
    fluxfiles_token: 'JWT_TOKEN',
    fluxfiles_disk: 'local',
    fluxfiles_locale: 'en',
    fluxfiles_multiple: false
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

**Add a new language:** See [`lang/CONTRIBUTING.md`](lang/CONTRIBUTING.md) — copy `lang/en.json`, translate, submit PR.

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
| **Path scoping** | Users confined to their `prefix` directory — cannot access files outside scope |
| **Owner-only mode** | `owner_only` JWT claim restricts delete/rename/move to files the user uploaded |
| **System path protection** | `_fluxfiles/` and `_variants/` directories blocked from list/delete/rename/move — hidden in file listing |
| **Disk whitelist** | Per-token disk access — users can only access disks listed in JWT |
| **Permission model** | Granular `read`, `write`, `delete` checked on every operation |
| **BYOB encryption** | AES-256-GCM with HKDF-derived key (separate from signing key) |
| **BYOB local blocked** | BYOB tokens cannot use `local` driver — only S3-compatible storage |
| **Rate limiting** | Token bucket per user, file-locked, configurable (default: 60 read, 10 write/min) |
| **Quota enforcement** | Per-user storage limits checked before upload and cross-disk copy |
| **Duplicate detection** | SHA-256 hash prevents redundant uploads |
| **Audit trail** | All write actions logged with user ID, action, IP, user agent. Rotation at 5MB. |
| **Presign validation** | Method restricted to GET/PUT only, TTL capped at 86400 seconds |
| **Error handling** | Generic errors to client, detailed errors to server log only |
| **Search XSS** | HTML entities escaped before highlight `<mark>` tags applied |

### Production Checklist

- [ ] Set `FLUXFILES_SECRET` to a cryptographically random string (min 32 chars)
- [ ] Set `FLUXFILES_ALLOWED_ORIGINS` to your production domain(s)
- [ ] Use HTTPS everywhere
- [ ] Block public access to `.env`, `vendor/`, `storage/rate_limit.json`
- [ ] Set `storage/` directory permissions to 755, `.env` to 600
- [ ] Never commit `.env` with real credentials to git
- [ ] Review and rotate API keys periodically

---

## Testing

```bash
# Start dev server
php -S localhost:8080 router.php

# API integration tests
bash tests/test-api.sh          # Local disk — list, upload, rename, move, copy, delete, metadata, search
bash tests/test-r2.sh           # R2/S3 cloud storage tests

# Unit tests
php tests/test-claims.php       # JWT claims parsing + path scoping
php tests/test-diskmanager.php  # DiskManager factory
php tests/test-ratelimiter.php  # Rate limiter
php tests/test-metadata.php     # Metadata handler
php tests/test-byob.php         # BYOB encryption + token validation
php tests/test-i18n.php         # i18n — validates all 16 language files
php tests/test-i18n.php --api   # i18n API endpoint tests

# Generate tokens for manual testing
php tests/generate-token.php

# Browser-based tests
open tests/test-sdk.html        # SDK integration
open tests/test-ckeditor4.html  # CKEditor 4
open tests/test-tinymce.html    # TinyMCE
```

---

## Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `FLUXFILES_SECRET` | **Yes** | — | JWT signing secret (min 32 chars) |
| `FLUXFILES_ALLOWED_ORIGINS` | **Yes** | — | Comma-separated CORS origins |
| `FLUXFILES_LOCALE` | No | `en` | UI language (`en`, `vi`, `zh`, `ja`, etc.) |
| `FLUXFILES_RATE_LIMIT_READ` | No | `60` | Max read requests per minute per user |
| `FLUXFILES_RATE_LIMIT_WRITE` | No | `10` | Max write requests per minute per user |
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
├── api/                              # PHP backend
│   ├── index.php                     # Router, CORS, Origin validation, JWT auth
│   ├── FileManager.php               # Core file operations (list/upload/delete/move/copy/rename/mkdir/crop/presign)
│   ├── StorageMetadataHandler.php     # Metadata + search index + audit in user storage (S3 or sidecar JSON)
│   ├── MetadataRepositoryInterface.php
│   ├── DiskManager.php               # Flysystem factory (local/s3/r2/byob)
│   ├── Claims.php                    # JWT claims value object (perms, disks, prefix, limits)
│   ├── JwtMiddleware.php             # JWT extraction + HS256 verification
│   ├── ImageOptimizer.php            # Resize + WebP variants (thumb/medium/large)
│   ├── AiTagger.php                  # Claude / OpenAI vision API integration
│   ├── ChunkUploader.php             # S3 multipart upload (files > 10MB)
│   ├── CredentialEncryptor.php        # AES-256-GCM encryption for BYOB credentials
│   ├── RateLimiterFileStorage.php     # Token bucket rate limiter (file-based with flock)
│   ├── AuditLogStorage.php           # Audit log stored in user's disk (_fluxfiles/audit.jsonl)
│   ├── QuotaManager.php              # Storage quota calculation + enforcement
│   ├── I18n.php                      # Locale detection, JSON translation loading, t() / tp()
│   └── ApiException.php              # HTTP error exception class
├── assets/
│   ├── fm.js                         # Alpine.js UI component (file browser, detail panel, crop, bulk ops)
│   └── fm.css                        # Styles — light/dark mode, RTL support, CSS custom properties
├── config/
│   └── disks.php                     # Storage disk definitions (local/s3/r2)
├── lang/                             # 16 translation files (en.json, vi.json, etc.)
│   ├── en.json
│   ├── vi.json
│   ├── ...
│   └── CONTRIBUTING.md               # How to add a new language
├── public/
│   └── index.html                    # Iframe entry point (Alpine.js + htmx)
├── storage/
│   ├── uploads/                      # Local disk root (gitignored)
│   └── rate_limit.json               # Rate limiter data (gitignored)
├── tests/                            # Test suite (bash scripts + PHP unit tests)
├── adapters/
│   ├── laravel/                      # Composer package: fluxfiles/laravel
│   │   ├── src/                      # ServiceProvider, Facade, Controller, Middleware, Blade component
│   │   ├── config/fluxfiles.php      # Publishable config
│   │   ├── routes/fluxfiles.php      # Route definitions
│   │   └── composer.json
│   ├── wordpress/                    # WP plugin
│   │   ├── fluxfiles.php             # Plugin header + boot
│   │   ├── includes/                 # Plugin, Admin, Api, Shortcode, MediaButton classes
│   │   ├── templates/settings.php    # Admin settings page
│   │   └── assets/admin.css
│   ├── react/                        # npm: @fluxfiles/react (TypeScript)
│   │   ├── src/                      # FluxFiles.tsx, FluxFilesModal.tsx, useFluxFiles.ts, types.ts
│   │   └── package.json
│   ├── vue/                          # npm: @fluxfiles/vue (TypeScript)
│   │   ├── src/                      # FluxFiles.vue, FluxFilesModal.vue, useFluxFiles.ts
│   │   └── package.json
│   ├── ckeditor4/                    # CKEditor 4 plugin
│   └── tinymce/                      # TinyMCE 4/5 plugin
├── fluxfiles.js                      # Host app SDK (IIFE + UMD, zero dependencies)
├── fluxfiles.d.ts                    # TypeScript declarations for SDK
├── embed.php                         # PHP helpers: fluxfiles_token(), fluxfiles_embed(), fluxfiles_byob_token()
├── router.php                        # PHP built-in server router (dev mode)
├── composer.json                     # PHP dependencies
├── package.json                      # npm metadata (for SDK publishing)
├── .env.example                      # Environment template
├── CHANGELOG.md
└── LICENSE                           # MIT
```

---

## Customization

| What | Where | Notes |
|------|-------|-------|
| **Secrets & CORS** | `.env` | `FLUXFILES_SECRET`, `FLUXFILES_ALLOWED_ORIGINS` |
| **Storage disks** | `config/disks.php` | Add/remove disk definitions |
| **Cloud credentials** | `.env` | `AWS_*`, `R2_*` variables |
| **AI tagging** | `.env` | Provider, API key, model, auto-tag on upload |
| **Branding / colors** | `assets/fm.css` | CSS custom properties (`--ff-primary`, `--ff-bg`, etc.) |
| **UI behavior** | `assets/fm.js` | Alpine.js component — modify any behavior |
| **SDK protocol** | `fluxfiles.js` | Event names, iframe communication |
| **Token defaults** | `embed.php` | Default TTL, claims, signing |
| **Image variants** | `api/ImageOptimizer.php` | Change sizes (thumb/medium/large) and quality |
| **Rate limits** | `.env` | `FLUXFILES_RATE_LIMIT_READ`, `FLUXFILES_RATE_LIMIT_WRITE` |
| **Translations** | `lang/*.json` | Edit existing or add new locale |
| **Dangerous extensions** | `api/FileManager.php` | `DANGEROUS_EXTENSIONS` constant |
| **Adapters** | `adapters/*/` | Package name, config, routes, views |

---

## Attribution

Created and maintained by **thai-pc**. If you fork or redistribute, please retain the copyright notice:

```
Based on FluxFiles by thai-pc — https://github.com/thai-pc/fluxfiles
```

---

## License

[MIT](LICENSE)
