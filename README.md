# FluxFiles

A standalone, embeddable file manager built with PHP 7.4+. Multi-storage support (Local, AWS S3, Cloudflare R2), JWT authentication, and a zero-build-step frontend powered by Alpine.js.

Drop it into any web app via iframe + SDK, or use the provided adapters for **Laravel**, **WordPress**, **React**, and **Vue / Nuxt**.

---

## Features

- **Multi-storage** — Local disk, AWS S3, Cloudflare R2 via Flysystem v3
- **JWT authentication** — HS256 tokens with granular claims (permissions, disk access, path scoping, upload limits, file type whitelist, storage quota)
- **File operations** — Upload, download, move, copy, rename, delete, create folders
- **Cross-disk operations** — Copy/move files between different storage backends
- **Image optimization** — Auto-generates WebP variants (thumb 150px, medium 768px, large 1920px) on upload
- **Image crop** — Inline crop tool with aspect ratio presets
- **AI auto-tag** — Claude or OpenAI vision API integration for automatic image tagging, alt text, and captions
- **Chunk upload** — S3 multipart upload for large files (>10 MB)
- **Trash / soft delete** — Recoverable deletes with configurable auto-purge
- **Full-text search** — SQLite FTS5 across file names, titles, alt text, captions, and tags
- **SEO metadata** — Title, alt text, caption per file (synced to S3 object tags)
- **Duplicate detection** — MD5 hash check on upload
- **Rate limiting** — Token bucket per user (60 reads, 10 writes per minute)
- **Audit log** — All write actions logged with user, IP, and user agent
- **Storage quota** — Per-user storage limits enforced server-side
- **Dark mode** — Automatic theme detection with manual toggle
- **i18n** — 16 languages (EN, VI, ZH, JA, KO, FR, DE, ES, AR, PT, IT, RU, TH, HI, TR, NL) with RTL support
- **Bulk operations** — Multi-select with bulk move, copy, delete, download

---

## Requirements

- PHP >= 7.4
- Extensions: `pdo`, `pdo_sqlite`, `gd` (for image processing), `curl` (for AI tagging)
- Composer

---

## Quick Start

### 1. Install

```bash
git clone <repo-url> fluxfiles
cd fluxfiles
composer install
```

### 2. Configure

```bash
cp .env.example .env
```

Edit `.env`:

```env
# REQUIRED — generate a random 32+ character string
FLUXFILES_SECRET=your-random-secret-key-here

# Allowed origins for CORS (comma-separated)
FLUXFILES_ALLOWED_ORIGINS=http://localhost:3000,https://yourapp.com
```

### 3. Run

```bash
# Development server
php -S localhost:8080 -t .

# The file manager UI is at:
# http://localhost:8080/public/index.html

# The API endpoint is:
# http://localhost:8080/api/
```

For production, point your web server (Nginx/Apache) to the project root.

### 4. Generate a token

In your host application, generate a JWT token to authenticate users:

```php
require_once 'path/to/fluxfiles/embed.php';

$token = fluxfiles_token(
    userId:     'user-123',
    perms:      ['read', 'write', 'delete'],
    disks:      ['local', 's3'],
    prefix:     'user-123/',    // scope to user directory
    maxUploadMb: 10,
    allowedExt:  null,          // null = allow all
    ttl:         3600           // 1 hour
);
```

---

## Embedding in Your App

### JavaScript SDK

Include `fluxfiles.js` in your page:

```html
<script src="https://your-fluxfiles-host/fluxfiles.js"></script>

<script>
FluxFiles.open({
    endpoint: 'https://your-fluxfiles-host',
    token: 'eyJhbGci...',
    disk: 'local',
    mode: 'picker',         // 'picker' (select file) or 'browser' (free browse)
    locale: 'en',           // optional — auto-detects if omitted
    allowedTypes: ['image/*', '.pdf'],  // optional file type filter
    maxSize: 10485760,      // optional max size in bytes
    container: '#my-div',   // optional — omit for modal overlay
    onSelect: function(file) {
        console.log('Selected:', file.url, file.path);
    },
    onClose: function() {
        console.log('Closed');
    }
});
</script>
```

### SDK Commands

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

### SDK Events

