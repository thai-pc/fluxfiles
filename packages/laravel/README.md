# FluxFiles for Laravel

Laravel adapter for [FluxFiles](https://github.com/thai-pc/fluxfiles) — a standalone, embeddable file manager with multi-storage support (Local, AWS S3, Cloudflare R2).

## Requirements

- PHP >= 8.1 (matches `fluxfiles/fluxfiles`)
- Laravel 10, 11, or 12

## Installation

```bash
composer require fluxfiles/laravel
```

Publish the config file:

```bash
php artisan vendor:publish --tag=fluxfiles-config
```

Add to your `.env`:

```env
FLUXFILES_SECRET=your-random-32-char-secret
```

For the default local disk, expose Laravel public storage once:

```bash
php artisan storage:link
```

The default `local` disk writes to `storage/app/public/fluxfiles/uploads` and returns URLs under `/storage/fluxfiles/uploads`.

## Modes

| Mode | Description |
|------|-------------|
| `proxy` (default) | FluxFiles API runs through Laravel routes — no separate server needed |
| `standalone` | FluxFiles runs on its own server; Laravel only generates tokens and embeds the iframe |

Set mode in `.env`:

```env
FLUXFILES_MODE=proxy
# or
FLUXFILES_MODE=standalone
FLUXFILES_ENDPOINT=https://your-fluxfiles-server.com
```

## Usage

### Blade Component

```blade
<x-fluxfiles
    disk="local"
    mode="picker"
    width="100%"
    height="600px"
    @select="handleFileSelect"
/>
```

### Generate Token

```php
use FluxFiles\Laravel\FluxFilesFacade as FluxFiles;

// For the current authenticated user
$token = FluxFiles::tokenForUser();

// With custom overrides
$token = FluxFiles::token(auth()->id(), [
    'perms'      => ['read', 'write'],
    'disks'      => ['local', 's3'],
    'prefix'     => 'user-123/',
    'max_upload'  => 20,    // MB — per uploaded file
    'max_storage' => 1000,  // MB — total quota per prefix (0 = unlimited)
    'max_files'   => 0,     // max files per prefix (0 = unlimited)
    'allowed_ext' => ['jpg', 'png', 'pdf'], // lowercase, no dot; null = all safe
    'ttl'         => 7200,  // seconds — token lifetime (7200 = 2 hours)
]);
```

### Blade Directives

```blade
<script>
FluxFiles.open({
    endpoint: '@fluxfilesEndpoint',
    token: '@fluxfilesToken',
    disk: 'local',
    mode: 'picker'
});
</script>
```

### Facade Methods

```php
use FluxFiles\Laravel\FluxFilesFacade as FluxFiles;

FluxFiles::token($user, $overrides);   // Generate JWT token
FluxFiles::tokenForUser($overrides);   // Token for auth user
FluxFiles::endpoint();                  // Get FluxFiles URL
FluxFiles::iframeSrc();                 // Get iframe source URL
FluxFiles::sdkUrl();                    // Get SDK script URL
```

## Configuration

After publishing, edit `config/fluxfiles.php`:

```php
return [
    'secret'     => env('FLUXFILES_SECRET'),
    'mode'       => env('FLUXFILES_MODE', 'proxy'),
    'endpoint'   => env('FLUXFILES_ENDPOINT'),

    'route_prefix' => 'api/fm',
    'middleware'    => ['web', 'auth'],

    'disks' => [
        'local' => [...],
        's3'    => [...],
        'r2'    => [...],
    ],

    'defaults' => [
        'perms'       => ['read', 'write', 'delete'],
        'disks'       => ['local'],
        'prefix'      => '',
        'max_upload'  => 10,    // MB
        'allowed_ext' => null,  // null = allow all
        'max_storage' => 0,     // 0 = unlimited
        'ttl'         => 3600,  // seconds
    ],

    'locale'      => env('FLUXFILES_LOCALE', ''),
    'ai_provider' => env('FLUXFILES_AI_PROVIDER', ''),
    'ai_api_key'  => env('FLUXFILES_AI_API_KEY', ''),
    'ai_model'    => env('FLUXFILES_AI_MODEL', ''),
    'ai_auto_tag' => env('FLUXFILES_AI_AUTO_TAG', false),
];
```

## Deployment & permissions (`rate_limit.json`)

In **proxy mode** the rate limiter keeps its counter in a JSON file at
`config('fluxfiles.storage_path')` — by default `storage/fluxfiles/rate_limit.json`
(override with `FLUXFILES_STORAGE_PATH`). PHP creates the directory `0755` and the
file **`0600`** automatically on the first request.

What you need on the server:

- The directory must be **writable by the user PHP-FPM runs as** (usually
  `www-data`). Laravel's `storage/` already requires this, so the standard deploy
  perms cover it:

  ```bash
  sudo chown -R www-data:www-data storage bootstrap/cache
  sudo chmod -R 775 storage bootstrap/cache
  ```

- **Let PHP create `rate_limit.json` itself.** It's chmod-ed to `0600` (owner
  only), so it must be **owned by the PHP-FPM user**. If a deploy script or `root`
  pre-creates it as another user, PHP-FPM can't read it and every request fails
  with `500 "Rate limiter unavailable"`. Fix:

  ```bash
  sudo chown www-data:www-data storage/fluxfiles/rate_limit.json   # or just delete it; PHP recreates it
  ```

