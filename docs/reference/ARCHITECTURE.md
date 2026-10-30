# FluxFiles — Architecture (for contributors)

How the monorepo fits together: the engine, the adapters, how they relate, and how
everything is built, tested, and published. Read this once before touching more than
one package.

> Sibling docs: [`README.md`](../../README.md) (usage), [`.claude/architecture.md`](../../.claude/architecture.md)
> (runtime flow + module responsibilities), [`.claude/api-map.md`](../../.claude/api-map.md)
> (routes). Release mechanics are automated — see §5 (Publish topology) below and
> the workflows in `.github/workflows/`.

---

## 1. The one idea

**One engine, many faces.** All product logic lives in **`packages/core`** (a PHP
package + a zero-build browser UI). Everything else is a thin adapter that either
(a) proxies the core API from a PHP host, (b) embeds the core UI in a browser, or
(c) re-implements the token contract in another language. Adapters carry **no
business logic** — if you find yourself duplicating a rule into an adapter, it
belongs in the core instead.

Two hard constraints shape every decision:

- **Storage-resident by default, no FluxFiles-hosted database.** State is
  *storage-resident* (metadata sidecars, `_fluxfiles/*.json`, locked JSON for the
  rate limiter/audit/trash) and travels with the user's bucket, not a
  vendor-hosted central DB. **Opt-in exception:** `FLUXFILES_STORAGE_BACKEND=db`
  moves the same bookkeeping (metadata/search/folder-index/audit/trash/rate-limits)
  into the *operator's own* self-hosted SQLite/MySQL/Postgres instead of JSON
  files — an infrastructure choice gated by a server env var, not a JWT claim or
  a paid module. File *bytes* stay fully storage-resident either way, and the
  default (`json`) is unchanged for every existing install. See
  `docs/design/DB-STORAGE-MIGRATION-DESIGN.md`.
- **Stateless auth.** A JWT *is* the per-tenant configuration — permissions, disk
  scope, quotas, and the paid-feature gates are all claims. The operator mints them.

---

## 2. Monorepo layout

```
FluxFiles/
├── packages/
│   ├── core/         ★ the engine — PHP API + zero-build UI + the browser SDK source
│   ├── laravel/        PHP adapter   (composer-requires core)         → Packagist
│   ├── wordpress/      PHP adapter   (bundles core into a plugin ZIP) → GitHub release ZIP
│   ├── sdk/            browser SDK   (the postMessage bridge)         → npm  `fluxfiles`
│   ├── node/           server-side JWT minter (TypeScript)           → npm  @fluxfiles/node
│   ├── mcp/            MCP server for AI agents (TypeScript)         → npm  @fluxfiles/mcp
│   ├── python/         server-side JWT minter (Python 3.10+)         → PyPI fluxfiles-token
│   ├── react/  vue/    framework wrappers (TypeScript)               → npm  @fluxfiles/{react,vue}
│   └── ckeditor4/  tinymce/  summernote/   rich-text-editor plugins  → npm  @fluxfiles/*
│
├── .github/workflows/  test.yml · split.yml · npm-publish.yml · pypi-publish.yml · docker-publish.yml · wordpress-release.yml
├── scripts/            build-wordpress.sh · check-adapter-core-floor.sh · pack-smoke.sh · pack-modules.php · ci-retry.sh · landing-screenshots/ · …
├── docker/             Dockerfile · Dockerfile.prod · nginx.conf · entrypoint.sh
├── docs/               guides/ · reference/ · design/ · security/ — indexed in docs/README.md (a few owner-only notes are gitignored: ROADMAP, COMMERCIAL-STRATEGY, LICENSING-PLAN, LICENSE-SERVER-DEPLOYMENT, UPDATE-CHANNEL-DEPLOYMENT)
├── .claude/            CLAUDE.md · architecture.md · api-map.md · development.md   (AI-agent context)
└── README · CHANGELOG · AGENTS.md · Makefile · docker-compose.yml
```

The paid modules (`packages/share/`, `packages/intake/`, … — the ids in
`ModuleRegistry::$map`) are gitignored private packages with their own repos; they
are not part of this tree on a public checkout.

### Inside `packages/core` (the only thing adapters depend on)

