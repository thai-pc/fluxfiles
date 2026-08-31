# FluxFiles AI Context

This directory is for AI agents working on the FluxFiles codebase. Read this file first, then use the linked notes for deeper context. The repo root [`AGENTS.md`](../AGENTS.md) is the tool-agnostic summary of the same guidance — keep the two roughly in sync.

## Product Summary

FluxFiles is a standalone, embeddable PHP file manager. The core app exposes a PHP API and zero-build frontend that can be embedded by iframe/SDK. The repo also ships adapters for Laravel, WordPress, React, Vue/Nuxt, CKEditor 4, TinyMCE, and Summernote.

Primary capabilities:

- Local, AWS S3, and Cloudflare R2 storage through Flysystem v3.
- JWT auth with permission, disk, path prefix, upload size, extension, quota, owner-only, and BYOB disk claims.
- File operations: list, upload, download presign, rename, move, copy, delete, mkdir, cross-disk copy/move, import-from-URL.
- Metadata: title, alt text, caption, tags, file hash, ownership, search index, folder index, audit log.
- Image processing: WebP variants, crop/regenerate variants.
- Optional AI tagging through Claude/OpenAI-compatible vision providers.
- Iframe SDK and framework wrappers that communicate by `postMessage`.

## High-Level Structure

- `packages/core/`: main PHP package and standalone app.
- `packages/core/api/index.php`: HTTP entrypoint, CORS, locale routes, auth, dependency wiring, route dispatch.
- `packages/core/api/FileManager.php`: central file operation service and most security checks.
- `packages/core/api/DiskManager.php`: builds Flysystem disks for local/S3/R2 and runtime BYOB disks.
- `packages/core/api/Claims.php`: decoded JWT claim model and path scoping.
- `packages/core/api/StorageMetadataHandler.php`: metadata, index, folder index, hash, and audit storage.
- `packages/core/assets/` and `packages/core/public/`: standalone UI assets.
- `packages/sdk/`: browser SDK exposed as `FluxFiles`.
- `packages/laravel/`: Laravel service provider, facade, config, route proxy, Blade component.
- `packages/wordpress/`: WordPress plugin wrapper around the core package.
- `packages/react/`: React component/hooks wrapper.
- `packages/vue/`: Vue 3/Nuxt component/composable wrapper.
- `packages/ckeditor4/`, `packages/tinymce/`, `packages/summernote/`: editor integrations.
- `docs/`: design and roadmap notes.

More detailed maps:

- See `architecture.md` for runtime flow and module responsibilities.
- See `development.md` for commands, tests, and change guidance.
- See `api-map.md` for current core routes.

## Working Rules

