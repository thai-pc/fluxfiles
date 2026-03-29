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

## Features

| Category | Details |
|----------|---------|
| **Storage** | Local disk, AWS S3, Cloudflare R2 via Flysystem v3. Cross-disk copy/move. |
| **Auth** | JWT HS256 with granular claims — permissions, disk access, path scoping, upload limits, file type whitelist, storage quota. BYOB (Bring Your Own Bucket) support. |
| **File ops** | Upload, download (presigned URL), move, copy, rename, delete, create folders. Chunk upload (S3 multipart) for large files. |
| **Images** | Auto WebP variants on upload (thumb 150px / medium 768px / large 1920px). Inline crop tool with aspect ratio presets. |
| **AI** | Claude or OpenAI vision API — auto-tag, alt text, captions on upload or manual trigger. |
| **Metadata** | Title, alt text, caption, tags per file. Stored as S3 object metadata (cloud) or sidecar JSON (local). Full-text search via FTS5. |
| **Safety** | Duplicate detection (MD5). Rate limiting per user. Audit log. Per-user storage quota. |
| **UI** | Dark mode (auto/manual). 16 languages with RTL support. Bulk operations (multi-select, shift-select). |

---

## Requirements

- PHP >= 7.4
- Extensions: `gd` (image processing), `curl` (AI tagging)
- Composer

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

Edit `.env`:

```env
# Required
FLUXFILES_SECRET=your-random-secret-key-min-32-chars
FLUXFILES_ALLOWED_ORIGINS=http://localhost:3000,https://yourapp.com

# AWS S3 (optional)
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=ap-southeast-1
AWS_BUCKET=

# Cloudflare R2 (optional)
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_ACCOUNT_ID=
R2_BUCKET=

# AI auto-tag (optional — 'claude' or 'openai')
FLUXFILES_AI_PROVIDER=
FLUXFILES_AI_API_KEY=
```

### 3. Run

```bash
php -S localhost:8080 router.php
```

- UI: `http://localhost:8080/public/index.html`
- API: `http://localhost:8080/api/fm/`

For production, point your web server (Nginx/Apache) to the project root with URL rewriting equivalent to `router.php`.

### 4. Generate a Token

```php
require_once 'path/to/fluxfiles/embed.php';

$token = fluxfiles_token(
    userId:      'user-123',
    perms:       ['read', 'write', 'delete'],
    disks:       ['local', 's3', 'r2'],
    prefix:      'user-123/',   // scope to user directory
    maxUploadMb: 10,
    allowedExt:  null,          // null = allow all
    ttl:         3600           // 1 hour
);
```

---

## Embedding in Your App

### JavaScript SDK

```html
<script src="https://your-fluxfiles-host/fluxfiles.js"></script>

<script>
FluxFiles.open({
    endpoint: 'https://your-fluxfiles-host',
    token: 'eyJhbGci...',
    disk: 'local',
    mode: 'picker',         // 'picker' or 'browser'
    multiple: false,
    locale: 'en',           // auto-detects if omitted
    theme: 'auto',          // 'light', 'dark', or 'auto'
    allowedTypes: ['image/*', '.pdf'],
    maxSize: 10485760,      // bytes
    container: '#my-div',   // omit for modal overlay
    onSelect(file) {
        console.log('Selected:', file.url, file.path);
    },
    onClose() {
        console.log('Closed');
    }
});
</script>
```

### Commands

```js
FluxFiles.navigate('/photos/2024');
FluxFiles.setDisk('s3');
FluxFiles.refresh();
FluxFiles.search('invoice');
FluxFiles.crossCopy('s3', 'backups/');
FluxFiles.crossMove('r2', 'archive/');
FluxFiles.aiTag();
FluxFiles.close();
```

### Events

```js
FluxFiles.on('FM_READY',  (payload) => { /* iframe loaded */ });
FluxFiles.on('FM_SELECT', (file)    => { /* file or array */ });
FluxFiles.on('FM_CLOSE',  ()        => { /* closed */ });
FluxFiles.on('FM_EVENT',  (event)   => {
    // event.action: upload, delete, rename, move, copy, mkdir, crop, ai_tag
});
```

### PHP Embed Helper

```php
require_once 'path/to/fluxfiles/embed.php';

echo fluxfiles_embed(
    endpoint: 'https://your-fluxfiles-host',
    token:    $token,
    disk:     'local',
    mode:     'picker',
    width:    '100%',
    height:   '600px'
);
```

---