```
core/
├── api/                ~63 PHP files (~50 top-level + 13 under `Db/` for the opt-in
│                       DB storage backend) — the whole engine
│   ├── index.php         HTTP entrypoint: CORS, locale routes, auth, DI wiring, route dispatch
│   ├── FileManager.php   central file-operation service + most security checks
│   ├── DiskManager.php   Flysystem factory (local / S3 / R2 / SFTP) + BYOB disks
│   ├── Claims.php        decoded-JWT value object + path scoping
│   ├── StorageMetadataHandler.php   metadata sidecars + search/folder index + audit (JSON backend by default; `Db/` is the opt-in exception — see §1)
│   ├── ModuleRegistry.php / LicenseManager.php   paid-module 3-layer gate + offline licence verifier
│   └── … TusServer, ExifStripper, MediaPoster, ProcessEnv, AuditChain, FixityChecker,
│         AiTagger, ImageOptimizer, ImageToken, StreamToken, RangeStreamer,
│         QuotaManager, RateLimiter*, SsrfGuard, CredentialEncryptor, PublicLinks,
│         UrlImporter, BucketDoctor, ChunkUploader, Jwt/ImageCompat shims, …
├── embed.php           PHP token helpers: fluxfiles_token() / fluxfiles_byob_token()
├── router.php          dev server; also serves /fluxfiles.js from packages/sdk
├── assets/             fm.js + fm.css — Alpine.js + htmx UI, NO build step (vendored xterm/CodeMirror under assets/vendor/)
├── public/             index.html (the iframe entry document), share.html, intake.html
├── lang/               16 locale JSON files
├── config/disks.php    disk definitions (env-driven)
├── db/migrations/      SQL migrations for the opt-in DB backend
├── bin/fluxfiles       small CLI (serve, index, update)
└── tests/{unit,integration,e2e,browser,apps}
```

Composer name: **`fluxfiles/fluxfiles`**. This is the single dependency edge every
PHP adapter declares. Minimum PHP: **8.1** (`config.platform.php` pins every lock
file to it).

---

## 3. The three integration patterns (the key relationships)

```
                          ┌──────────────────────────────┐
                          │   packages/core (ENGINE)     │
                          │   fluxfiles/fluxfiles         │
                          └──────────────────────────────┘
        (A) composer require   │   (B) iframe + postMessage   │   (C) token contract
       ┌───────────┴─────────┐ │ ┌──────────┴───────────┐     │ ┌──────┴──────────┐
   laravel              wordpress │  sdk → react / vue          │   node / python
  (proxy the API,    (bundle core │      → ckeditor4 / tinymce  │  (mint JWTs, no
   serve the UI)     into a ZIP)  │        / summernote         │   PHP dependency)
```

`@fluxfiles/mcp` sits beside (B)/(C): it is an HTTP client of the core API that an
AI agent drives through a JWT minted by any of the token builders.

### (A) PHP host — `laravel`, `wordpress`
Composer-require `fluxfiles/fluxfiles`, then **proxy the core API** through the
framework's router and **serve the core UI** (`public/index.html` + `assets/`).
The host's existing auth maps to a FluxFiles JWT.
- **Hard dependency** with an honest floor: `composer.json` declares
  `"fluxfiles/fluxfiles": "^X.Y.Z"`. When an adapter starts calling a core symbol
  newer than its floor, the **adapter↔core floor guard** (CI) fails until the floor
  is bumped — so the constraint never lies. See `scripts/check-adapter-core-floor.sh`.
  The `lockfiles-and-laravel-db` CI job separately fails when a committed
  `composer.lock` no longer satisfies its `composer.json`.
- Both adapters proxy every core route **except** `/tus` (resumable upload — the UI
  falls back to a plain upload), the DB-backend bulk-metadata pair (`/metadata/export`,
  `/metadata/import`) and the SSO bridge (`/sso/*`, a standalone-UI login). Each proxy's
  smoke test keeps those in an explicit "intentionally unproxied" allowlist. `/zip`,
  SFTP-only `/chmod`, `/stream` and `/img` were ported directly into both proxy
  controllers (mirroring the SSH-terminal port). Core's logic now lives in reusable
  classes — `MediaEndpoint` (`/stream` + `/img`), `AuditPolicy`, `FileManagerFactory`,
  `TerminalService`/`GitDeployService`, `Db\MetadataRowLogic` — which the proxies can
  call instead of keeping their own copies.