- Do not treat the server as the owner of user metadata. Metadata should live with user storage, not in a central database.
- Keep JWT enforcement centralized and consistent: disk access, permissions, path scope, owner-only, upload size, and extension checks matter.
- Never expose storage credentials. BYOB credentials are encrypted in JWTs and decrypted only at runtime.
- Do not add new stateful server dependencies unless the task explicitly changes the stateless/BYOB direction.
- Avoid editing `dist/` files unless the task is specifically about published artifacts. Prefer source under `src/` and rebuild.
- Do not read or print secrets from `.env` unless the user explicitly asks. Use `.env.example` for documentation.
- `packages/core/storage/`, metadata sidecars, `_fluxfiles/`, generated variants, lock/index files, and uploaded test files are runtime data.
- **Config = JWT claims; `docs/CONFIG.md` is the single source of truth** (all 81 claims + env vars). When you add/rename a claim you MUST document it there — `tests/unit/test-config-doc.php` fails CI otherwise. Mint claims with the **one-options-array** API: `fluxfiles_token(['user'=>…, 'claims'=>[…]])`; the `claims` map (also in node/laravel/wordpress) is the escape hatch for any claim by its raw name. The legacy positional signature still works but isn't the documented form.
- **Never put the main JWT in a URL.** Adapters send it via `postMessage`; API calls use `Authorization: Bearer`. The standalone `?token=` is **dev-only** — it's stripped on boot (`history.replaceState`) and `/public/index.html` sends `Referrer-Policy: no-referrer`. Per-file `/img` & `/stream` query-string tokens are fine (scoped to one file + short TTL + distinct token type). CSRF is checked via the `Origin` header (not `Referer`), so `no-referrer` is safe.
- **BYO-embed over build-and-sell.** Where excellent free OSS self-host exists, embed it via a free config toggle instead of building/selling a competitor. **All four are shipped** (each a free http(s)-validated claim that makes the UI iframe the operator's own instance): terminal → `terminal_pty_url` (ttyd/gotty/wetty), PDF → `pdf_tools_url` (Stirling-PDF), office → `office_url` (Collabora/OnlyOffice), e-sign → `esign_url` (DocuSeal). `office_url`/`esign_url` may carry a `{url}` placeholder the UI substitutes with the selected file's URL. Genuine paid value only where there's no OSS drop-in AND it fits the stateless/storage-resident grain.
- **Release** = per-package tags (`core-vX.Y.Z`, `react-vX.Y.Z`, `node-vX.Y.Z`, …). Bump `package.json` for changed JS packages (react/vue/sdk/node); core & PHP adapters are versioned by the tag. **Push ≤3 release tags per `git push`** (>3 silently skips publish workflows) — batch them. Add a `CHANGELOG.md` entry. **Its internal `0.2.X` is its OWN counter and does NOT equal the core tag** — the two drifted apart once adapter-only releases started getting their own entries (e.g. `[0.2.72]` → `core-v0.2.63`, and `[0.2.73]`–`[0.2.77]` shipped no core at all). Always read `git tag | grep '^core-v' | sort -V | tail -1` for the real next core version; never derive it from the changelog. Each entry carries a `> Released:` line naming the exact tags. This bit a private module once: its `composer.json` floor was set from the changelog number to `^0.2.78`, a version core will not reach for years.
- **Paid-module releases** are their own repos (`v1.0.0`, not `core-v…`), so the ≤3-tags rule does not apply. Two rules that do: (1) a module's core floor must be the first core release whose `index.php` **calls** that module — composer answers "which core may this install against", and core is the caller, so a lower floor permits an install that is present, licensed and never invoked (this shipped for `virus`); it can't be CI-guarded because the packages are gitignored, so check it by hand. (2) A tag is not a release — `php scripts/pack-modules.php` builds the zips + `build/modules/catalogue.json` that `docs/update-server.example.php` serves and `UpdateClient` re-hashes. It builds from the **tag** (never the worktree), refuses a non-reproducible build, and rejects a layout `UpdateClient::install()` would mis-extract.

## Common Entry Points

- Standalone dev server:

```bash
cd packages/core
php -S localhost:8080 router.php
```

- Generate a test token:

```bash
php packages/core/tests/generate-token.php
```

- Core package install:

```bash
composer install -d packages/core
```

- React package checks:

```bash
cd packages/react
npm run typecheck
npm run build
```

- Vue package checks:

```bash
cd packages/vue
npm run typecheck
npm run build
```

## Current Notes

- Delete: `/delete` is permanent (used by purge/API). Files **and folders** have **soft-delete** via `/trash` (+ `/trash/restore|list|purge|empty`) — move-based into `_fluxfiles/trash/<id>/` with a `_fluxfiles/trash.json` manifest (storage-resident, scoped by prefix/owner; folders move the whole subtree incl. variants). The UI soft-deletes everything to trash.
- `docs/ROADMAP.md` (gitignored, local) is the current roadmap/business-strategy doc, cross-referenced against the codebase; `docs/COMMERCIAL-STRATEGY.md` + `docs/LICENSING-PLAN.md` cover the paid-tier model. Verify implementation before following any roadmap endpoint.
- `docs/METADATA-STORAGE-DESIGN.md` captures the important principle that metadata travels with user storage.
- Auth uses `firebase/php-jwt` **v7** (constraint `^7.0`): HS256 keys must be **≥ 32 bytes** or token encode/decode throws. `JwtCompat`/`ImageCompat` shims abstract jwt v5–v7 / intervention v2–v3.
- Local metadata sidecars live at `_fluxfiles/meta/{key}.json` (not `{file}.meta.json`), so a user-uploaded `*.meta.json` cannot collide with or overwrite them. S3/R2 use object metadata.
- `owner_only` is also enforced at folder level (delete/rename/move a folder containing other users' files → 403 via `assertOwnsTree`). BYOB endpoints are SSRF-checked.
- **URL import** (`POST /api/fm/import-url`, `UrlImporter.php`): synchronous server-side fetch → the upload pipeline. Opt-in per tenant via `allow_url_import` (default false → inert). `SsrfGuard.php` is the shared SSRF denylist (per-hop + post-connect IP check; also used by the BYOB endpoint check). v1 is sync (no Redis/queue — by design); a storage-resident job model would be the v2 path if async is ever needed.
- **Media preview** (inline `<video>`/`<audio>`/pdf already exists): the UI auto-refreshes an expiring presigned media URL on playback error (re-presign via `/api/fm/presign`, keep `currentTime`). Claims: `media_preview` (default true), `preview_url_ttl` (media files get a longer presigned TTL), `max_preview_mb` (oversized → download placeholder). **Gated local media** (`FLUXFILES_LOCAL_PRIVATE=true`): local files serve through `/api/fm/stream?token=` (`StreamToken` + `RangeStreamer`, Range-capable, nginx `X-Accel-Redirect` fast path) instead of static URLs, so an `<video>` can't open without a token — the disk root must then not be served statically. `stream_token_ttl` claim.
- **On-demand WebP/AVIF** (`GET /api/fm/img`, `ImageToken` + `ImageOptimizer::transform`): extends the upload-time variants with arbitrary sizes; cache lives in the file's `_variants/` (free invalidation via existing delete/trash cleanup). Width rounded 100px + clamped (`webp_max_width`), quality snaps to 60/75/80/90 → variant count bounded without per-request counting. **`format=auto` (default) content-negotiates AVIF→WebP→original from `Accept`** (AVIF needs intervention v3 + GD `imageavif`; `ImageOptimizer::avifSupported()` gate); avif/webp cache as separate keys (format is in `transformCacheKey`), response sends `Vary: Accept`; `format=avif`/`webp` force it. **Box sizing**: `height` + `fit` (cover crops / contain fits, default) + `dpr` (1/2/3, multiplies the requested size) — all folded into the cache key, never upsize. SVG/animated-GIF/bomb never converted. S3/R2 cache-hit 302-redirects to a presigned URL (no app egress). Image entries get `img_base` in `list()` when `webp_enabled` + a stream secret are set. Claims: `webp_enabled`/`webp_max_width`/`webp_default_quality`. **AVIF delivery is free/core.**
- **Optimization is now FREE/core** (`POST /api/fm/optimize`, `\FluxFiles\OptimizeModule` + `\FluxFiles\PdfOptimizer`, both in `packages/core/api/`): recompress images → WebP at rest (replace/keep, EXIF stripped) + Ghostscript PDF compression + batch + on-upload `auto_optimize` + savings in the usage dashboard (`OptimizeStats`). Opt-in per token via `allow_optimize` (it replaces/deletes originals → `optimize_forbidden` 403 without it); needs `write` (and `delete` to replace the original). It was a paid module but the value was thin once AVIF/WebP delivery became free in `/img`, so it was folded into the MIT core (removed from `ModuleRegistry`; the `optimize_format`/AVIF-at-rest path is gone — WebP-only). The `packages/optimize/` private package is retired. PDF needs `gs` on the server → `501 pdf_unavailable` when absent.
- **SSH terminal** (`POST /api/fm/terminal`, `SshTerminal`, SFTP disks only): stateless **command-runner** (one `exec` per request, cwd threaded back), opt-in `allow_terminal` claim (default off), `write` perm, audited, server kill-switch `FLUXFILES_TERMINAL_DISABLED`. Core-standalone (adapters don't proxy it; laravel forwards the claim only in standalone mode, WP not at all). **Optional free PTY upgrade:** the `terminal_pty_url` claim (http(s) only, validated in Claims) makes the UI **embed a self-hosted ttyd/gotty/wetty** (the operator runs it on their own box) via iframe for a true interactive terminal (vim/top/htop) instead of the command-runner — empty (default) → command-runner. **Free/core config toggle, never a paid module** (a paid PTY SKU was rejected: browser terminals are commodity OSS). The Go-sidecar idea is dropped in favor of BYO ttyd.
- Both `/stream` and `/img` mint only when `FileManager::setStreamSecret()` is called — index.php always does, and the Laravel/WordPress **proxy** controllers now do too (unconditionally, in their shared `fileManager()` builder), so both routes are **proxied** by both adapters (ported directly into each controller since `handleMediaStream()`/`handleImageTransform()` are inline functions in `index.php`, not a reusable file — same pattern as the SSH terminal port). They reuse `FLUXFILES_SECRET` for their per-file tokens (`t=stream` / `t=img`, distinct types — one can't be used on the other's endpoint). The preview-time watermark **overlay** claim (`watermark_enabled`) is still NOT forwarded by either proxy adapter — that's a separate, still-valid restriction: the proxy's `/img` port doesn't implement overlay compositing, so forwarding the claim would mint a token whose watermark gets silently dropped (see `FluxFilesManager.php`'s comment on `watermark_enabled`).
- **Watermark logo + transparent base gotcha:** intervention/image v3's `place()` with opacity<100 composites through an opaque GD scratch image (`imagecopymerge`), so a logo over a **transparent (alpha) PNG/WebP** base comes out a dark box (JPEG is fine — no alpha). Fix = bake opacity into the logo's own alpha then place at 100% (`ImageCompat::bakeLogoOpacity`, used by both `placeLogo` overlay + `placeLogoAt` burn-in). Don't reintroduce opacity-on-`place()`.
- **Modal chrome dark-mode anti-flash:** the React/Vue/SDK modal chrome lives in the HOST origin and can't read the cross-origin iframe's resolved theme, so when the `theme` prop is `auto`/unset it mirrors the embedded UI's boot: resolve dark from `localStorage['fluxfiles_theme']` first, then OS, and persist an explicit theme. Computing chrome `dark` only from `prefers-color-scheme` flashes a light header before a dark theme settles.
- **Paid modules: 11** — `share`, `intake`, `versioning`, `webhooks`, `ai`, `ocr`, `virus`, `backup`, `c2pa`, `audit-export`, `sso` (all gitignored private packages, gated by the `ModuleRegistry` 3-layer check). **`ModuleRegistry::$map` is the source of truth — read it rather than trusting a count written down anywhere, including here.** `optimize` was a 10th but is now FREE/core (see the optimization note above). Strategy: Share + Intake are the sellable heroes (Pro); Versioning + Webhooks are à-la-carte; AI/OCR/terminal/backup are not standalone SKUs (free OSS or BYO-key) — see the gitignored `docs/COMMERCIAL-STRATEGY.md`. `audit-export`/`sso` are the Enterprise Compliance Bundle, built deliberately ahead of a named prospect (an explicit override of `docs/ROADMAP.md`'s default caution against speculative builds).
- **AI Vision has an operator UI now** (`AiVisionModule`'s server side — `/api/fm/ai-vision`, `allow_ai_vision` claim — was already shipped; only the client was missing). fm.js/index.html got a modal (bg_remove/upscale/smart_crop, busy/error/result states), a detail-panel button, and context-menu + action-sheet entries, gated by the same `proGate('allow_ai_vision', 'ai')` three-state treatment as Share/Intake/Versioning/Audit export; `canAiVisionFile()` mirrors `ImageOptimizer::isImage()`'s raster set (no SVG). OCR/Virus/Backup/C2PA remain engine-only with no operator UI.
- **Audit retention is archive-before-truncate, and export/purge are a separate paid layer on top of free rotation.** `StorageMetadataHandler::audit()`'s rotation used to destructively drop old lines past the 5MB/5000-line cap; it now writes the dropped tail to `_fluxfiles/audit/archive/audit-<ts>-<hex>.jsonl` first, under the same lock — a free/core correctness fix, not gated by any module. `readAuditArchive()`/`purgeAuditBefore()` are the storage primitives (no Claims/license awareness); `GET /api/fm/audit/export` (NDJSON/CSV, full unpaginated dump) and `POST /api/fm/audit/purge` (admin-only: requires an unscoped token, since `audit.jsonl` is per-disk not per-tenant) are gated by the `audit-export` module + `allow_audit_export` claim.
- **SSO bridge is pre-auth, so it can't use the normal 3-layer `ModuleRegistry::require()`** — there's no `Claims` yet to check a per-token claim against. `ModuleRegistry::requireServer($id, $license)` does layers 1+2 only (installed + licensed); layer 3 becomes an explicit `FLUXFILES_SSO_ENABLED` env check in each route (`SsoModule::claim()` returns `''`, per `ModuleInterface`'s contract for claim-less modules). The real JWT, once minted post-login, hands off to the standalone UI via a URL **fragment** (`#boot=<one-time token>`, 60s TTL) rather than a query string — fragments never reach server logs — then a `POST /api/fm/sso/exchange` trades it for the real JWT over a response body, held in JS memory only, never persisted. OIDC only in v1 (no SAML).
- **Virus scan is wired fail-closed, and that's load-bearing.** `allow_virus_scan` makes `index.php` (and the Laravel/WP proxies, which build their own `FileManager`) set `FileManager::setVirusScanner()`; the `ModuleRegistry::require` gate runs **inside** the callback so reads still work and only writes needing a scan fail. Scanned: `upload()` (so also `/import-url`), `putContent()`, every `extractZip()` entry — each **before** the bytes are written. Anything that prevents a scan (module absent 501 / licence 402 / claim 403 / engine down 502) **refuses the write**; a malformed verdict counts as infected. `/api/fm/chunk/*` returns `409 virus_unscannable` while the claim is on, because S3-multipart bytes go browser→S3 and can never be scanned — don't "fix" that by letting the route through. The module scans by **path** (`scanPath`), not bytes, so uploads aren't loaded into memory.
- **Webhooks is shipped and intentionally at-most-once.** `docs/ROADMAP.md`'s DROP table once listed Webhooks as out-of-scope ("needs a persisted outbox + retries"); `COMMERCIAL-STRATEGY.md` §6b (2026-06-28) reversed that — event-driven **is** stateless when the webhook fires on the request that caused the event. `WebhooksModule::dispatch` is post-response, synchronous, best-effort: a dead endpoint loses the event (`error_log` only). Only the *guaranteed-delivery* variant (outbox + retries) stays dropped. Don't "fix" the missing retries by adding a queue.

## Tests & Tooling

- Core PHP tests: `packages/core/tests/{unit,integration}/*.php`, e2e `tests/e2e/test-api.sh` + env-gated `tests/e2e/test-s3-live.php` + self-booting `tests/e2e/test-stream-http.php` / `test-img-http.php` / `test-virus-http.php` (start their own `php -S`, back up/restore `packages/core/.env`, need `curl`). **All `tests/e2e/*-http.php` now run in CI** via the `selfboot-e2e` job, which also boots an `atmoz/sftp` container so `test-sftp-http.php` + `test-sftp-live.php` actually execute — SFTP was previously the one driver with no CI at all. A new self-booting test is picked up automatically if it matches `*-http.php`; give it an unused port (8099/8101/8103/8104/8105/8107, share uses 8110-8112). Browser tests: `tests/browser` (Playwright on the standalone `/public/` UI, incl. `media-preview.spec.ts`). Run: `for f in packages/core/tests/unit/*.php packages/core/tests/integration/*.php; do php "$f"; done`.
- **Real-adapter e2e** (debugging the embedded UI through the framework wrappers, vs `tests/browser` which hits the standalone `/public`): `packages/core/tests/apps/` drives the ACTUAL `@fluxfiles/{react,vue}` wrappers, a real Laravel proxy app, and the real WordPress plugin against a live core iframe. React/Vue/Laravel run together (`npm run setup:laravel` once → `npm run e2e`, 4 webServers: core 8088 + Vite 5173/5174 + artisan 8000). WordPress is separate (Docker via `@wordpress/env`): `npm run setup:wp` → `npm run e2e:wp`. The generated `laravel-app/` is gitignored (regen: `laravel-e2e/setup.sh`); WP mounts the `build-wordpress.sh` artifact. See `packages/core/tests/apps/README.md`.
- Each wrapper owns its tests: `packages/{sdk,react,vue,ckeditor4,tinymce,summernote}/tests` (vitest), `packages/{wordpress,laravel}/tests/test-*-smoke.php` (stubbed PHP). `scripts/pack-smoke.sh` verifies published dist/types.
- **Adapter↔core floor guard**: `scripts/check-adapter-core-floor.sh` (CI job `adapter-core-floor`) runs each PHP adapter's smoke against core built at the *floor* its `composer.json` declares (via a `core-vX.Y.Z` worktree + `FLUXFILES_CORE_AUTOLOAD`). The plain smokes load the live (newest) core, so they can't catch an adapter using a core API newer than its constraint — this guard does, automatically, so the constraint stays honest without anyone remembering to bump it.
- Docker: `docker/Dockerfile` (`ARG PHP_VERSION`, runs the suite), `docker/Dockerfile.prod` (nginx+php-fpm), `docker-compose.yml` (app + MinIO), `Makefile` (`make test`/`test-all`/`up`). CI is `.github/workflows/test.yml` (12 jobs).