```js
FluxFiles.on('FM_READY', function(payload) { /* iframe loaded */ });
FluxFiles.on('FM_SELECT', function(file) { /* file selected */ });
FluxFiles.on('FM_EVENT', function(event) {
    // event.action: 'upload', 'delete', 'move', 'copy', 'mkdir',
    //               'restore', 'purge', 'trash', 'crop', 'ai_tag'
});
FluxFiles.on('FM_CLOSE', function() { /* closed */ });
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

## Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `FLUXFILES_SECRET` | Yes | — | JWT signing secret (32+ chars) |
| `FLUXFILES_ALLOWED_ORIGINS` | Yes | — | Comma-separated CORS origins |
| `FLUXFILES_LOCALE` | No | auto-detect | UI language (`en`, `vi`, `zh`, `ja`, `ko`, `fr`, `de`, `es`, `ar`, `pt`, `it`, `ru`, `th`, `hi`, `tr`, `nl`) |
| `AWS_ACCESS_KEY_ID` | No | — | AWS S3 access key |
| `AWS_SECRET_ACCESS_KEY` | No | — | AWS S3 secret key |
| `AWS_DEFAULT_REGION` | No | `ap-southeast-1` | AWS region |
| `AWS_BUCKET` | No | — | S3 bucket name |
| `R2_ACCESS_KEY_ID` | No | — | Cloudflare R2 access key |
| `R2_SECRET_ACCESS_KEY` | No | — | Cloudflare R2 secret key |
| `R2_ACCOUNT_ID` | No | — | Cloudflare account ID |
| `R2_BUCKET` | No | — | R2 bucket name |
| `FLUXFILES_AI_PROVIDER` | No | — | `claude` or `openai` (empty = disabled) |
| `FLUXFILES_AI_API_KEY` | No | — | API key for AI provider |
| `FLUXFILES_AI_MODEL` | No | auto | Override AI model (e.g. `gpt-4o`, `claude-sonnet-4-20250514`) |
| `FLUXFILES_AI_AUTO_TAG` | No | `false` | Auto-tag images on upload |

---

## Storage Disks

Configured in `config/disks.php`. Three drivers are provided out of the box:

```php
// Local filesystem
'local' => [
    'driver' => 'local',
    'root'   => __DIR__ . '/../storage/uploads',
    'url'    => '/storage/uploads',
],

// AWS S3
's3' => [
    'driver' => 's3',
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'key'    => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
],

// Cloudflare R2
'r2' => [
    'driver'   => 's3',
    'endpoint' => 'https://' . env('R2_ACCOUNT_ID') . '.r2.cloudflarestorage.com',
    'region'   => 'auto',
    'bucket'   => env('R2_BUCKET'),
    'key'      => env('R2_ACCESS_KEY_ID'),
    'secret'   => env('R2_SECRET_ACCESS_KEY'),
],
```

---

## JWT Token Structure

Tokens are signed with HS256. Claims control what each user can do:

```json
{
    "sub":          "user-123",
    "iat":          1710500000,
    "exp":          1710503600,
    "jti":          "a1b2c3d4e5f6",
    "perms":        ["read", "write", "delete"],
    "disks":        ["local", "s3"],
    "prefix":       "user-123/",
    "max_upload":   10,
    "allowed_ext":  ["jpg", "png", "pdf"],
    "max_storage":  1000
}
```

| Claim | Type | Description |
|-------|------|-------------|
| `sub` | string | User identifier |
| `perms` | string[] | Permissions: `read`, `write`, `delete` |
| `disks` | string[] | Allowed storage disks |
| `prefix` | string | Path prefix scope (e.g. `user-123/` restricts to that directory) |
| `max_upload` | int | Maximum upload size in MB |
| `allowed_ext` | string[]&#124;null | Allowed file extensions (`null` = any) |
| `max_storage` | int | Storage quota in MB (`0` = unlimited) |

---

## API Endpoints

Base path: `/api/fm/`

### Public (no auth)

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/lang` | List available locales |
| `GET` | `/lang/{code}` | Get translation messages for a locale |

### File Operations (JWT required)

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/list?disk=&path=` | List directory contents |
| `POST` | `/upload` | Upload file (multipart form) |
| `DELETE` | `/delete` | Soft delete (move to trash) |
| `POST` | `/move` | Move file/folder |
| `POST` | `/copy` | Copy file/folder |
| `POST` | `/mkdir` | Create directory |
| `POST` | `/cross-copy` | Copy between disks |
| `POST` | `/cross-move` | Move between disks |
| `POST` | `/presign` | Generate presigned URL |
| `POST` | `/crop` | Crop image |
| `POST` | `/ai-tag` | AI-tag an image |

### Metadata

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/meta?disk=&path=` | File info (size, mime, modified, variants) |
| `GET` | `/metadata?disk=&key=` | Get SEO metadata |
| `PUT` | `/metadata` | Save title, alt_text, caption, tags |
| `DELETE` | `/metadata` | Delete metadata |

### Trash

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/trash?disk=` | List trashed files |
| `POST` | `/restore` | Restore from trash |
| `DELETE` | `/purge` | Permanently delete |

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
| `POST` | `/chunk/presign` | Get presigned URL for a part |
| `POST` | `/chunk/complete` | Complete multipart upload |
| `POST` | `/chunk/abort` | Abort multipart upload |

All responses follow the format:
```json
{ "data": { ... }, "error": null }
```

---

## Framework Adapters

### Laravel

```bash
# In your Laravel project
composer require fluxfiles/laravel

