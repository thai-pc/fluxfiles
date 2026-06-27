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

## Where the files land — `git clone` vs `composer require`

This is the #1 source of "where did my files go?" confusion. **The code is the same
in every case; only the folder layout + how you launch it differ.** Pick your row:

| How you got it | What you have | Run the standalone app with |
|---|---|---|
| **`composer require fluxfiles/fluxfiles`** | Core lands in **`vendor/fluxfiles/fluxfiles/`** (api/, public/, embed.php, router.php…). The autoloader is your project's top-level `vendor/autoload.php`. | `php vendor/bin/fluxfiles serve` |
| **Release ZIP** / `git clone …/fluxfiles-core` (the published split repo) | Core **is the folder root** (api/, public/, router.php… at top level). Run `composer install` there. | `php -S localhost:8080 router.php` |
| **`git clone …/fluxfiles`** (the **monorepo**, for dev/contrib) | The whole dev repo. **Core is a sub-package at `packages/core/`** — there is *no* composer.json at the repo root. | `composer install -d packages/core` then `cd packages/core && php -S localhost:8080 router.php` |

> The most common mix-up: cloning the **monorepo** and running `composer install`
> at the repo root (no composer.json there) — use `-d packages/core`, and the app
> lives under `packages/core/`, not the repo root.

Then open:
- UI: `http://localhost:8080/public/index.html`
- API: `http://localhost:8080/api/fm/list?disk=local&path=`

## Two ways to *use* it (independent of the above)

- **Standalone app** — run the file manager directly (any row above → its "Run" command).
- **Composer library** — `composer require fluxfiles/fluxfiles`, then autoload the
  `FluxFiles\` classes and mint tokens via `embed.php` from your own PHP app. The
  app files live under `vendor/fluxfiles/fluxfiles/` (that's correct); to also run
  the bundled standalone server from an installed dependency:

  ```bash
  php vendor/bin/fluxfiles serve --host=127.0.0.1 --port=8080
  # → http://127.0.0.1:8080/public/  (finds the autoloader + docroot in either layout)
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