### (B) Browser embed — `sdk`, `react`, `vue`, `ckeditor4`, `tinymce`, `summernote`
Put a core server somewhere, then embed it as an `<iframe>` and talk over a
**`postMessage` bridge** (`FM_CONFIG` / `FM_SELECT` / `FM_EVENT` / `FM_COMMAND` / …).
No code dependency on core — the contract is the message protocol over HTTP.
- **`sdk`** (`packages/sdk/fluxfiles.js`) is the canonical bridge. Core
  **serves it at `/fluxfiles.js`** (router.php), so embeds use one URL whether they
  run from the monorepo or a published install.
- **`react` / `vue`** do **not** bundle the SDK — they re-implement the same bridge
  in TypeScript (`useFluxFiles.ts`) so the package is tree-shakeable and typed.
- **`ckeditor4` / `tinymce` / `summernote`** load `fluxfiles.js` and call
  `FluxFiles.open()` to pick a file for the editor.
- All iframe surfaces grant the same `allow="clipboard-write; fullscreen"` +
  `allowfullscreen` — **keep these in sync across all four when you touch one**
  (CI job `iframe-allow`).

### (C) Token minting — `node`, `python`
`@fluxfiles/node` and `fluxfiles-token` (Python) mint FluxFiles JWTs (plain + BYOB)
**server-side**, with **no dependency on the PHP core**. Both are a **byte-for-byte
contract** with `Claims.php` + `CredentialEncryptor.php` (HS256 JWT, HKDF salt,
AES-256-GCM BYOB) — same claim names, same role/edition presets, same wire format.
- The contract is guarded by real cross-language tests:
  `packages/node/tests/php-compat.test.ts` and `packages/python/tests/test_php_compat.py`
  each mint in their own language and **decode in the actual PHP core**,
  round-tripping BYOB both ways. Touch a claim or the crypto on any side → keep
  all three in sync, or these tests fail. Shared fixtures for the claim/BYOB
  vectors live in `docs/testdata/{token,byob}-vectors.json`, consumed by all
  three languages.

---

## 4. Dependency graph & version discipline

| From | To | Edge | Enforcement |
|---|---|---|---|
| laravel, wordpress | core | composer `^X.Y.Z` | **floor guard** (CI) + lock-file validation |
| react, vue, editors | core | runtime (HTTP/iframe), no build dep | route-parity + browser e2e |
| sdk | core | served by core; protocol contract | sdk + browser e2e |
| node | core | **language contract** (no code dep) | `php-compat.test.ts` |
| python | core | **language contract** (no code dep) | `test_php_compat.py` |
| mcp | core | HTTP API client (no code dep) | `packages/mcp/tests` |

- **Per-package versioning.** Each package has its own version and a **prefixed Git
  tag**: `core-vX.Y.Z`, `laravel-vX.Y.Z`, `sdk-vX.Y.Z`, `mcp-vX.Y.Z`, … A release is
  "tag the package(s) that changed."
- **WordPress carries two versions:** the plugin's own (`fluxfiles.php` header,
  `FLUXFILES_VERSION`, `readme.txt` Stable tag — the release workflow refuses a tag
  that disagrees with any of them) **and** the core it bundles (pinned in
  `packages/wordpress/composer.lock`). After a core release, refresh that lock
  (`composer update -W`) before tagging `wordpress-v*`.

---

## 5. Publish topology (tag → registry)

| Registry | Packages | Workflow | Triggering tags |
|---|---|---|---|
| **Packagist** (subtree-split to read-only mirror repos) | core, laravel | `split.yml` | `core-v*`, `laravel-v*` |
| **npm** | sdk, node, mcp, react, vue, ckeditor4, tinymce, summernote | `npm-publish.yml` | `sdk-v*`, `node-v*`, `mcp-v*`, `react-v*`, … |
| **PyPI** (Trusted Publishing / OIDC, no stored token) | python | `pypi-publish.yml` | `python-v*` |
| **GitHub release ZIP** (bundles core + sdk + vendor/) | wordpress | `wordpress-release.yml` (runs `scripts/build-wordpress.sh`, attaches the ZIP + sha256 + a catalogue entry with `requires_php`) | `wordpress-v*` |
| **GHCR Docker** (standalone app image) | core | `docker-publish.yml` | `core-v*` |