## Storage Disks

Configured in `config/disks.php`:

```php
// Local filesystem
'local' => [
    'driver' => 'local',
    'root'   => __DIR__ . '/../storage/uploads',
],

// AWS S3
's3' => [
    'driver' => 's3',
    'region' => $_ENV['AWS_DEFAULT_REGION'],
    'bucket' => $_ENV['AWS_BUCKET'],
    'key'    => $_ENV['AWS_ACCESS_KEY_ID'],
    'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'],
],

// Cloudflare R2
'r2' => [
    'driver'   => 's3',
    'endpoint' => 'https://' . $_ENV['R2_ACCOUNT_ID'] . '.r2.cloudflarestorage.com',
    'region'   => 'auto',
    'bucket'   => $_ENV['R2_BUCKET'],
    'key'      => $_ENV['R2_ACCESS_KEY_ID'],
    'secret'   => $_ENV['R2_SECRET_ACCESS_KEY'],
],
```

> R2 uses the S3-compatible API. ACL operations are not supported — FluxFiles automatically disables `retain_visibility` for endpoint-based disks.

### BYOB (Bring Your Own Bucket)

Users can connect their own S3/R2 buckets. Credentials are AES-256-GCM encrypted inside the JWT:

```php
$token = fluxfiles_byob_token(
    userId:    'user-123',
    byobDisks: [
        'my-s3' => [
            'driver' => 's3',
            'region' => 'us-west-2',
            'bucket' => 'user-personal-bucket',
            'key'    => 'AKIAIOSFODNN7EXAMPLE',
            'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ],
    ],
    perms: ['read', 'write', 'delete'],
    ttl:   1800
);
```

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
    "max_storage":  1000
}
```

| Claim | Type | Description |
|-------|------|-------------|
| `sub` | string | User identifier |
| `perms` | string[] | `read`, `write`, `delete` |
| `disks` | string[] | Allowed storage disks |
| `prefix` | string | Path scope (e.g. `user-123/`) |
| `max_upload` | int | Max upload size in MB (default 10) |
| `allowed_ext` | string[]&#124;null | File extension whitelist (`null` = any) |
| `max_storage` | int | Storage quota in MB (`0` = unlimited) |
| `byob_disks` | object | Encrypted BYOB credentials (optional) |

---

## API Endpoints

Base path: `/api/fm/`

### Public (no auth)

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/lang` | List available locales |
| `GET` | `/lang/{code}` | Get translation messages |

### File Operations (JWT required)

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/list?disk=&path=` | List directory |
| `POST` | `/upload` | Upload file (multipart) |
| `DELETE` | `/delete` | Permanently delete file/folder |
| `POST` | `/rename` | Rename file/folder |
| `POST` | `/move` | Move file/folder |
| `POST` | `/copy` | Copy file/folder |
| `POST` | `/mkdir` | Create directory |
| `POST` | `/cross-copy` | Copy between disks |
| `POST` | `/cross-move` | Move between disks |
| `POST` | `/presign` | Generate presigned URL |
| `POST` | `/crop` | Crop image |
| `POST` | `/ai-tag` | AI-tag image |

### Metadata

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/meta?disk=&path=` | File info (size, mime, variants) |
| `GET` | `/metadata?disk=&key=` | Get SEO metadata |
| `PUT` | `/metadata` | Save title, alt_text, caption, tags |
| `DELETE` | `/metadata` | Delete metadata |

### Search, Quota, Audit

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/search?disk=&q=&limit=` | Full-text search |
| `GET` | `/quota?disk=` | Storage usage |
| `GET` | `/audit?limit=&offset=&user_id=` | Audit log |

### Chunk Upload (S3 multipart)

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/chunk/init` | Initiate multipart upload |
| `POST` | `/chunk/presign` | Presign URL for a part |
| `POST` | `/chunk/complete` | Complete upload |
| `POST` | `/chunk/abort` | Abort upload |

All responses: `{ "data": { ... }, "error": null }`

---

## Framework Adapters

### Laravel

```bash
composer require fluxfiles/laravel
php artisan vendor:publish --tag=fluxfiles-config
```

```blade
<x-fluxfiles disk="local" mode="picker" height="600px" @select="handleFileSelect" />
```

```php
use FluxFiles\Laravel\FluxFilesFacade as FluxFiles;

$token = FluxFiles::token(
    userId: auth()->id(),
    perms: ['read', 'write'],
    disks: ['local', 's3']
);
```

### WordPress

