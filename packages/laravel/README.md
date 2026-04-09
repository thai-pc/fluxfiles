# FluxFiles for Laravel

Laravel adapter for [FluxFiles](https://github.com/thai-pc/fluxfiles) — a standalone, embeddable file manager with multi-storage support (Local, AWS S3, Cloudflare R2).

## Requirements

- PHP >= 7.4
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
    'max_upload' => 20,
    'ttl'        => 7200,
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
