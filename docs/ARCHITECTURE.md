# FluxFiles — Architecture (for contributors)

How the monorepo fits together: the engine, the adapters, how they relate, and how
everything is built, tested, and published. Read this once before touching more than
one package.

> Sibling docs: [`README.md`](../README.md) (usage), [`.claude/architecture.md`](../.claude/architecture.md)
> (runtime flow + module responsibilities), [`.claude/api-map.md`](../.claude/api-map.md)
> (routes), [`PUBLISHING.md`](../PUBLISHING.md) (release mechanics).

---

## 1. The one idea

**One engine, many faces.** All product logic lives in **`packages/core`** (a PHP
package + a zero-build browser UI). Everything else is a thin adapter that either
(a) proxies the core API from a PHP host, (b) embeds the core UI in a browser, or
(c) re-implements the token contract in another language. Adapters carry **no
business logic** — if you find yourself duplicating a rule into an adapter, it
belongs in the core instead.

Two hard constraints shape every decision:

- **No server database.** All state is *storage-resident* (metadata sidecars,
  `_fluxfiles/*.json`, locked JSON for the rate limiter/audit/trash). State travels
  with the user's bucket, not a central DB.
- **Stateless auth.** A JWT *is* the per-tenant configuration — permissions, disk
  scope, quotas, and the paid-feature gates are all claims. The operator mints them.

---

## 2. Monorepo layout

```
FluxFiles/
├── packages/
│   ├── core/         ★ the engine — PHP API + zero-build UI + the browser SDK source
│   ├── laravel/        PHP adapter   (composer-requires core)         → Packagist
│   ├── wordpress/      PHP adapter   (bundles core into a plugin ZIP) → ZIP download
│   ├── sdk/            browser SDK   (the postMessage bridge)         → npm  `fluxfiles`
│   ├── node/           server-side JWT minter (TypeScript)           → npm  @fluxfiles/node
│   ├── react/  vue/    framework wrappers (TypeScript)               → npm  @fluxfiles/{react,vue}
│   └── ckeditor4/  tinymce/  summernote/   rich-text-editor plugins  → npm  @fluxfiles/*
│
├── .github/workflows/  test.yml · split.yml · npm-publish.yml · docker-publish.yml
├── scripts/            build-wordpress.sh · check-adapter-core-floor.sh · pack-smoke.sh · ci-retry.sh
├── docker/             Dockerfile · Dockerfile.prod · nginx.conf · entrypoint.sh
├── docs/               ARCHITECTURE.md (this) · METADATA-STORAGE-DESIGN.md · (planning docs, gitignored)
├── .claude/            CLAUDE.md · architecture.md · api-map.md · development.md   (AI-agent context)
└── README · CHANGELOG · AGENTS.md · PUBLISHING.md · Makefile · docker-compose.yml
```

### Inside `packages/core` (the only thing adapters depend on)

```
core/
├── api/                26 PHP classes — the whole engine (~8.5k LOC)
│   ├── index.php         HTTP entrypoint: CORS, locale routes, auth, DI wiring, route dispatch
│   ├── FileManager.php   central file-operation service + most security checks
│   ├── DiskManager.php   Flysystem factory (local / S3 / R2 / SFTP) + BYOB disks
│   ├── Claims.php        decoded-JWT value object + path scoping
│   ├── StorageMetadataHandler.php   metadata sidecars + search/folder index + audit (NO DB)
│   ├── LicenseManager.php           offline verifier for the paid editions
│   └── … AiTagger, ImageOptimizer, ImageToken, StreamToken, RangeStreamer,
│         QuotaManager, RateLimiterFileStorage, SsrfGuard, CredentialEncryptor,
│         UrlImporter, BucketDoctor, ChunkUploader, Jwt/ImageCompat shims, …
├── embed.php           PHP token helpers: fluxfiles_token() / fluxfiles_byob_token()
├── router.php          dev server; also serves /fluxfiles.js from packages/sdk
├── assets/             fm.js (~3.1k) + fm.css (~3.5k) — Alpine.js + htmx UI, NO build step
├── public/index.html   the iframe entry document
├── lang/               16 locale JSON files
├── config/disks.php    disk definitions (env-driven)
├── bin/fluxfiles       small CLI
└── tests/{unit,integration,e2e,browser}
```