1. Copy `adapters/wordpress/` to `wp-content/plugins/fluxfiles/`
2. Activate in WP Admin
3. Configure at **Settings > FluxFiles**

```
[fluxfiles disk="local" mode="browser" height="600px"]
```

A "FluxFiles" media button is added to the classic editor toolbar.

### React

```bash
npm install @fluxfiles/react
```

```tsx
import { FluxFilesModal } from '@fluxfiles/react';

<FluxFilesModal
    open={open}
    endpoint="https://your-fluxfiles-host"
    token={token}
    disk="local"
    onSelect={(file) => console.log(file)}
    onClose={() => setOpen(false)}
/>
```

```tsx
import { useFluxFiles } from '@fluxfiles/react';

const { iframeRef, iframeSrc, navigate, setDisk, refresh, search, aiTag } =
    useFluxFiles({ endpoint, token, onSelect: (file) => console.log(file) });
```

### Vue / Nuxt

```bash
npm install @fluxfiles/vue
```

```vue
<script setup>
import { ref } from 'vue';
import { FluxFilesModal } from '@fluxfiles/vue';

const open = ref(false);
</script>

<template>
    <FluxFilesModal
        v-model:open="open"
        endpoint="https://your-fluxfiles-host"
        :token="token"
        disk="local"
        @select="(file) => console.log(file)"
        @close="open = false"
    />
</template>
```

Nuxt 3 auto-import:
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
        endpoint: 'https://your-fluxfiles-host',
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
    fluxfiles_endpoint: 'https://your-fluxfiles-host',
    fluxfiles_token: 'JWT_TOKEN',
    fluxfiles_disk: 'local',
    fluxfiles_locale: 'en',
    fluxfiles_multiple: false
});
```

Auto-detects TinyMCE 4 vs 5 API. Click the **FluxFiles** toolbar button — images insert as `<img>`, other files as `<a>`.

---

## Internationalization

16 languages. Translation files in `lang/`.

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

**Priority:** FM_CONFIG locale > `?lang=` query param > `Accept-Language` header > `en`

See [`lang/CONTRIBUTING.md`](lang/CONTRIBUTING.md) to add a new language.

---

## Testing

```bash
# Start dev server
php -S localhost:8080 router.php

# Run all tests
bash tests/test-api.sh          # API integration tests (local disk)
bash tests/test-r2.sh           # R2/S3 cloud storage tests
php tests/test-claims.php       # Claims unit tests
php tests/test-diskmanager.php  # DiskManager unit tests
php tests/test-ratelimiter.php  # Rate limiter tests
php tests/test-metadata.php     # Metadata handler tests
php tests/test-byob.php         # BYOB encryption + token tests
php tests/test-i18n.php         # i18n validation (all languages)
php tests/test-i18n.php --api   # i18n API endpoint tests

# Generate tokens for manual testing
php tests/generate-token.php

# SDK test page (open in browser)
open tests/test-sdk.html