# Publish config
php artisan vendor:publish --tag=fluxfiles-config
```

**Blade component:**
```blade
<x-fluxfiles
    disk="local"
    mode="picker"
    width="100%"
    height="600px"
    @select="handleFileSelect"
/>
```

**Generate token:**
```php
use FluxFiles\Laravel\FluxFilesFacade as FluxFiles;

$token = FluxFiles::token(
    userId: auth()->id(),
    perms: ['read', 'write'],
    disks: ['local', 's3']
);
```

Config: `config/fluxfiles.php`

### WordPress

1. Copy `adapters/wordpress/` to `wp-content/plugins/fluxfiles/`
2. Activate the plugin in WP Admin
3. Configure at **Settings > FluxFiles**

**Shortcode:**
```
[fluxfiles disk="local" mode="browser" height="600px"]
```

**Media button:** A "FluxFiles" button is automatically added to the classic editor toolbar.

### React

```bash
npm install @fluxfiles/react
```

**Picker component:**
```tsx
import { FluxFilesModal } from '@fluxfiles/react';

function App() {
    const [open, setOpen] = useState(false);

    return (
        <FluxFilesModal
            open={open}
            endpoint="https://your-fluxfiles-host"
            token={token}
            disk="local"
            locale="en"
            onSelect={(file) => console.log(file)}
            onClose={() => setOpen(false)}
        />
    );
}
```

**Hook for full control:**
```tsx
import { useFluxFiles } from '@fluxfiles/react';

const { iframeRef, iframeSrc, navigate, setDisk, refresh, search, aiTag } =
    useFluxFiles({
        endpoint: 'https://your-fluxfiles-host',
        token,
        onSelect: (file) => console.log(file),
    });
```

### Vue / Nuxt

```bash
npm install @fluxfiles/vue
```

**Modal component:**
```vue
<script setup>
import { ref } from 'vue';
import { FluxFilesModal } from '@fluxfiles/vue';

const open = ref(false);

function onSelect(file) {
    console.log(file);
    open.value = false;
}
</script>

<template>
    <button @click="open = true">Pick file</button>

    <FluxFilesModal
        v-model:open="open"
        endpoint="https://your-fluxfiles-host"
        :token="token"
        disk="local"
        locale="en"
        @select="onSelect"
        @close="open = false"
    />
</template>
```

**Embedded component:**
```vue
<script setup>
import { ref } from 'vue';
import { FluxFiles } from '@fluxfiles/vue';

const fm = ref();

// Programmatic control:
// fm.value?.navigate('/uploads');
// fm.value?.setDisk('s3');
// fm.value?.refresh();
</script>

<template>
    <FluxFiles
        ref="fm"
        endpoint="https://your-fluxfiles-host"
        :token="token"
        disk="local"
        width="100%"
        height="600px"
        @select="(file) => console.log(file)"
    />
</template>
```

**Composable for full control:**
```ts
import { useFluxFiles } from '@fluxfiles/vue';

const { iframeRef, iframeSrc, navigate, setDisk, refresh, search, aiTag } =
    useFluxFiles({
        endpoint: 'https://your-fluxfiles-host',
        token,
        onSelect: (file) => console.log(file),
    });