- **No web-server rule needed.** Unlike the standalone core, this file lives under
  Laravel's `storage/` (outside the `public/` web root), so it is never served.
  Keep the `0600` mode — don't loosen it.

- **Read-only / immutable deploys** (e.g. containers): point the path at a writable
  volume —

  ```env
  FLUXFILES_STORAGE_PATH=/var/lib/fluxfiles
  ```
  ```bash
  sudo install -d -o www-data -g www-data -m 775 /var/lib/fluxfiles
  ```

> Standalone mode (running the core server directly) puts the file at
> `packages/core/storage/rate_limit.json` instead — there it **is** under the web
> root, so block it at the web server (`location /storage/rate_limit.json { deny all; }`).

## Using an existing upload directory

If your app already has a directory tree like `public/uploads/user_1/`, `public/uploads/user_2/` (populated before FluxFiles was installed), you can point FluxFiles at it — existing files show up immediately, and a one-shot Artisan command makes them searchable.

### 1. Point the `local` disk at your existing path

In `config/fluxfiles.php`:

```php
'disks' => [
    'local' => [
        'driver' => 'local',
        'root'   => public_path('uploads'),     // where your files already live
        'url'    => '/uploads',                 // URL prefix for preview links
    ],
],
```

### 2. Scope each user to their own sub-folder via the `prefix` claim

Always derive the prefix server-side from the authenticated user — never trust client input:

```php
use FluxFiles\Laravel\FluxFilesFacade as FluxFiles;

$token = FluxFiles::tokenForUser([
    'prefix' => 'user_' . auth()->id() . '/',
    'disks'  => ['local'],
    'perms'  => ['read', 'write', 'delete'],
]);
```

With `prefix = 'user_1/'`, all API paths are transparently scoped to `public/uploads/user_1/`. User 1 cannot see or touch `user_2/`.

### 3. Filesystem permissions

Make `public/uploads` writable by the PHP process (upload / mkdir / delete):

```bash
chown -R www-data:www-data public/uploads
chmod -R u+rwX public/uploads
```

### 4. Seed metadata + folder index for pre-existing content

Listing existing files works out of the box. Preview links load only when the disk `url` matches a path your web server actually serves. **Search** relies on the FluxFiles metadata index (`_fluxfiles/index.json`) and the directory index (`_fluxfiles/dirs.json`), which are only written when content is created through the API. To make pre-existing files and folders searchable, run the included Artisan command once:

```bash
# Dry run first — report what would be indexed, no writes
php artisan fluxfiles:seed --disk=local --dry-run

# Apply
php artisan fluxfiles:seed --disk=local

# Generate thumbnails for existing images
php artisan fluxfiles:seed --disk=local --variants

# Add hashes for duplicate detection too
php artisan fluxfiles:seed --disk=local --hash --variants

# Only a sub-tree
php artisan fluxfiles:seed --disk=local --path=user_1

# Force re-index (overwrite any existing metadata)
php artisan fluxfiles:seed --disk=local --overwrite
```

What it does:

- Walks the disk recursively (skipping `_fluxfiles/`, `_variants/`, and `*.meta.json`).
- For each **file**: creates a metadata record with `title` derived from the filename so search can find it. Metadata writes are skipped for files that already have metadata unless `--overwrite` is passed, but `--hash` and `--variants` can still fill in missing hashes/thumbnails.
- For each **folder**: tracks it in `_fluxfiles/dirs.json` so folder search (`/api/fm/search-folders`) can return it.

After seeding, both file and folder search work for the existing tree.

### 5. Notes & gotchas

- FluxFiles auto-creates `public/uploads/_fluxfiles/` (metadata index and audit log) and `public/uploads/_variants/` (image thumbnails). These are hidden from the UI — do not delete them. If you use FTP/rsync/backup tools, add them to your ignore list.
- `url = '/uploads'` must match how your web server serves `public/`. Preview links are built as `{url}/{key}` — e.g. file key `user_1/avatar.jpg` → `/uploads/user_1/avatar.jpg`.
- Files uploaded **before** seeding won't have an `uploaded_by` metadata field. If you later enable `owner_only`, legacy files fall through gracefully (all users can act on them) until the next time someone edits them through the UI.
- For S3/R2 disks with an existing bucket, the same seed command works — pass `--disk=s3` (or `--disk=r2`). Listing is slower because it pages the bucket remotely.

## Features

- **Blade component** `<x-fluxfiles>` with auto token generation
- **Blade directives** `@fluxfilesToken` and `@fluxfilesEndpoint`
- **Facade** `FluxFiles::token()` for programmatic token generation
- **Proxy mode** — serve FluxFiles API through Laravel routes
- **Standalone mode** — connect to a separate FluxFiles server
- **Auto-discovery** — ServiceProvider and Facade register automatically
- **16 languages** — en, vi, zh, ja, ko, fr, de, es, ar, pt, it, ru, th, hi, tr, nl

## License

MIT — see [LICENSE](LICENSE) for details.

## Links

- [FluxFiles](https://github.com/thai-pc/fluxfiles) — Main repository
- [Documentation](https://github.com/thai-pc/fluxfiles#laravel) — Full docs
- [Issues](https://github.com/thai-pc/fluxfiles/issues) — Bug reports