Notes:
- **PyPI trusted publishing needs a one-time manual step on PyPI's side.** A brand-new
  PyPI project has no OIDC "pending publisher" registered yet, so its very first
  `python-v*` tag fails the exchange until a human adds one at
  pypi.org/manage/account/publishing/ (exact-match project name / owner / repo /
  workflow filename `pypi-publish.yml` / environment `pypi`). Every release after
  that first one works automatically — this is a one-time cost, not a per-release step.
- **WordPress is not on Packagist** (it's `type: wordpress-plugin`, shipped as a ZIP
  that bundles core+sdk so users just unzip into `wp-content/plugins/`). That's why
  it's absent from `split.yml`.
- Packagist indexing lags a tag push by a minute or two; a WP lock refresh waits for
  the new core to appear on Packagist first.
- Keep release-tag pushes **≤ 3 per `git push`** (more can silently skip workflows).
- Paid modules are released from their own private repos and packed with
  `php scripts/pack-modules.php` (see `docs/guides/OPERATIONS.md`).

---

## 6. CI map (`.github/workflows/test.yml`, 16 jobs)

`core-php` (unit+integration, PHP 8.1–8.5) · `lockfiles-and-laravel-db` (every
`composer.lock` agrees with its `composer.json`; Laravel DB adapter test) ·
**`adapter-core-floor`** (PHP floors are honest) · `iframe-allow` · `api-e2e` ·
`selfboot-e2e` (every `tests/e2e/*-http.php` + `test-sftp-live.php`, boots its own
`php -S` + an `atmoz/sftp` container) · `s3-localstack` (live S3 via LocalStack) ·
`db-mysql` / `db-postgres` (the `FLUXFILES_STORAGE_BACKEND=db` suite run against real
MySQL/Postgres services) · `wrappers` (react/vue/sdk/editors vitest) · `node-sdk`
(also builds and tests `@fluxfiles/mcp`) · `python-token` (PHP↔Python parity — decode +
BYOB round-trip, shared fixtures) · `browser-e2e` (Playwright) · `editor-e2e` ·
`pack-smoke` (published dist/types) · `docker-build`. Publishing is separate (`split` /
`npm-publish` / `pypi-publish` / `docker-publish` / `wordpress-release`).

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
             · JwtMiddleware → Claims (verify + decode; typed share/intake/stream tokens refused)
             · rate limiter check (file or DB storage, per-route buckets)
             · routeRequest() → FileManager / DiskManager / metadata repository
             · ModuleRegistry gates any paid-module endpoint (else 501/402/403)
             · audit log entry (HMAC-chained)
        └─ JSON { data, error }
```
For an embed, the host page talks to the iframe over `postMessage` instead of
issuing the API calls itself; the iframe makes the authenticated calls internally.

---

## 8. Where to make a change

| You want to… | Touch | Don't forget |
|---|---|---|
| add/modify a **file operation or rule** | `core/api/FileManager.php` (+ a test) | it's automatically available to every adapter |
| add a **token claim** | `core/api/Claims.php` (parse) + `embed.php` (mint) | forward it in laravel/wp/node/python mints + bump the node TS types + the python type hints + `docs/reference/CONFIG.md` + the floor if an adapter reads it |
| add a **core API route** | `core/api/index.php` | proxy it in laravel + wordpress (or whitelist it in each smoke's unproxied list); give mutating routes a named audit action (CI guard); update `.claude/api-map.md` + `docs/guides/API.md` |
| change the **postMessage protocol** | `sdk/fluxfiles.js` | mirror it in react/vue (`useFluxFiles.ts`) and the editor plugins |
| change **JWT/BYOB crypto** | `core/api/{Claims,CredentialEncryptor}.php` | mirror in `node/src/*` and `python/src/fluxfiles_token/*` — `php-compat.test.ts` / `test_php_compat.py` are the guards |
| add a **paid module** | a new proprietary package (own repo) + an entry in `ModuleRegistry::$map` + its routes in `index.php` + an `allow_<x>` claim | the 3-layer gate is capability (code installed) + license (`LicenseManager`) + claim (JWT); set the module's core floor to the first core whose `index.php` calls it |
| run an **external binary** | `proc_open` with an argv array | pass `ProcessEnv::minimal()` as the environment — never inherit secrets |
| add UI strings | `core/lang/*.json` (all 16) | the i18n parity test enforces equal key counts |

When in doubt: **logic in core, glue in the adapter.**