Composer name: **`fluxfiles/fluxfiles`**. This is the single dependency edge every
PHP adapter declares.

---

## 3. The three integration patterns (the key relationships)

```
                          ┌──────────────────────────────┐
                          │   packages/core (ENGINE)     │
                          │   fluxfiles/fluxfiles         │
                          └──────────────────────────────┘
        (A) composer require   │   (B) iframe + postMessage   │   (C) token contract
       ┌───────────┴─────────┐ │ ┌──────────┴───────────┐     │ ┌──────┴──────┐
   laravel              wordpress │  sdk → react / vue          │     node
  (proxy the API,    (bundle core │      → ckeditor4 / tinymce  │  (mint JWTs in
   serve the UI)     into a ZIP)  │        / summernote         │   JS, no PHP)
```

### (A) PHP host — `laravel`, `wordpress`
Composer-require `fluxfiles/fluxfiles`, then **proxy the core API** through the
framework's router and **serve the core UI** (`public/index.html` + `assets/`).
The host's existing auth maps to a FluxFiles JWT.
- **Hard dependency** with an honest floor: `composer.json` declares
  `"fluxfiles/fluxfiles": "^X.Y.Z"`. When an adapter starts calling a core symbol
  newer than its floor, the **adapter↔core floor guard** (CI) fails until the floor
  is bumped — so the constraint never lies. See `scripts/check-adapter-core-floor.sh`.
- A few **byte-streaming** endpoints (`/stream`, `/img`, SFTP `/zip`, `chmod`) are
  *intentionally unproxied* by the adapters (they're core-standalone / Docker
  features); the route-parity test whitelists them.

### (B) Browser embed — `sdk`, `react`, `vue`, `ckeditor4`, `tinymce`, `summernote`
Put a core server somewhere, then embed it as an `<iframe>` and talk over a
**`postMessage` bridge** (`FM_CONFIG` / `FM_SELECT` / `FM_EVENT` / `FM_COMMAND` / …).
No code dependency on core — the contract is the message protocol over HTTP.
- **`sdk`** (`packages/sdk/fluxfiles.js`, ~260 LOC) is the canonical bridge. Core
  **serves it at `/fluxfiles.js`** (router.php), so embeds use one URL whether they
  run from the monorepo or a published install.
- **`react` / `vue`** do **not** bundle the SDK — they re-implement the same bridge
  in TypeScript (`useFluxFiles.ts`) so the package is tree-shakeable and typed.
- **`ckeditor4` / `tinymce` / `summernote`** load `fluxfiles.js` and call
  `FluxFiles.open()` to pick a file for the editor.
- All iframe surfaces grant the same `allow="clipboard-write; fullscreen"` +
  `allowfullscreen` — **keep these in sync across all four when you touch one.**

### (C) Token minting — `node`
`@fluxfiles/node` mints FluxFiles JWTs (plain + BYOB) **server-side in TypeScript**,
with **no dependency on the PHP core**. It is a **byte-for-byte contract** with
`Claims.php` + `CredentialEncryptor.php` (HS256 JWT, HKDF salt, AES-256-GCM BYOB).
- The contract is guarded by a real cross-language test:
  `packages/node/tests/php-compat.test.ts` mints in Node and **decodes in the actual
  PHP core**, round-tripping BYOB both ways. Touch a claim or the crypto on either
  side → keep both in sync, or this test fails.

---

## 4. Dependency graph & version discipline

| From | To | Edge | Enforcement |
|---|---|---|---|
| laravel, wordpress | core | composer `^X.Y.Z` | **floor guard** (CI) |
| react, vue, editors | core | runtime (HTTP/iframe), no build dep | route-parity + browser e2e |
| sdk | core | served by core; protocol contract | sdk + browser e2e |
| node | core | **language contract** (no code dep) | `php-compat.test.ts` |

- **Per-package versioning.** Each package has its own version and a **prefixed Git
  tag**: `core-vX.Y.Z`, `laravel-vX.Y.Z`, `sdk-vX.Y.Z`, … A release is "tag the
  package(s) that changed."
