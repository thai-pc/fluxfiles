# FluxFiles Core (PHP)

Core PHP engine for FluxFiles — a standalone, embeddable file manager with multi-storage support (Local/S3/R2) via Flysystem v3.

This package provides:
- API router (`api/index.php`)
- Core classes (`api/*.php`)
- UI assets (`assets/`, `public/`)
- Translations (`lang/`)
- Helper functions (`embed.php`)

## Requirements

- PHP >= 8.1 (Flysystem 3 + Intervention Image v3)
- Composer >= 2

## Install (Composer)

```bash
composer require fluxfiles/fluxfiles
```

## Local development (built-in server)

From this package directory:

```bash
php -S localhost:8080 router.php
```

Open:
- UI: `http://localhost:8080/public/index.html`
- API: `http://localhost:8080/api/fm/list?disk=local&path=`

## Configuration

Copy this package's [`.env.example`](.env.example) to `.env` (the API loads it from
the package root) and set at least:

```env
FLUXFILES_SECRET=your-random-secret-key-min-32-chars
FLUXFILES_ALLOWED_ORIGINS=http://localhost:3000,http://localhost:8080
```

## License

MIT — see [LICENSE](LICENSE) for details.

## Links

- Main repository: `https://github.com/thai-pc/fluxfiles`
- Laravel adapter: `https://packagist.org/packages/fluxfiles/laravel`
- Issues: `https://github.com/thai-pc/fluxfiles/issues`

