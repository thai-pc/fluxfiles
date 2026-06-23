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

## Two ways to use FluxFiles

FluxFiles is both **a standalone app** and **a Composer library**. Pick the mode
that matches what you saw after installing — this is the #1 source of "where did
my files go?" confusion.

### A. Standalone app (run the file manager directly)

Download a release / clone the repo, then from the package root:

```bash
composer install
php -S localhost:8080 router.php
```

Open:
- UI: `http://localhost:8080/public/index.html`
- API: `http://localhost:8080/api/fm/list?disk=local&path=`

### B. Composer dependency (embed in your PHP app)

```bash
composer require fluxfiles/fluxfiles
```

This installs the package under **`vendor/fluxfiles/fluxfiles/`** — that is correct
and expected. The app files (`api/`, `public/`, `embed.php`, …) live there, *not*
in your project root, and the autoloader lives in your project's top-level
`vendor/`. From your app you autoload the `FluxFiles\` classes and mint tokens via
`embed.php`.

To run the **standalone server from an installed dependency**, use the bundled
binary (it sets the document root and finds the autoloader for you, in either
layout):

```bash
php vendor/bin/fluxfiles serve --host=127.0.0.1 --port=8080
# → http://127.0.0.1:8080/public/
```

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