- **WordPress carries two versions:** the plugin's own (`fluxfiles.php`) **and** the
  core it bundles (pinned in `packages/wordpress/composer.lock`). After a core
  release, refresh that lock (`composer update -W`) before tagging `wordpress-v*`.

---

## 5. Publish topology (tag → registry)

| Registry | Packages | Workflow | Triggering tags |
|---|---|---|---|
| **Packagist** (subtree-split to read-only mirror repos) | core, laravel | `split.yml` | `core-v*`, `laravel-v*` |
| **npm** | sdk, node, react, vue, ckeditor4, tinymce, summernote | `npm-publish.yml` | `sdk-v*`, `node-v*`, `react-v*`, … |
| **ZIP download** (bundles core + sdk) | wordpress | `scripts/build-wordpress.sh` | `wordpress-v*` (release marker) |
| **GHCR Docker** (standalone app image) | core | `docker-publish.yml` | `core-v*` |

Notes:
- **WordPress is not on Packagist** (it's `type: wordpress-plugin`, shipped as a ZIP
  that bundles core+sdk so users just unzip into `wp-content/plugins/`). That's why
  it's absent from `split.yml`.
- Packagist indexing lags a tag push by a minute or two; a WP lock refresh waits for
  the new core to appear on Packagist first.
- Keep release-tag pushes **≤ 3 per `git push`** (more can silently skip workflows).

---

## 6. CI map (`.github/workflows/test.yml`, 10 jobs)

`core-php` (unit+integration, multi-PHP) · **`adapter-core-floor`** (PHP floors are
honest) · `api-e2e` · `s3-minio` (live S3 via MinIO) · `wrappers` (react/vue/sdk/
editors vitest) · `node-sdk` · `browser-e2e` (Playwright) · `pack-smoke` (published
dist/types) · `docker-build`. Publishing is separate (`split` / `npm-publish` /
`docker-publish`).

---

## 7. A request, end to end (standalone core)

```
browser <iframe src=/public/index.html?token=…>
  └─ GET /public/index.html ──► api/index.php serves the HTML
        · injects __FM_LOCALE__ (server-side i18n)
        · rewrites asset URLs to fm.js?v=<hash> / fm.css?v=<hash>  (cache-busting)
  └─ fm.js (Alpine) boots, reads the token, calls the API:
        Authorization: Bearer <JWT>
        └─ api/index.php
             · JwtMiddleware → Claims (verify + decode)
             · RateLimiterFileStorage::check
             · routeRequest() → FileManager / DiskManager / StorageMetadataHandler
             · LicenseManager gates any paid-module endpoint (else 501/402)
        └─ JSON { data, error }
```
For an embed, the host page talks to the iframe over `postMessage` instead of
issuing the API calls itself; the iframe makes the authenticated calls internally.

---

## 8. Where to make a change

| You want to… | Touch | Don't forget |
|---|---|---|
| add/modify a **file operation or rule** | `core/api/FileManager.php` (+ a test) | it's automatically available to every adapter |
| add a **token claim** | `core/api/Claims.php` (parse) + `embed.php` (mint) | forward it in laravel/wp/node mints + bump the node TS types + the floor if an adapter reads it |
| add a **core API route** | `core/api/index.php` | proxy it in laravel + wordpress (or whitelist it in the route-parity test if it's byte-streaming) |
| change the **postMessage protocol** | `sdk/fluxfiles.js` | mirror it in react/vue (`useFluxFiles.ts`) and the editor plugins |
| change **JWT/BYOB crypto** | `core/api/{Claims,CredentialEncryptor}.php` | mirror in `node/src/*` — `php-compat.test.ts` is the guard |
| add a **paid module** | a new proprietary package + a `class_exists` gate in core + a `LicenseManager` module id + a claim | the 3-layer gate is capability (code installed) + license (`LicenseManager`) + claim (JWT) |
| add UI strings | `core/lang/*.json` (all 16) | the i18n parity test enforces equal key counts |

When in doubt: **logic in core, glue in the adapter.**