# Editor integration tests (open in browser)
open tests/test-ckeditor4.html   # CKEditor 4 + FluxFiles
open tests/test-tinymce.html     # TinyMCE 4/5 + FluxFiles
```

---

## Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `FLUXFILES_SECRET` | Yes | — | JWT signing secret (32+ chars) |
| `FLUXFILES_ALLOWED_ORIGINS` | Yes | — | Comma-separated CORS origins |
| `FLUXFILES_LOCALE` | No | auto | UI language code |
| `AWS_ACCESS_KEY_ID` | No | — | AWS S3 access key |
| `AWS_SECRET_ACCESS_KEY` | No | — | AWS S3 secret key |
| `AWS_DEFAULT_REGION` | No | `ap-southeast-1` | AWS region |
| `AWS_BUCKET` | No | — | S3 bucket name |
| `R2_ACCESS_KEY_ID` | No | — | Cloudflare R2 access key |
| `R2_SECRET_ACCESS_KEY` | No | — | Cloudflare R2 secret key |
| `R2_ACCOUNT_ID` | No | — | Cloudflare account ID |
| `R2_BUCKET` | No | — | R2 bucket name |
| `FLUXFILES_AI_PROVIDER` | No | — | `claude` or `openai` |
| `FLUXFILES_AI_API_KEY` | No | — | AI provider API key |
| `FLUXFILES_AI_MODEL` | No | auto | Override AI model |
| `FLUXFILES_AI_AUTO_TAG` | No | `false` | Auto-tag on upload |
| `FLUXFILES_RATE_LIMIT_READ` | No | `60` | Read requests per minute |
| `FLUXFILES_RATE_LIMIT_WRITE` | No | `10` | Write requests per minute |

---

## Project Structure

```
FluxFiles/
├── api/                          # PHP backend
│   ├── index.php                 # Router, CORS, JWT auth
│   ├── FileManager.php           # Core file operations
│   ├── StorageMetadataHandler.php # Metadata/audit in user storage
│   ├── MetadataRepositoryInterface.php # Metadata interface
│   ├── DiskManager.php           # Flysystem factory (local/s3/r2/byob)
│   ├── Claims.php                # JWT claims value object
│   ├── JwtMiddleware.php         # JWT extraction + verification
│   ├── ImageOptimizer.php        # Resize + WebP variants
│   ├── AiTagger.php              # Claude/OpenAI vision
│   ├── ChunkUploader.php         # S3 multipart upload
│   ├── CredentialEncryptor.php   # AES-256-GCM for BYOB credentials
│   ├── RateLimiterFileStorage.php # Token bucket rate limit (file-based)
│   ├── AuditLogStorage.php       # Audit log in user storage
│   ├── QuotaManager.php          # Storage quota enforcement
│   ├── I18n.php                  # Internationalization
│   └── ApiException.php          # HTTP error exceptions
├── assets/
│   ├── fm.js                     # Alpine.js UI component
│   └── fm.css                    # Styles (dark mode, RTL)
├── config/
│   └── disks.php                 # Storage disk definitions
├── lang/                         # 16 translation JSON files
├── public/
│   └── index.html                # Iframe entry point
├── storage/                      # Local uploads + rate limit data
├── tests/                        # Test suite
├── adapters/
│   ├── laravel/                  # Laravel package
│   ├── wordpress/                # WordPress plugin
│   ├── react/                    # React component library
│   ├── vue/                      # Vue 3 / Nuxt 3 library
│   ├── ckeditor4/                # CKEditor 4 plugin
│   └── tinymce/                  # TinyMCE 4/5 plugin
├── fluxfiles.js                  # Host app SDK (UMD)
├── fluxfiles.d.ts                # TypeScript declarations
├── embed.php                     # PHP helper (token + embed)
├── router.php                    # PHP built-in server router
├── composer.json
└── package.json
```

---

## Security

- **JWT HS256** — All API requests require a signed token
- **CORS whitelist** — Only specified origins can access the API
- **Path scoping** — Users restricted to directory prefix via `prefix` claim
- **Permission model** — Granular `read`, `write`, `delete` per token
- **Disk whitelist** — Per-token disk access control
- **File type restrictions** — Optional extension whitelist per token
- **BYOB encryption** — User bucket credentials encrypted with AES-256-GCM
- **Rate limiting** — Token bucket algorithm prevents abuse
- **Quota enforcement** — Per-user storage limits
- **Audit trail** — All write actions logged with user, IP, user agent
- **No ACL dependency** — Works with modern IAM/Bucket Policy (S3) and R2

---

## Fork / Customize

| Category | File(s) | What to Change |
|----------|---------|----------------|
| **Secrets & CORS** | `.env` | `FLUXFILES_SECRET`, `FLUXFILES_ALLOWED_ORIGINS` |
| **Storage** | `config/disks.php` | Add/remove disk definitions |
| **Cloud credentials** | `.env` | `AWS_*` and `R2_*` variables |
| **AI tagging** | `.env` | `FLUXFILES_AI_PROVIDER`, `FLUXFILES_AI_API_KEY` |
| **Branding** | `assets/fm.css` | CSS custom properties (`--ff-primary`, etc.) |
| **Frontend** | `assets/fm.js` | Alpine.js component |
| **SDK** | `fluxfiles.js` | Event names, defaults, iframe protocol |
| **Token** | `embed.php` | Default TTL, claims, signing |
| **Adapters** | `adapters/*/` | Package name, config, routes |
| **Translations** | `lang/*.json` | Edit strings or add locale |
| **Rate limits** | `.env` | `FLUXFILES_RATE_LIMIT_READ`, `FLUXFILES_RATE_LIMIT_WRITE` |
| **Image variants** | `api/ImageOptimizer.php` | Dimensions and quality |

### Attribution

Created and maintained by **thai-pc**. If you fork or redistribute, please retain the copyright notice:

```
Based on FluxFiles by thai-pc — https://github.com/thai-pc/fluxfiles
```

---

## License

MIT