```

**Nuxt 3 auto-import:** Add the plugin to your `nuxt.config.ts`:
```ts
export default defineNuxtConfig({
    plugins: ['@fluxfiles/vue/nuxt'],
});
```

Components `<FluxFiles>` and `<FluxFilesModal>` are then globally available without explicit imports.

---

## Internationalization

16 languages included. Translation files are in `lang/`.

| Code | Language | Direction |
|------|----------|-----------|
| `en` | English | LTR |
| `vi` | Tieng Viet | LTR |
| `zh` | Chinese | LTR |
| `ja` | Japanese | LTR |
| `ko` | Korean | LTR |
| `fr` | Francais | LTR |
| `de` | Deutsch | LTR |
| `es` | Espanol | LTR |
| `pt` | Portugues | LTR |
| `ar` | Arabic | RTL |
| `it` | Italiano | LTR |
| `ru` | Русский | LTR |
| `th` | ไทย | LTR |
| `hi` | हिन्दी | LTR |
| `tr` | Türkçe | LTR |
| `nl` | Nederlands | LTR |

**Set locale via SDK:**
```js
FluxFiles.open({ locale: 'ar', ... });
```

**Set locale via env:**
```env
FLUXFILES_LOCALE=vi
```

**Auto-detection order:** FM_CONFIG locale > `?lang=` query param > `Accept-Language` header > `en`

**Adding a new language:** See [`lang/CONTRIBUTING.md`](lang/CONTRIBUTING.md).

---

## Project Structure

```
FluxFiles/
├── api/                        # PHP backend
│   ├── index.php               # Main router (CORS, auth, routing)
│   ├── FileManager.php         # Core file operations
│   ├── MetadataRepository.php  # SQLite CRUD + FTS5 search
│   ├── DiskManager.php         # Flysystem factory (local/s3/r2)
│   ├── Claims.php              # JWT claims value object
│   ├── JwtMiddleware.php       # JWT extraction + verification
│   ├── ImageOptimizer.php      # Resize + WebP variant generation
│   ├── AiTagger.php            # Claude/OpenAI vision integration
│   ├── ChunkUploader.php       # S3 multipart upload
│   ├── RateLimiter.php         # Token bucket rate limiting
│   ├── AuditLog.php            # Write action logging
│   ├── QuotaManager.php        # Storage quota enforcement
│   ├── I18n.php                # Internationalization
│   └── ApiException.php        # HTTP error exceptions
├── assets/
│   ├── fm.js                   # Alpine.js UI component
│   └── fm.css                  # Styles (dark mode, RTL)
├── config/
│   └── disks.php               # Storage disk definitions
├── lang/                       # Translation JSON files
├── public/
│   └── index.html              # Iframe entry point
├── storage/                    # SQLite DB + local uploads
├── adapters/
│   ├── laravel/                # Laravel package
│   ├── wordpress/              # WordPress plugin
│   ├── react/                  # React component library
│   └── vue/                    # Vue 3 / Nuxt 3 component library
├── fluxfiles.js                # Host app SDK (UMD)
├── embed.php                   # PHP helper (token + embed)
├── composer.json
└── .env.example
```

---

## Security

- **JWT HS256** — All API requests require a signed token
- **CORS whitelist** — Only specified origins can access the API
- **Path scoping** — Users can be restricted to a directory prefix via `prefix` claim
- **Permission model** — Granular `read`, `write`, `delete` permissions per token
- **Disk whitelist** — Per-token disk access control
- **File type restrictions** — Optional extension whitelist per token
- **Rate limiting** — Token bucket algorithm prevents abuse
- **Quota enforcement** — Per-user storage limits
- **Soft delete** — Files go to trash before permanent deletion
- **Audit trail** — All write actions are logged

---

## Fork / Customize

If you fork FluxFiles, the table below lists the key files you'll need to review and modify:

### Files to Change

| Category | File(s) | What to Change |
|----------|---------|----------------|
| **Secrets & CORS** | `.env` | `FLUXFILES_SECRET`, `FLUXFILES_ALLOWED_ORIGINS` — generate your own secret and set your domains |
| **Storage drivers** | `config/disks.php` | Add, remove, or reconfigure disk definitions (local / S3 / R2) |
| **Cloud credentials** | `.env` | `AWS_*` and `R2_*` variables for your own buckets |
| **AI tagging** | `.env` | `FLUXFILES_AI_PROVIDER`, `FLUXFILES_AI_API_KEY`, `FLUXFILES_AI_MODEL` |
| **Branding — colors** | `assets/fm.css` | CSS custom properties (`--ff-primary`, `--ff-bg`, `--ff-text`, etc.) |
| **Branding — title** | `public/index.html` | `<title>` tag and any visible product name |
| **Frontend logic** | `assets/fm.js` | Alpine.js component — add features or change UI behavior |
| **SDK** | `fluxfiles.js` | Event names, default options, iframe communication protocol |
| **Token helper** | `embed.php` | Default TTL, claims, or signing algorithm |
| **Laravel adapter** | `adapters/laravel/config/fluxfiles.php` | Endpoint, default disks, mode, AI settings |
| **WordPress adapter** | `adapters/wordpress/fluxfiles.php` | Plugin header (name, author, URI) |
| **React adapter** | `adapters/react/package.json` | Package name, author, repository URL |
| **Vue adapter** | `adapters/vue/package.json` | Package name, author, repository URL |
| **Translations** | `lang/*.json` | Edit existing strings or add a new locale (see `lang/CONTRIBUTING.md`) |
| **Rate limits** | `api/RateLimiter.php` | Bucket size and refill rate constants |
| **Image variants** | `api/ImageOptimizer.php` | Thumbnail / medium / large dimensions and quality |

### Attribution

FluxFiles was created and maintained by **thai-pc**.

If you fork or redistribute this project, please retain the original copyright notice and give appropriate credit. A link back to the original repository is appreciated:

```
Based on FluxFiles by thai-pc — https://github.com/thai-pc/FluxFiles
```

---

## License

MIT
