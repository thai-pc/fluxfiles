# Changelog

All notable changes to FluxFiles are documented here. This project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- **Identical re-uploads are kept as a copy (behavior change).** Content dedup
  (SHA-256) is now **opt-in** via the new `dedupe_uploads` claim (default off) — by
  default an identical upload is kept as `name (1).ext` like Finder / Google Drive /
  Dropbox, instead of being silently refused. Enable the claim to refuse
  byte-for-byte duplicates and save storage.
- **Keep-both counter is now ` (1)`, ` (2)`** (was `-1`, `-2`) — matching Finder /
  Drive / Windows. `force_upload` continues to mean "overwrite in place".

### Fixed

- **Image variants no longer collide across extensions.** `a.jpg` and `a.png`
  shared `_variants/a_thumb.webp` and overwrote each other's thumbnails. Variant
  keys now include the full filename (`a.jpg_thumb.webp` vs `a.png_thumb.webp`).
  Legacy extension-less variants are orphaned (harmless cache) and cleaned on delete.
- **Proxied error messages now localise.** The Laravel/WordPress proxy dropped the
  core's `error_code`/`error_params`, so the embedded UI always showed English (e.g.
  folder-exists / name-conflict) through an adapter. Both are now forwarded.

### Added

- **Self-hosted update channel (`UpdateClient` + `fluxfiles update`).** Deliver +
  auto-update paid modules without a third-party platform fee. `fluxfiles update
  <module> [--check]` asks the vendor update server (`FLUXFILES_UPDATE_URL`) for an
  Ed25519-**signed manifest** (using `FLUXFILES_LICENSE_KEY`), verifies the manifest
  + the zip's `sha256`, and installs into `vendor/fluxfiles/<module>/` (Zip-Slip
  guarded, atomic swap). A reference server is in `docs/update-server.example.php`;
  WordPress can use Plugin Update Checker against the same endpoint for wp-admin
  one-click updates. Runtime stays offline — this only pulls new builds.
- **License enforcement model (`perpetual` | `subscription`).** `LicenseManager`
  reads an `enforcement` field so monthly / annual / lifetime can coexist on one
  offline verifier. `perpetual` (default) — the module keeps running after expiry,
  only updates stop (`updatesAllowed()`); `status()` → `perpetual`. `subscription`
  — the module disables past grace (402), as before. `/api/fm/license` now returns
  `enforcement` + `updates_allowed`. This makes "annual with perpetual fallback"
  (the standard self-host model) and lifetime licences safe to sell.
- **Optimization — AVIF target + per-tenant tuning claims.** Beyond the M3 AVIF
  engine, the target format is now selectable per tenant via `optimize_format`
  (`webp` default | `avif`, falling back to WebP when the build lacks AVIF) — wired
  through both the `/api/fm/optimize` UI action and on-upload auto-optimize.
  Three more tuning claims complete the Phase-2 set (request body still overrides):
  `optimize_keep_original` (default false), `optimize_max_mb` (skip oversized files →
  `skipped:too_large`, 0 = no limit), and `pdf_level`
  (`screen|ebook|printer|prepress|default`, default `ebook`). All forwarded by every
  token helper (embed/Laravel/WordPress/Node) + SDK types.

## [0.2.42] — 2026-06-24

> Released: core `core-v0.2.37` (Packagist + Docker), Laravel `laravel-v0.2.20`,
> WordPress `wordpress-v0.2.21`, SDK `fluxfiles` `0.2.4`, React `@fluxfiles/react`
> `0.2.6`, Vue `@fluxfiles/vue` `0.2.5`, Node `@fluxfiles/node` `0.1.14`.
> Adapter core floors → `^0.2.37`.

### Added

- **Optimization M2 — on-upload auto-optimize + savings surface.** A new
  `auto_optimize` claim (with `optimize_quality`) recompresses images in the upload
  pipeline before storing (mirrors AI auto-tag on upload); the result carries
  `optimized` + `saved_bytes`, and the UI shows a savings toast. The usage
  dashboard renders an **Optimization savings** section from the storage-resident
  counter (`OptimizeStats`, `_fluxfiles/optimize.json`, no DB) already returned by
  `GET /api/fm/usage`. The `/api/fm/optimize` endpoint also accepts `paths[]`
  (batch, capped at 200 — one bad file is reported per-item, the rest proceed).
  New `optimize` i18n namespace ×16 locales. *(The compression engine stays in the
  proprietary `fluxfiles/optimize` package; this is the core/UI surface.)*
- **Optimization M3 — AVIF + PDF.** Core gains an AVIF encode primitive
  (`ImageCompat::avifSupported()`/`encodeAvif()`) and `ImageOptimizer::transform()`
  takes an optional target format (`webp`|`avif`, falling back to WebP when the
  build lacks AVIF). The optimize module can now also compress **PDFs** via
  Ghostscript (availability-gated → `501` when `gs` is absent; hardened shell-out:
  arg-array, `-dSAFER`, temp sandbox, timeout). The UI's Optimize action now covers
  PDFs too.
- **`fluxfiles serve` + autoload tolerance.** The standalone entrypoints no longer
  hard-require `../vendor/autoload.php`, so the package runs correctly whether it's
  a monorepo checkout/zip **or** installed as a Composer dependency. New
  `php vendor/bin/fluxfiles serve --host --port` launches the standalone app from
  an installed dependency; the README documents the two usage modes.

### Changed

- **Upload name-collision policy (behavior change).** A same-name upload of
  *different* content used to silently overwrite. It now **keeps both** by default
  (`<name>-1.<ext>`, `-2`…), matching Google Drive / Dropbox / WordPress. Tunable
  per tenant via the new `upload_collision` claim: `rename` (default) | `reject`
  (409 `name_conflict`) | `overwrite` (old behavior). Content dedup (by SHA-256)
  still takes precedence — an identical re-upload is reported as a duplicate.
- **Dotfiles are hidden by default (behavior change).** Files/folders whose name
  starts with `.` (`.env`, `.gitignore`, `.git/`…) no longer appear in listings or
  search — matching Finder / cPanel / Nextcloud, and keeping a `.env` out of search
  results. Opt back in per tenant with the new `show_hidden` claim.
- **`mkdir` on an existing folder now returns `409 folder_exists`** instead of a
  silent `200` (Flysystem's `createDirectory` is idempotent). The OS reports a
  conflict there, and so do we; `error.folder_exists` added ×16 locales.

### Fixed

- **Modal Close button is consistent across adapters.** The React/Vue
  `FluxFilesModal` used a black circular × at the top-right; it now matches the
  browser SDK overlay (Laravel/WordPress) — a macOS-style window with a grey header
  and a red traffic-light dot that reveals a faint × on hover.

### Tests

- **Real-adapter Playwright e2e** (`packages/core/tests/apps/`) driving the actual
  `@fluxfiles/{react,vue}` wrappers, a real Laravel proxy app, and the real
  WordPress plugin against a live core iframe. The standalone browser suite is now
  serialized (`workers:1`) to remove cross-file flakiness on the single-threaded
  `php -S`.

## [0.2.40] — 2026-06-22

> Released: core `core-v0.2.35` (Packagist + Docker), Laravel `laravel-v0.2.18`,
> WordPress `wordpress-v0.2.19`. (Adapter core floors → `^0.2.35`.)

### Added

- **License verifier (`LicenseManager`)** — foundation for the commercial editions
  (Pro / AI / Enterprise). An **offline** Ed25519 verifier in the MIT core: it
  embeds FluxFiles' **public** signing key only (the private key stays offline),
  verifies a compact signed license key, and exposes
  `edition()/modules()/licensed($m)/status()/expired()/inGrace()/daysLeft()`.
  Anything absent/malformed/tampered/expired-past-grace → the **free** edition,
  never an error — the MIT core always runs unlicensed. 14-day default grace.
  This is layer 2 of the capability/license/claim gate (a paid feature also needs
  its proprietary module installed **and** the JWT claim).
- `GET /api/fm/license` → a non-sensitive `{edition, status, modules, limits,
  expires, days_left}` summary for dashboards; proxied by Laravel + WordPress.

### Removed

- Dropped two stale planning docs — `docs/FLUXFILES-ROADMAP.md` (superseded by
  `ROADMAP.md`) and `docs/TEST-PLAN.md` (no longer reflected the shipped test
  suite).

## [0.2.41] — 2026-06-22

> Released: core `core-v0.2.36` (Packagist + Docker), Laravel `laravel-v0.2.19`,
> WordPress `wordpress-v0.2.20`, Node `@fluxfiles/node` `0.1.13`. Adapter core
> floors → `^0.2.36`.

### Added

- **Paid-module gate (`ModuleInterface` + `ModuleRegistry`)** — the reusable seam
  for commercial add-ons, built on the licensing M0 verifier. A paid endpoint is
  served only when **all three** layers pass: the module's code is installed
  (`class_exists` → else `501 module_not_installed`), the license entitles it
  (`LicenseManager` → `402 license_required`/`license_expired`), and the token
  carries the claim (`Claims::isAllowed` → `403`). Free MIT core ships none of the
  module packages, so paid endpoints 501 cleanly.
- **`POST /api/fm/optimize`** (route + `allow_optimize` claim, default false) — the
  first paid module: image → smaller WebP (same dimensions, EXIF stripped), gated
  as above. The engine ships in a separate **proprietary** `fluxfiles/optimize`
  package (not in this MIT repo); the core only holds the gate + route. Proxied by
  Laravel + WordPress; `allow_optimize` forwarded through every token helper.

## [0.2.39] — 2026-06-22

> Released: core `core-v0.2.34` (Packagist + Docker). Adapters unchanged
> (inherit via `^0.2.30`).

### Docs

- **SFTP: document passphrase-protected private keys.** RSA/ED25519 keys with a
  passphrase were already supported end-to-end (`SFTP_PRIVATE_KEY_PASSPHRASE` env,
  BYOB `private_key_passphrase`, decrypted by phpseclib at connect time) but only
  appeared in the env-vars reference table. The SFTP section + BYOB example now
  call it out. Added `test-sftp-passphrase.php` (plumbing + a real ED25519/RSA
  passphrase key decrypting via the provider's exact phpseclib path).

## [0.2.38] — 2026-06-21

> Released: core `core-v0.2.33` (Packagist + Docker), Laravel `laravel-v0.2.17`,
> WordPress `wordpress-v0.2.18` (lock → core 0.2.33). Node inherits via `^0.2.30`.

### Fixed

- **Cache-busting for the UI assets.** `fm.js` / `fm.css` were referenced with a
  fixed URL, so after a core update browsers (and proxies) kept serving the stale
  cached build. The PHP that serves `public/index.html` now appends a per-file
  **content hash** (`?v=<md5>`) to each asset URL and marks the (dynamic) HTML
  `Cache-Control: no-cache` — so an update always loads the new CSS/JS. WordPress
  already did this; the **core standalone and the Laravel adapter** now do too.

### Changed

- **Brand: the primary color is now purple `#8957e5`.** The core UI accent
  (`--ff-primary` — buttons / links / focus ring, light + dark), the logo, and the
  favicon all move to `#8957e5`; the marketing site adopts the same accent. One
  brand across the embedded app and the landing.

## [0.2.37] — 2026-06-21

> Released: core `core-v0.2.32` (Packagist + Docker), SDK `@fluxfiles/sdk` `0.2.3`,
> React `@fluxfiles/react` `0.2.4`, Vue `@fluxfiles/vue` `0.2.3`, WordPress
> `wordpress-v0.2.17` (lock → core 0.2.32). Laravel/Node inherit via `^0.2.30`.

### Added

- **Zoomable image preview** — the fullscreen image lightbox now zooms (wheel /
  click / +−, 100%–500%, snapped to the cursor), pans by dragging, and has a
  control bar with a reset (⟲). Escape resets then closes.
- **Whole-app fullscreen** — a fullscreen button (next to theme/usage) maximises
  the file manager like a video's fullscreen button (Fullscreen API). Every iframe
  surface (SDK, React, Vue, WordPress) now grants `allow="…; fullscreen"` so it
  works embedded. New `toolbar.fullscreen` + `zoom` i18n namespaces ×16.

### Changed

- **`.ff-logo` now leads with the FluxFiles glyph** (the favicon's indigo
  folder-check SVG) before the wordmark.
- **`FM_SELECT` now carries `img_base` / `img_srcset` / `img_sizes`**, so a host
  that picks an image can build any size via `FluxFiles.imgUrl(file, {width,
  quality})` or use the responsive `srcset` — not just the fixed `variants`. (The
  on-demand fields were previously only in `list()`.)

## [0.2.36] — 2026-06-21

> Released: core `core-v0.2.31` (Packagist + Docker). WordPress lock refreshed;
> Laravel/Node inherit it via `^0.2.30` (no API change).

### Changed

- **UI: prefer "Download ZIP" over the broken multi-file download.** A multi-file
  or folder selection used to fire one `<a download>` per file — which browsers
  block as N parallel downloads, and which can't handle a folder (no direct URL).
  Now a multi-selection (or any folder pick) shows a single **Download ZIP**; the
  individual **Download** stays for a lone file (and is the fallback when
  `allow_zip` is off).

## [0.2.35] — 2026-06-21

> Released: core `core-v0.2.30` (Packagist), Laravel `laravel-v0.2.16`,
> WordPress plugin `wordpress-v0.2.15`, Node `@fluxfiles/node` `0.1.12`.

### Added

- **Zip / Extract** (synchronous — no queue/worker, which would need a DB).
  - **`POST /api/fm/zip`** `{disk, paths[], name?}` streams a `.zip` of the
    selected files **and folders** (recursive), constant-memory via
    `maennchen/zipstream-php` (each entry piped through Flysystem → local/S3/R2/
    SFTP). Read perm + `allow_zip` + `allow_download`; `owner_only` enforced,
    system files skipped; pre-flight `413` over `zip_max_mb`/`zip_max_files`
    before any byte. Binary streaming → **core-standalone** (unproxied, like
    `stream`/`img`).
  - **`POST /api/fm/extract`** `{disk, path, dest?}` extracts a `.zip` in place.
    Two-pass = **atomic** (validate every entry → then write; one bad entry
    aborts everything). Hardened: **zip-slip** (absolute/`..`/drive-letter
    rejected), **zip-bomb** (uncompressed-size + entry-count caps), **quota**
    (on the total uncompressed), and the always-on **dangerous-extension** block
    + `ext` allowlist. Returns JSON → **proxied** by Laravel/WordPress.
  - Claims `allow_zip` / `allow_extract` (default true), `zip_max_mb` (1024),
    `zip_max_files` (10000), forwarded through every token helper. UI gains a
    **Download ZIP** action (selection toolbar + folders) and an **Extract**
    action on `.zip` files; new `zip` i18n namespace in all 16 locales.

## [0.2.34] — 2026-06-21

> Released: core `core-v0.2.29` (Packagist), Laravel `laravel-v0.2.15`,
> WordPress plugin `wordpress-v0.2.14`, Node `@fluxfiles/node` `0.1.11`.

### Added

- **Responsive `srcset`** — every image entry in `list()` now carries a
  ready-to-use **`img_srcset`** string (and optional **`img_sizes`**) alongside
  `img_base`, so a host can drop a responsive image straight from the listing.
  Pure metadata on top of the shipped `/api/fm/img` endpoint — no image is read.
  - New **`srcset_widths`** claim (`int[]`, default `[320,640,768,1024,1366,1920]`):
    snapped to 100px (the endpoint's cache grain), clamped to `webp_max_width`,
    deduped, sorted, capped at 12. **`srcset_sizes`** (string) → emits `img_sizes`.
  - Candidate widths are **capped at the image's natural width** (from the stored
    dimensions, zero extra I/O) and the source width is always offered, so a
    browser never requests an upscale (`transform` only scales down). Images
    `<100px` wide get no `img_srcset`. Rides the exact `img_base` gate.
  - Both claims forwarded through every token helper
    (`fluxfiles_token`/Laravel/WordPress/`@fluxfiles/node`); `img_srcset`/`img_sizes`
    added to the SDK/React/Vue `FluxFile` types. The standalone UI wires
    `srcset`/`sizes` onto its detail-panel and lightbox image previews.

## [0.2.33] — 2026-06-21

> Released: core `core-v0.2.28` (Packagist), Laravel `laravel-v0.2.14`,
> WordPress plugin `wordpress-v0.2.13`, Node `@fluxfiles/node` `0.1.10`.

### Added

- **Config / code editor** — edit a file's **text** content in place (the cPanel
  "Edit" use case: `wp-config.php`, `.env`, `nginx.conf`, `deploy.sh`). Works on
  **any** disk (local / S3 / R2 / SFTP) via Flysystem.
  - `GET /api/fm/content?disk=&path=` → `{path, content, size}` (read perm;
    binary → 415, > 5 MB → 413). `PUT /api/fm/content {disk, path, content}`
    overwrites an **existing** file (write perm; missing → 404, oversize → 413).
  - Gated by the new **`allow_code_edit` claim, default `false`** (opt-in):
    rewriting a config/executable is effectively code execution, so existing
    tokens can't suddenly do it. The always-on dangerous-extension *upload* block
    is deliberately **not** applied to editing (editing `.php`/`.sh` is the point),
    but the `ext`/`allowed_ext` policy and `prefix` scope still apply — the claim
    is the lock. Editing **only existing files** (no creating new executables).
  - UI: an **Edit** button in the file detail panel for text files, opening a
    CodeMirror 5 editor (lazy-loaded from CDN, syntax by extension, dark theme,
    `Ctrl/⌘+S` to save); falls back to a plain `<textarea>` if the CDN is blocked.
    New i18n `editor` namespace in all 16 locales.
  - Unlike SFTP-only chmod, `/content` is disk-agnostic and therefore **proxied**
    by both Laravel and WordPress. `allow_code_edit` forwarded through every token
    helper (`fluxfiles_token`/Laravel/WordPress/`@fluxfiles/node`).

## [0.2.32] — 2026-06-21

> Released: core `core-v0.2.27` (Packagist), Laravel `laravel-v0.2.13`,
> WordPress plugin `wordpress-v0.2.12`, Node `@fluxfiles/node` `0.1.9`.

### Added

- **SFTP file permissions (chmod)** — cPanel-style Unix permissions for files on
  an SFTP disk (S3 has no Unix modes).
  - `GET /api/fm/chmod?disk=&path=` reads the 3-digit octal mode (read perm);
    `POST /api/fm/chmod {disk, path, mode}` sets it (write perm + the new
    `allow_chmod` claim, default true → an SFTP token can be made read-only).
    Octal-validated (`0?[0-7]{3}`), not recursive, SFTP-only (non-SFTP → 400).
  - Built on raw phpseclib chmod via `DiskManager::sftpConnection()` (Flysystem
    only models coarse public/private visibility); the host stays SSRF-checked.
  - UI: a "Permissions" dialog (Owner/Group/World × Read/Write/Execute, bound live
    to the octal value), shown only on an SFTP disk when `allow_chmod` is set. New
    i18n `chmod` namespace in all 16 locales. `list()` now returns `disk_driver`
    so the UI can offer driver-specific actions.
  - `allow_chmod` forwarded through every token helper. Like SFTP serving, chmod
    is a core-standalone route (the Laravel/WordPress proxies don't expose SFTP).

## [0.2.31] — 2026-06-21

> Released: core `core-v0.2.26` (Packagist), Node `@fluxfiles/node` `0.1.8`.

### Added

- **SFTP disk driver** — a 3rd storage driver (after local and S3/R2) that turns
  FluxFiles into a file manager for a VPS / shared host. Built on
  `league/flysystem-sftp-v3`; **connect/disconnect per request** (no pool, no DB).
  - Configured via `SFTP_*` env (registers an `sftp` disk when `SFTP_HOST` is set)
    or **BYOB** (a token carrying the user's own SFTP credentials —
    `fluxfiles_byob_token`/`createByobToken`, AES-256-GCM-encrypted, SSRF-checked
    on decode). Password OR private-key auth.
  - **No static/presigned URL**: downloads/previews stream through the app via a
    tokened `/api/fm/stream` link (reuses the gated-local-media path; Range not
    advertised — SFTP is for browsing/editing, not media seeking). Chunk upload
    and presign reject SFTP (S3-only); uploads go direct (cap per-file with the
    `max_upload` claim). Bandwidth runs through the app server.
  - **SSRF-guarded**: `SsrfGuard::assertHostSafe()` rejects loopback / RFC1918 /
    link-local / CGNAT / cloud-metadata hosts. `FLUXFILES_SSRF_ALLOW_HOSTS`
    allowlists a trusted host on a private network (VPN'd VPS).
  - Serving is a **core-standalone / Docker** feature (it streams bytes); the
    Laravel/WordPress proxies don't expose it.

### Security

- Bumped `guzzlehttp/guzzle` 7.10.5→7.12.1, `guzzlehttp/psr7` →2.12.1,
  `mtdowling/jmespath.php` →2.9.1 (4 pre-existing AWS-SDK transitive-dep CVEs).
  `composer audit` is clean.

## [0.2.30] — 2026-06-20

> Released: core `core-v0.2.25` (Packagist), Laravel `laravel-v0.2.12`,
> WordPress plugin `wordpress-v0.2.11`, Node `@fluxfiles/node` `0.1.7`.

### Added

- **Storage usage dashboard** (`GET /api/fm/usage`). Returns a breakdown for the
  token's prefix — quota status, size/count by type, and the largest folders —
  computed **on the fly** (no DB, no usage history).
  - **One pass**: the breakdown is computed in the same recursive listing the
    quota check already runs (type by extension, no per-file MIME lookup);
    `_fluxfiles/`/`_variants/` are excluded. `status` = ok / warning (≥70%) /
    critical (≥90%).
  - **Cache, not DB**: cached per prefix in `_fluxfiles/usage.json` for
    `usage_cache_ttl`s (default 15 min; 0 disables). `?refresh=true` recomputes
    (rate-limited to 2/min). The cache lives under `_fluxfiles/`, so it's excluded
    from its own breakdown.
  - **UI**: a dashboard panel (toolbar button) with a status-coloured quota meter,
    a by-type chart, and a clickable top-folders list that navigates into the
    folder. i18n in all 16 locales.
  - Claims `usage_cache_ttl` / `_warning_threshold` / `_critical_threshold` /
    `_top_folders_count` / `_folder_depth`, forwarded through every token helper.
    Proxied by the Laravel adapter. `quota_limit` stays in the JWT, so a quota
    change applies on the tenant's next token (token-is-config).

## [0.2.29] — 2026-06-20

> Released: core `core-v0.2.24` (Packagist), Laravel `laravel-v0.2.11`,
> WordPress plugin `wordpress-v0.2.10`, Node `@fluxfiles/node` `0.1.6`.

### Added

- **Watermark** (preview protection for content sellers). The on-demand WebP
  endpoint (`/api/fm/img`) can overlay a text or logo watermark **on the fly** —
  the source file is never modified, so there's one source of truth.
  - **Logo is a file**, not DB config: upload a transparent PNG and point
    `watermark_logo_path` at it (re-upload to change). A missing/unsafe logo path
    **falls back to a text watermark** (with `X-FluxFiles-Warning`) — never to a
    clean image. Built on intervention/image v3 + GD (no Imagick); ships a bundled
    DejaVuSans font for text. Watermarked output is cached in `_variants/` keyed by
    config + logo mtime.
  - **Preview-only gating** via `allow_download` (default true). When `false`,
    `list()` withholds `url`/`permanent_url`/`variants` for files and GET presign
    returns `403`, leaving only the watermarked `img_base` — so a preview client
    can't bypass the watermark by grabbing the clean original. Issue a separate
    `allow_download => true` token (e.g. after purchase) for the clean file.
  - Claims `watermark_enabled`/`_type`/`_text`/`_logo_path`/`_position`/`_opacity`/
    `_font_size` + `allow_download`, sanitized/clamped on decode and forwarded
    through every token helper. A non-WebP client, an SVG/animated source, or a
    `?watermark=` override can't make the endpoint hand back a clean image.

## [0.2.28] — 2026-06-20

> Released: core `core-v0.2.23` (Packagist).

### Changed

- **On-demand WebP cache-hit on S3/R2 now 302-redirects** to a presigned URL of
  the cached WebP, so the bucket serves the bytes directly instead of the app
  server reading them back and proxying them (no app egress on repeat views).
  Local disks still serve the cached bytes directly (a local read is cheap).
  New `DiskManager::presignGetUrl()` helper. Verified end-to-end against live S3.

## [0.2.27] — 2026-06-20

> Released: core `core-v0.2.22` (Packagist), Laravel `laravel-v0.2.10`,
> WordPress plugin `wordpress-v0.2.9`, Node `@fluxfiles/node` `0.1.5`,
> SDK `fluxfiles` `0.2.2` (npm).

### Added

- **On-demand WebP** (`GET /api/fm/img`). Extends the existing upload-time variant
  system (fixed thumb/medium/large) with arbitrary on-demand sizes for responsive
  images. Each image entry in a listing gains an **`img_base`** URL; the host
  builds a transform with `FluxFiles.imgUrl(file, { width: 800, quality: 80 })`.
  - First request converts + caches the WebP into the file's `_variants/` dir
    (so the existing delete/trash cleanup invalidates it for free); the cache key
    is mtime-stamped, so a re-upload never re-matches a stale image.
  - **Abuse-bounded**: width is rounded to 100px and clamped to `webp_max_width`,
    quality snaps to `60`/`75`/`80`/`90` — so a single file can only ever spawn a
    bounded number of cache variants (no `?width=801,802,…` growth), without any
    per-request counting.
  - **Content negotiation**: `?format=auto` serves the original to browsers that
    don't accept `image/webp`. **SVG and animated GIFs are never converted.** A
    decompression-bomb guard refuses to decode absurd source dimensions.
  - Claims `webp_enabled` (default true), `webp_max_width` (2000),
    `webp_default_quality` (80), forwarded through every token helper
    (`fluxfiles_token($…, webp: […])`, Laravel/WordPress overrides, Node
    `createToken`). Auth reuses the per-file query-string token pattern
    (`ImageToken`, distinct from the media stream token).

### Security

- `/api/fm/img` reads disk/path from the signed token (never the query), rejects a
  missing/placeholder secret, serves only image files, and forces `nosniff`. A
  stream token can't be used on `/img` and vice versa. Like gated-local media, it's
  a core-standalone / Docker feature (the Laravel/WordPress proxies don't expose it).

## [0.2.26] — 2026-06-20

> Released: core `core-v0.2.21` (Packagist).

### Security

- **Gated media stream hardening** (follow-up to the security review of M4):
  - `/api/fm/stream` now rejects a missing/placeholder `FLUXFILES_SECRET` with
    `500` (consistency with the main API, which is already disabled in that state)
    instead of attempting to verify tokens.
  - Documented the stream endpoint's protections (signed disk/path, realpath
    containment, `local`-`private`-only, forced download + `nosniff` for
    non-inline types) in the Security table, and the deliberate query-string
    token tradeoff (single-file scope + short `stream_token_ttl`; intentionally
    not per-request rate-limited so HTTP Range seeking isn't throttled).

## [0.2.25] — 2026-06-20

> Released: core `core-v0.2.20` (Packagist), Laravel `laravel-v0.2.9`,
> WordPress plugin `wordpress-v0.2.8`.

### Added

- **Configurable presigned-URL lifetime.** The presigned GET-URL TTL on a private
  S3/R2 disk is now settable via `AWS_URL_TTL` / `R2_URL_TTL` (core + Laravel) and
  the `fluxfiles_s3_url_ttl` / `fluxfiles_r2_url_ttl` options (WordPress). Default
  stays **1 hour** (was effectively hardcoded), max 24h. Media files still override
  per tenant with `preview_url_ttl`. The core already honoured the `url_ttl` disk
  key — this exposes it through config/env.

### Docs

- Documented that a selected file's `url` is a presigned link that **expires after
  `url_ttl` (default 1h)** — for saved/embedded content use `permanent_url`
  (public disk or `public_url`/CDN). Added `AWS_URL_TTL` / `R2_URL_TTL` to the env
  table and `.env.example`.

## [0.2.24] — 2026-06-20

> Released: core `core-v0.2.19` (Packagist), Laravel `laravel-v0.2.8`,
> WordPress plugin `wordpress-v0.2.7`, Node `@fluxfiles/node` `0.1.4`.

### Added

- **Media preview hardening + gated local streaming.** Inline video/audio
  preview already existed; this makes it robust and configurable:
  - **Auto-refresh** an expiring presigned media URL: when a `<video>`/`<audio>`
    errors mid-playback the UI silently re-presigns and swaps the source,
    restoring the playhead — so a long video on a private S3/R2 bucket no longer
    403s mid-stream. Retry-capped; static URLs are left alone.
  - **`media_preview`** (bool, default true) toggles inline preview;
    **`preview_url_ttl`** (default 7200s) gives media files a longer presigned
    TTL; **`max_preview_mb`** (default 500) shows a "too large" placeholder +
    download instead of loading a huge player.
  - **Gated local media** (`FLUXFILES_LOCAL_PRIVATE=true`): a local disk serves
    files through per-file `/api/fm/stream` tokens (short-lived, single-file,
    HTTP Range–capable) instead of static URLs, so a `<video>` can't be opened
    without authorization yet still seeks. Production path uses nginx
    `X-Accel-Redirect` (`FLUXFILES_XACCEL`) for zero-copy native Range; PHP
    `fseek` fallback otherwise. New claim **`stream_token_ttl`** (default 3600).
  - All four media claims forward through `fluxfiles_token($…, media: […])`,
    Laravel/WordPress `token()` overrides, and Node `createToken({ mediaPreview,
    … })`. New i18n key `file.too_large_preview` in all 16 locales.

### Security

- The stream endpoint reads disk/path from the *signed* token (never the query),
  realpath-contains the file in the disk root (symlink/traversal guard), serves
  only `local` `private` disks, and forces non-inline types to download with
  `nosniff`. Verified by unit + integration + HTTP e2e tests (Range/206, 416,
  bad/missing token → 403, traversal → 403).

## [0.2.23] — 2026-06-17

> Released: core `core-v0.2.18` (Packagist), Laravel `laravel-v0.2.7`,
> WordPress plugin `wordpress-v0.2.6`, CKEditor 4 `ckeditor4-v0.3.1`,
> TinyMCE `tinymce-v0.3.1`, Summernote `summernote-v0.1.1` (npm).

### Fixed

- **Disk config keys weren't reachable through the adapters.** The core
  consumes `endpoint`, `visibility` and `public_url` on a disk, but the Laravel
  and WordPress adapters didn't surface them — so the S3-compatible
  (MinIO/Spaces), public-disk and CDN/`public_url` flows silently fell back to
  private native-AWS through those adapters.
  - Laravel `config/fluxfiles.php`: `s3` gains `AWS_ENDPOINT` / `AWS_VISIBILITY`
    / `AWS_PUBLIC_URL`; `r2` gains `R2_VISIBILITY` / `R2_PUBLIC_URL`.
  - WordPress: `diskConfigs()` reads the matching options, with new admin
    fields (S3 endpoint/visibility/public_url, R2 visibility/public_url).
- **Core standalone `s3` disk had no custom-endpoint var.** `config/disks.php`
  now reads `AWS_ENDPOINT` (empty = native AWS), so the bundled MinIO / any
  S3-compatible can be targeted without editing the file. `DiskManager` already
  supported the `endpoint` key.
- **Editor pickers dropped `theme` and `disks`.** The CKEditor 4, TinyMCE and
  Summernote plugins now forward both to `FluxFiles.open()`, so an editor can
  match the picker to its light/dark theme and expose multiple disks.

### Added

- **`packages/core/.env.example`** ships with the core package (it loads `.env`
  from the package root), covering the full env set — `FLUXFILES_*`, `AWS_*`,
  `R2_*` — verified to match exactly what the code reads.

### Docs

- Completed the root README **Environment Variables** table: `AWS_ENDPOINT`,
  `AWS_VISIBILITY` / `AWS_PUBLIC_URL`, `R2_VISIBILITY` / `R2_PUBLIC_URL` and the
  four `FLUXFILES_IMPORT_*` defaults were previously undocumented. Laravel README
  gains a cloud-storage env block; editor READMEs document `theme` / `disks`.

## [0.2.22] — 2026-06-17

> Released: core `core-v0.2.17` (Packagist), Laravel `laravel-v0.2.6`,
> WordPress plugin `wordpress-v0.2.5`, Node `@fluxfiles/node` `0.1.3`.

### Fixed

- **Import from URL could not be enabled through the documented token helpers.**
  Every minting helper used a fixed whitelist and silently dropped the import
  claims, so `allow_url_import` (and friends) never reached the JWT — the feature
  only worked if you hand-crafted a raw token. All helpers now forward the six
  import claims:
  - **Core** `fluxfiles_token()` / `fluxfiles_byob_token()` / `fluxfiles_mixed_token()`
    gain an `$import` array param (e.g. `['allow_url_import' => true, 'max_import_mb' => 20]`).
  - **Laravel** `FluxFiles::token()` / `tokenWithByob()` pass the claims through
    the override array.
  - **WordPress** `FluxFilesPlugin::generateToken()` now forwards per-tenant
    claims (it previously dropped `ai_auto_tag` / rate / `variants` too), and a new
    **`fluxfiles_token_overrides` filter** lets sites enable import for the
    built-in shortcode / media button / REST proxy without a custom token caller.
  - **Node** `createToken()` / `createByobToken()` accept `allowUrlImport`,
    `maxImportMb`, `importUrlAllowlist`, `importPath`, `importRateLimit`,
    `importConcurrency`.

### Docs

- New **"Import from URL"** sections in the root, Laravel, WordPress and Node
  READMEs show how to enable the feature per tenant (it's a token claim — nothing
  to install server-side) and document the `FLUXFILES_IMPORT_*` server defaults.

## [0.2.21] — 2026-06-16

> Released: core `core-v0.2.16` (Packagist).

### Changed

- **URL-import size limit is now in MB** for consistency with `max_upload` /
  `max_storage`. The claim `max_import_size` (bytes) → **`max_import_mb`** (MB),
  and the env `FLUXFILES_IMPORT_MAX_BYTES` → **`FLUXFILES_IMPORT_MAX_MB`** (MB,
  default 50). The byte-based names shipped only in 0.2.15 (opt-in, brand new), so
  this is a clean rename rather than a migration.

## [0.2.20] — 2026-06-16

> Released: core `core-v0.2.15` (Packagist), Laravel `laravel-v0.2.5`,
> WordPress plugin `wordpress-v0.2.4`.

### Added

- **Import from URL** (`POST /api/fm/import-url`). The server fetches a public URL
  and saves it like a normal upload — no download-then-reupload. **Opt-in per
  tenant** via the `allow_url_import` claim (default off, so the route is inert
  until enabled). Reuses the upload pipeline (quota, dedup, variants, AI-tag,
  metadata) and adds a dedicated SSRF guard (`SsrfGuard`):
  - blocks loopback / RFC1918 / link-local / CGNAT / cloud-metadata / IPv6
    ULA / IPv4-mapped IPv6, plus decimal/hex IP obfuscation, on the URL **and
    every redirect hop**, with a post-connect IP re-check (DNS-rebinding);
  - streams to a temp file with a hard size cap, magic-byte MIME (never trusts
    the remote Content-Type) with an executable/markup deny-list, SVG denied
    unless `FLUXFILES_IMPORT_ALLOW_SVG=true`.
  - Claims: `allow_url_import`, `max_import_size`, `import_url_allowlist`
    (host globs), `import_path`, `import_rate_limit` (default 10/min, its own
    bucket), `import_concurrency`. UI: a gated "Import URL" toolbar button +
    dialog (16 locales). Proxied by the Laravel and WordPress adapters.
  - The BYOB endpoint SSRF check now shares `SsrfGuard`, gaining the same
    IPv6/CGNAT/mapped-address coverage.

## [0.2.19] — 2026-06-15

> Released: TinyMCE `tinymce-v0.3.0`, CKEditor 4 `ckeditor4-v0.3.0` (npm).

### Added (editor plugins)

- **Native image-dialog integration (the "CKFinder" pattern).** Besides the
  standalone toolbar button, the editor plugins now plug FluxFiles into the
  editor's own Insert/Edit Image dialog:
  - **TinyMCE** registers a `file_picker_callback` (+ `file_picker_types`), so the
    native image/link/media dialog's Source field gets a browse icon that opens
    FluxFiles and fills Source/Alt/Width/Height. Opt out with
    `fluxfiles_image_dialog: false`; a host-provided `file_picker_callback` is
    respected. Verified on TinyMCE 4 and 5.
  - **CKEditor 4** injects a **Browse FluxFiles** button into the Image Properties
    dialog (inline, no popup) that fills URL/Alt/Width/Height. Opt out with
    `fluxfiles: { imageDialog: false }`.
  - **Summernote** has no native browse hook, so its toolbar button remains the
    integration point (documented).
  Both standalone buttons are unchanged. Verified end-to-end with Playwright.

## [0.2.18] — 2026-06-15

> Released: core `core-v0.2.14` (Packagist).

### Fixed

- **Expired-session "Retry" was a no-op.** When the session expired, the load-error
  Retry button just re-ran the same request with the dead token and never re-asked
  the host for a fresh one. Two causes: the token-refresh attempt budget was spent
  by concurrent 401s (each parallel request counted, instead of one per refresh
  cycle), and a manual Retry never reset that budget. Now concurrent 401s coalesce
  *before* counting, and Retry (`retryLoad`) resets the budget, re-requests a token,
  and reloads — recovering once a valid token arrives. A pushed `FM_TOKEN_UPDATED`
  also recovers a broken view. Verified end-to-end (embedded iframe + host refresh)
  with a new Playwright regression test.

### Packaging

- **Ship a `LICENSE` file in every package.** The MIT `LICENSE` lived only at the
  repo root and in a few wrappers, so the published `fluxfiles/fluxfiles` (and
  `wordpress`, `sdk`, `ckeditor4`, `tinymce`, `summernote`) packages carried the
  `license` field but no license text. Added `LICENSE` to all packages and the
  npm `files` allow-lists.

## [0.2.17] — 2026-06-15

> Released: Summernote plugin `summernote-v0.1.0` (npm).

### Added

- **Summernote editor plugin (`@fluxfiles/summernote`).** A new jQuery/Summernote
  adapter, mirroring the CKEditor 4 / TinyMCE plugins: a toolbar button opens the
  FluxFiles picker and inserts the selection (`<img>` for images, `<a>` otherwise)
  via `editor.pasteHTML`, preferring `permanent_url` and warning on presigned
  URLs. Registered via `$.summernote.plugins`; configured with a `fluxfiles`
  options object. The plugin saves/restores the editing range around the modal so
  content lands at the cursor. Ships `plugin.min.js`; a manual test page lives at
  `tests/manual/test-summernote.html`.

## [0.2.16] — 2026-06-14

> Released: core `core-v0.2.13` (Packagist).

### Added (UI)

- **Sidebar folder tree (lazy expand/collapse).** The sidebar is now a real
  file-explorer tree: chevrons open/close branches (lazily fetched and cached),
  the path to the current folder auto-expands, and the current folder is
  highlighted. Replaces the flat ancestor-trail list.
- **Drag a file/folder onto a folder to move it.** Drag a card (or a whole
  multi-selection) onto a folder card or a sidebar tree node to move it there;
  the target highlights on hover, and moving a folder into itself/a descendant is
  rejected. (OS file drops still go to the upload dropzone.)
- **Loading skeleton.** Switching disk/folder shows shimmer placeholders instead
  of a blank flash.
- **Persistent load-error state with Retry.** When a folder fails to load it now
  shows a clear error + a Retry button instead of looking like an empty folder
  once the toast fades. New `error.load_failed`, `error.move_into_self`,
  `common.retry`/`expand`/`collapse` strings added across all 16 locales.

## [0.2.15] — 2026-06-14

> Released: core `core-v0.2.12` (Packagist).

### Changed

- **API keys are now relative to the token `prefix`** (the prefix is the tenant's
  root and stays invisible). For a path-scoped token (`prefix=users/42/`), `list`,
  `search`, `search-folders`, `upload`, `rename`, `move`/`copy`, cross-disk
  copy/move, `restore` and trash listings return keys with the prefix **stripped**
  — on-disk `users/42/reports` comes back as `reports`. Fixes the "phantom root"
  where the breadcrumb/sidebar showed `Root › users › 42 › reports` and a fake
  "Root" sat above the real (prefix) root. Input still accepts relative **or**
  absolute paths (scoping is idempotent), and `url`/`permanent_url` keep the real
  prefixed storage path. **Migration:** if you stored the absolute `key` (with
  prefix) from a scoped token, it is now returned relative — re-derive or
  re-scope. Unscoped tokens (no prefix) are unaffected.

### Added

- **Sidebar folder tree shows the ancestor trail** to the current folder
  (root → … → parent, indented), then the current folder + its children — the
  vertical twin of the breadcrumb. Reuses `breadcrumbs`; no extra API calls.

## [0.2.14] — 2026-06-14

> Released: Laravel adapter `laravel-v0.2.4` (Packagist).

### Fixed

- **Laravel proxy returned 404 for `disk/doctor` and the whole `trash/*` family.**
  The proxy is an explicit route allow-list and these core routes were never
  added, so Bucket Doctor and soft-delete/restore/empty 404'd in proxy mode.
  Added the 6 routes + controller handlers (`diskDoctor`, `trash`, `trashRestore`,
  `trashList`, `trashPurge`, `trashEmpty`), mirroring the core endpoints (same
  permission/validation; BYOB disks registered before the Doctor probes).

### Tests

- The Laravel smoke now **diffs core's `/api/fm/*` route surface against the proxy**
  and fails if a core route isn't proxied (would have caught this gap), plus a
  check that every proxied route maps to a real controller method.

## [0.2.13] — 2026-06-14

> Released: React `react-v0.2.3`, Vue `vue-v0.2.2` (npm).

### Added

- **React/Vue `FluxFile` type now declares `created`** (Unix seconds) alongside
  the existing `modified`. The field was already present at runtime on selection
  payloads and list rows; this just types it so editors autocomplete it. The
  selection examples in both READMEs mention `created`/`modified`.

### Docs / CI

- **Packagist sync lag fixed at the source.** `split.yml` now pings the Packagist
  update API right after the subtree push, so a release shows up immediately
  instead of waiting for the slow periodic crawl. Requires the `PACKAGIST_USERNAME`
  + `PACKAGIST_TOKEN` repo secrets (documented in the README); no-op without them.
- Guide-file review: `.claude/api-map.md` now documents the `/trash/*` routes +
  `/disk/doctor` (it had wrongly said no trash/config routes existed) and notes
  per-tenant config lives in JWT claims; `docs/TEST-PLAN.md` drops a stale FTS5
  reference (FluxFiles has no SQLite).

## [0.2.12] — 2026-06-14

> Released: core `core-v0.2.11` (Packagist), WordPress plugin `wordpress-v0.2.3`.

### Added

- **Search results now show the created date**, consistent with the browse views.
  `searchFolders()` returns `created` for folder hits, and the search result cards
  (files and folders) render the date.

### Changed

- **WordPress adapter requires core `^0.2.11`** (was `^0.2.0`) — like the Laravel
  adapter, so installing/updating `fluxfiles/wordpress` pulls a core new enough
  for the storage-permission fix, per-tenant claims, search sort and created dates
  (the UI the plugin serves comes from that core).

### Tests

- New dedicated `test-search.php` (name/metadata matching, highlight, `created`/
  `size`/`modified` on rows, folder search + `created`, `_fluxfiles`/`_variants`
  exclusion, prefix scoping) and `test-rate-limit.php` (limit enforcement, the
  429 `rate_limited` code, separate read/write buckets, per-user independence,
  the higher per-tenant `rate_read` limit).

## [0.2.11] — 2026-06-13

> Released: core `core-v0.2.10` (Packagist).

### Added

- **A stable `created` timestamp on files _and_ folders, shown in the UI.** Both
  list and grid views now display the created date for files and folders. The
  timestamp is stored as FluxFiles' own metadata (file index + a new `dirs.json`
  map), so — unlike a storage mtime — it's **stable** (doesn't change when a
  folder's contents change) and **works on S3 / R2** too, where prefixes have no
  native timestamp. `list()` exposes `created` on every entry; date sorting now
  keys on `created` (falling back to the live `modified`), which also fixes
  folder date-sorting on S3/R2.

  The folder index (`_fluxfiles/dirs.json`) migrates from a list of keys to a
  `{ "dir/key": <created ts> }` map; the loader still reads the old shape, and a
  reindex backfills `created` for pre-existing files (best-estimated from mtime).
  Folders not created through FluxFiles (e.g. raw S3 prefixes) simply show no
  date until tracked.

## [0.2.10] — 2026-06-13

> Released: core `core-v0.2.9` (Packagist), Laravel `laravel-v0.2.3`.

### Fixed

- **Search results now honour the active sort.** Previously a search rendered its
  matches in raw index order, ignoring the Name/Date/Size selection — so the
  ordering looked random next to the sorted browse view. The picker now sorts
  search results (files and folders) by the chosen key + direction. To make
  Date/Size sorting possible there, the search index now stores each file's
  `size` and `modified`; results from an older index without them fall back to
  name. Run a reindex (`php artisan fluxfiles:seed …` / the indexer) to backfill
  existing files — new uploads carry it automatically.

### Changed

- **`fluxfiles/laravel` now requires core `^0.2.8`** (was `^0.2.0`), so
  `composer require fluxfiles/laravel` pulls a core new enough for the per-tenant
  claims and the folder-date / search-sort fixes instead of leaving an old core
  installed.

## [0.2.9] — 2026-06-13

> Released: Laravel `laravel-v0.2.2`.

### Fixed

- **Laravel adapter no longer fatals when the core is older than 0.2.8.**
  `laravel-v0.2.1` called `FluxFiles\Claims::sanitizeVariants()` — a core 0.2.8
  method — from `FluxFilesManager::token()`, so an install whose `fluxfiles/fluxfiles`
  hadn't been updated crashed with *"Call to undefined method …sanitizeVariants()"*.
  The adapter now passes the `variants` claim through inline (the core re-sanitizes
  it on decode) and reads the `rate_read`/`rate_write` claims defensively (`?? 0`),
  so a version mismatch degrades gracefully instead of 500-ing. The per-tenant
  `ai_auto_tag` / rate / `variants` overrides still require core ≥ 0.2.8 to take
  effect; on an older core they're ignored.

## [0.2.8] — 2026-06-13

> Released: core `core-v0.2.8` (Packagist), Laravel `laravel-v0.2.1`, npm
> `@fluxfiles/node` `node-v0.1.2`.

### Added

- **Per-tenant config claims.** Three settings that used to be server-global can
  now be set **per token**, enforced server-side, each inheriting the server
  default when the claim is unset:
  - `ai_auto_tag` (bool) — turn AI auto-tagging on/off per tenant. The AI
    provider and API key stay server-side; only the on/off switch is per token.
  - `rate_read` / `rate_write` (int, req/min) — per-tenant API rate limits;
    `0` inherits `FLUXFILES_RATE_LIMIT_READ` / `_WRITE`.
  - `variants` (object) — per-tenant WebP variant widths (`thumb`/`medium`/`large`,
    16–8000 px); unset names inherit the `150`/`768`/`1920` defaults.

  Issued via the PHP `fluxfiles_token()` helper, `@fluxfiles/node`'s
  `createToken()` (camelCase `aiAutoTag` / `rateRead` / `rateWrite` / `variants`),
  and the Laravel `FluxFiles::token([...])` overrides. Tokens stay byte-compatible
  across PHP and Node; unset claims are omitted so existing tokens are unaffected.
  The Laravel adapter also gained `rate_limit_read` / `rate_limit_write` config.

## [0.2.7] — 2026-06-13

> Released: core `core-v0.2.7` (Packagist).

### Changed

- **The file manager now sorts by date, newest first, by default** (was name,
  A→Z). With many folders or files it's far easier to scan recent uploads; the
  choice is still user-overridable and remembered per browser. Directory
  listings now also carry a `modified` timestamp where the storage adapter
  exposes one (local does; S3/R2 prefixes don't), so **folders** sort by date as
  well — previously only files had a timestamp.

### Fixed

- **An unwritable storage directory now returns a clear, actionable error**
  instead of a cryptic 500. When the uploads dir isn't writable by the web
  server user, FluxFiles responds with `storage_not_writable` (500), naming the
  path and the remediation. Under Laravel, a bare `fopen()`/`mkdir()` warning was
  being promoted to a fatal `ErrorException` before the core's best-effort lock
  guard could run; the warnings in `acquireIndexLock` and the rate limiter are
  now suppressed and converted to a proper exception. (Field report:
  `fopen(.../_fluxfiles/index.lock): Permission denied`.)

## [0.2.6] — 2026-06-06

> Released: core `core-v0.2.6` (Packagist); npm `fluxfiles` (SDK) `sdk-v0.2.1`,
> `@fluxfiles/react` `react-v0.2.2`, `@fluxfiles/vue` `vue-v0.2.1`,
> `@fluxfiles/ckeditor4` `ckeditor4-v0.2.3`, `@fluxfiles/tinymce` `tinymce-v0.2.2`.

### Added

- **`width` / `height` / `mime` on file listings and the select payload.** Image
  dimensions and MIME type are captured at upload and stored in metadata, so
  listings and the `FM_SELECT` payload expose them directly (no extra `/meta`
  call). Reflected in the React / Vue / SDK `FluxFile` types.
- **`permanent_url` on file listings and the select payload.** A stable,
  non-expiring URL for embedding (local disks, public disks, or any disk with a
  `public_url`); `null` for a private bucket with no public domain. The editor
  plugins prefer it over the presigned `url`, so saved content doesn't embed an
  expiring link.

### Fixed

- **CKEditor 4 & TinyMCE plugins now use the rich callback data.** Inserted
  images use `meta.alt_text` for `alt` (was the filename), detect images by MIME
  (not just extension), and set `width`/`height` to avoid layout shift. Folders
  are skipped. **They also warn when inserting a presigned (expiring) URL** — a
  private-disk URL embedded in saved editor content breaks once it expires; use a
  public disk or `public_url` for editor embedding.

### Docs

- Documented embedding with `permanent_url` vs the presigned `url` — the root
  README gains an "Embedding selected files" section, and the React/Vue selection
  examples prefer `permanent_url` for saved content.

## [0.2.5] — 2026-06-06

> Released: core `core-v0.2.5` (Packagist), WordPress plugin
> `wordpress-v0.2.2` (bundles core 0.2.5).

### Added

- **Trash / Restore (soft-delete).** Deleting a file **or folder** now moves it
  (variants and, for folders, the whole subtree included) into a reserved,
  restorable trash namespace (`_fluxfiles/trash/<id>/` + a `_fluxfiles/trash.json`
  manifest) instead of destroying it. New endpoints `POST /api/fm/trash`,
  `/trash/restore`, `/trash/purge`, `/trash/empty` and `GET /trash/list` (gated
  by the `delete` permission; scoped to the token's prefix/owner). The UI
  soft-deletes everything and adds a **Trash** panel (restore / delete forever /
  empty); restore re-creates metadata and re-tracks the folder index. `/delete`
  stays permanent (purge/API). Storage-resident, no central DB — fits the
  stateless model.

## [0.2.4] — 2026-06-05

> Released: core `core-v0.2.4` (Packagist), `@fluxfiles/node` 0.1.1 (npm),
> WordPress plugin `wordpress-v0.2.1` (bundles core 0.2.4).

### Security

- **The activity log is now scoped to the token's path prefix.** `GET
  /api/fm/audit` previously filtered entries only by user id; it now also scopes
  them to the caller's prefix via `Claims::isPathInScope()` (default-deny for
  out-of-scope or keyless entries) and is gated behind a new **`audit`
  permission** (off by default). Defense-in-depth so a tenant can never read
  another tenant's activity from a shared disk's log.

### Added

- **Activity log panel + filters.** The audit endpoint gained `action` / `from`
  / `to` / `path` / `actor` filters, and the embedded UI has an Activity panel
  (shown when the token holds the `audit` perm) with a "stored in your own
  storage" note for the BYOB trust story.
- **Bucket Doctor.** New `GET /api/fm/disk/doctor` diagnoses an S3/R2 disk
  (reachability, read/write/delete, presigned GET, multipart, CORS, versioning)
  with the disk's own credentials and returns a report plus IAM/CORS remediation
  snippets; checks needing extra permissions degrade to warnings, so it never
  demands more access than FluxFiles itself. An in-app "Bucket health" panel
  surfaces it (write-gated). Built for BYOB onboarding — a host can run it on an
  ephemeral token to validate credentials before issuing a long-lived one.

### Fixed

- **Multipart uploads now record their file key in the audit log** (it was blank
  because multipart requests carry no JSON body to read the path from).

### Changed

- **`@fluxfiles/node` docs (republish).** Clarified that the package only
  *issues tokens* — a running FluxFiles **core service** (the PHP file backend)
  is still required for the tokens to authenticate against. The React / Vue / SDK
  Requirements now separate "core service" from "token minting" (which works from
  any backend). Added an npm badge, a `LICENSE` file, and `sideEffects: false`.

## [0.2.3] — 2026-06-04

> Released: core `core-v0.2.3` (Packagist), `@fluxfiles/react` 0.2.1,
> `@fluxfiles/node` 0.1.0 (npm).

### Added

- **New `@fluxfiles/node` — server-side token SDK.** Zero-dependency Node/TS
  package that mints FluxFiles JWTs (plain + BYOB) from any JS backend
  (Express, Next.js, Nuxt, NestJS), byte-compatible with the PHP core so non-PHP
  apps can issue tokens. `createToken` / `createByobToken` mirror the PHP helpers
  exactly; BYOB credentials use the same HKDF-SHA256 + AES-256-GCM scheme as
  `CredentialEncryptor`. Cross-language tests assert tokens decode in the PHP core
  and BYOB blobs round-trip both ways.
- **`@fluxfiles/react` now works in the Next.js App Router out of the box.** The
  package ships the `"use client"` directive (added via a tsup banner), so
  `<FluxFiles>` / `<FluxFilesModal>` can be imported directly into a Server
  Component. Components were already SSR-safe (they only touch `window`/`document`
  inside effects). A **Next.js** section was added to the React README.

### Security

- **A file's extension is now immutable across relocation.** `rename`, `move`,
  `copy` and the cross-disk variants take a caller-controlled destination
  filename, so they could change a file's extension (e.g. `a.png → a.svg`) and
  bypass the upload `allowedExt` policy, while drifting the stored MIME/variants
  from the real type. Renaming a file now edits the base name only (the
  extension is fixed → `400 ext_changed` on change), and move/copy additionally
  re-check `allowedExt` (`403 ext_not_allowed`) — a scoped token can no longer
  relocate a file out of its allowed types. Directories and extensionless files
  keep whole-name edits.

### Fixed

- **No more flash of raw translation keys on load.** In production the public UI
  could render i18n keys (e.g. `toolbar.upload`) before `/api/fm/lang` resolved,
  because nginx served `public/index.html` statically (`try_files $uri …`) and
  skipped the server-side `window.__FM_LOCALE__` injection. The UI is now routed
  through PHP via exact-match `rewrite` (matching the already-correct Apache
  rule), and the frontend gained defense-in-depth: an `x-cloak` boot overlay
  hides the app until messages are ready, so statically-served pages degrade to a
  brief spinner instead of showing keys.
- **Rename dialog locks the file extension.** The modal shows the extension as a
  locked suffix beside the input; only the base name is editable.
- **Internal directories no longer appear in search.** `_fluxfiles/` and
  `_variants/` (at any depth) are excluded from both file and folder search
  results — previously image-variant folders leaked into folder search.
- **Explicit theme from the host/URL wins over a saved preference.** `?theme=` /
  `FM_CONFIG` `theme` now overrides a stored `localStorage` choice (without
  persisting), so an embed matches the host app; the saved choice resurfaces when
  the host stops forcing a theme.

### Changed

- Added `rename.ext_locked` and `error.ext_changed` strings to all 16 locales.

## [0.2.2] — 2026-06-02

> Released: core `core-v0.2.2` (Packagist), `@fluxfiles/ckeditor4` 0.2.2,
> `@fluxfiles/tinymce` 0.2.1 (npm).

### Added

- **Minified builds for the editor plugins.** `@fluxfiles/ckeditor4` and
  `@fluxfiles/tinymce` now ship `plugin.min.js` (~1.3 KB / ~1.8 KB) alongside the
  readable `plugin.js`, served by the dev router and resolved by jsDelivr/unpkg.
  `npm run build` (esbuild) regenerates them. Mirrors the SDK's `fluxfiles.min.js`.

### Fixed

- **Long folder names in the sidebar tree no longer wrap to two lines.** The
  `tree-item`/`tree-item-child` label now truncates to a single line with an
  ellipsis (`…`) and shows the full name via `title` on hover; the row height
  stays fixed.

### Security

- **Cross-tenant paths now fail closed (403) instead of silently sandboxing.**
  When a token's `prefix` has a parent (e.g. `users/42` → parent `users`), a
  request targeting a sibling tenant under that parent (`users/99/…`) is rejected
  with `403 path_denied` in `scopePath()`/`scopedPath()` — applied to list,
  navigate, and every mutating op — rather than mapping to an empty phantom
  folder. (It was never a leak — `user_1` could never reach `user_2`'s files —
  but the explicit error is clearer and consistent with the metadata endpoints.)
  Relative paths and in-scope absolute keys are unaffected. A flat single-segment
  prefix (`user_1`) has no parent, so `user_2/…` remains indistinguishable from a
  real subfolder and stays sandboxed — use a parented prefix (`users/{id}`) for
  explicit cross-tenant rejection.

### Changed

- **Core runtime-state directory is env-configurable.** `FLUXFILES_STORAGE_PATH`
  (optional; defaults to `packages/core/storage`) lets read-only/immutable
  deployments point the rate-limit state file at a writable volume. Matches the
  Laravel adapter, which already had it.

## [0.2.1] — 2026-06-02

### Changed

- **CKEditor 4 toolbar icon is now an inline SVG**, matching the TinyMCE plugin —
  the same folder glyph as a data-URI SVG instead of a bundled `icons/fluxfiles.png`.
  Drops the PNG file, the sprite-based `icons`/`hidpi` plugin props, and the
  `icons` entry in `package.json`. Both editor plugins are now visually in sync
  and ship no separate image asset.

### Fixed

- **Duplicated network requests when opening the manager (iframe/modal).** The
  standalone page had both Alpine's automatic `init()` call **and** an explicit
  `x-init="init()"` on the same element, so `init()` ran twice → two `message`
  listeners → every `FM_CONFIG` was handled twice, firing `list` + `quota` +
  `lang` twice. Combined with chatty wrappers (React/Vue re-renders sending the
  config 2–3×) this multiplied into the ~4–6× duplicate requests seen in the
  network panel. Removed the redundant `x-init`, and added an **idempotency
  guard** to the `FM_CONFIG` handler so a repeated/identical config no longer
  re-fetches — a real change (token/disk/path/locale/endpoint) still reloads.
  Regression test asserts exactly one `list`/`quota`/`lang` per config even when
  the host sends FM_CONFIG three times.

## [0.2.0] — 2026-06-02

### Added

- **`max_files` — limit the number of files.** New JWT claim `max_files` (token
  param `maxFiles`, `0` = unlimited) caps the **total** user-visible files under
  a prefix; exceeding it returns **413 `too_many_files`** on both the normal and
  chunked upload paths (`QuotaManager::getFileCount()` skips internal
  `_fluxfiles/`/`_variants/`). The SDK/React/Vue/editor `maxFiles` option also caps
  a single drop/selection batch client-side. Wired through every package
  (core/SDK/React/Vue/CKEditor4/TinyMCE/Laravel/WordPress) + the standalone URL
  param `?maxFiles=`. Added `error.too_many_files` to all 16 locales.

### Changed

- **Upload-size option standardized on megabytes.** The JS packages now take
  **`maxUploadMb`** (MB) — matching the server's `max_upload` claim — instead of
  the bytes-based `maxSize`. `maxSize` is kept as a **deprecated alias** (auto
  converted to MB) so existing integrations keep working. The standalone UI now
  **actually enforces** the per-file size client-side (it was a dead option
  before): an oversized file is rejected with a toast before any bytes are sent.
  Standalone URL param `?maxUploadMb=` added.

- **`multiple` option filled in where it was missing.** Laravel `config/fluxfiles.php`
  gains a `multiple` UI default (the `<x-fluxfiles>` component falls back to it),
  and the WordPress `[fluxfiles]` shortcode (`multiple="1"`) + media button
  (`fluxfiles_picker_multiple` option) now support multi-select. SDK/React/Vue/
  CKEditor4/TinyMCE already had it.

- **`fluxfiles_token()` can now set the storage quota.** Added a `maxStorageMb`
  parameter (megabytes; `0` = unlimited) that writes the `max_storage` claim — the
  claim was enforced but the core helper had no way to set it.

### Fixed

- **CI: the SDK wrapper test failed on Linux** with
  `Cannot find module @rollup/rollup-linux-x64-gnu`. A `packages/sdk/package-lock.json`
  generated on macOS had been committed; it pinned only the darwin rollup native
  binary, so `npm install` on the Linux runner skipped the linux one
  ([npm/cli#4828](https://github.com/npm/cli/issues/4828)) and vitest crashed at
  startup. The lockfile is now untracked + gitignored (it isn't needed for a
  published lib), so CI resolves platform-correct optional deps from a fresh
  install. (`react`'s committed lock already lists all platforms; `vue`'s pulls no
  native rollup — both unaffected.)

### Documentation

- README documents the exact **units** for every token parameter — `maxUploadMb`
  and `maxStorageMb` are **MB**, `ttl` is **seconds** (`exp = iat + ttl`), and
  `allowedExt` entries are bare lowercase extensions (no dot). Added a "Token
  parameters & units" reference table and unit annotations across the token,
  JWT-structure, BYOB, and Laravel examples.
- README production-deployment section now documents the **three upload-size
  layers** (nginx `client_max_body_size`, PHP `upload_max_filesize`/`post_max_size`,
  and the JWT `max_upload`), with the nginx example setting `client_max_body_size`
  and a note that S3/R2 chunked uploads bypass `post_max_size`.

## [0.1.3] — 2026-06-01

### Added

- **Minified SDK build.** The `fluxfiles` package now ships `fluxfiles.min.js`
  (~5 KB, ~half of `fluxfiles.js`) alongside the readable source, served by the
  dev router at `/fluxfiles.min.js` and resolved by jsDelivr/unpkg `npm/fluxfiles`.
  `npm run build` (esbuild) regenerates it.

### Fixed

- **Laravel adapter: upload no longer 500s on a missing/null `path`.**
  `FluxFilesController::upload()` passed `$request->input('path', '')` straight
  into `FileManager::upload(string $path)`; Laravel's `input()` default only
  applies when the key is absent, so a present-but-null `path` yielded `null` →
  an uncaught `TypeError` (HTML 500) *before* the extension check. Now disk/path
  are coerced with `(string) (… ?? '')`, and a catch-all `\Throwable` returns a
  JSON error instead of an HTML page. A disallowed type (e.g. a `.zip` not in
  `allowed_ext`) now correctly returns **403 `ext_not_allowed`** instead of 500.

- **Dropping a file outside the dropzone no longer breaks the app.** Only the
  small `.ff-dropzone` prevented the browser default, so a file (e.g. a `.zip`)
  dropped on the grid or anywhere else made the browser open/navigate to the raw
  file, replacing the whole manager. A global `dragover`/`drop` guard now blocks
  that everywhere and treats a drop anywhere in the manager as an upload; drops on
  the dropzone still upload as before. (Server-side extension rejection was already
  correct — disallowed types return a clean 403 on both the normal and chunked
  upload paths.)

## [0.1.2] — 2026-06-01

### Fixed

- **Subfolders were unreachable when the token had a path `prefix`.** `list()`
  returns full prefixed keys (e.g. `user_1/posts`) and the UI navigates with
  them, but `scopedPath()`/`Claims::scopePath()` prefixed again
  (`user_1/user_1/posts`) so every subfolder came back empty — only the root
  showed, and no images loaded inside folders. Prefixing is now **idempotent**: a
  path already inside the prefix is left as-is. The security boundary is intact —
  `..`/`.` are still stripped first and the `/` boundary blocks prefix confusion
  (`user_1` vs `user_10`), so a foreign path is still sandboxed back into the
  user's prefix (verified: `user_1` cannot list `user_2`). New
  `tests/integration/test-prefix-navigate.php` + `Claims::scopePath` cases lock
  this in.

- **Manual editor test pages now load.** `tests/manual/test-ckeditor4.html` and
  `test-tinymce.html` referenced the SDK at `../fluxfiles.js` and their plugin at
  `../../{pkg}/` — both 404'd after the monorepo restructure. They now use the
  absolute `/fluxfiles.js` + `/{ckeditor4,tinymce}/plugin.js` paths, and the dev
  `router.php` serves those sibling adapter packages. README/test docs updated to
  open the pages through the dev server (not `file://`).

### Changed

- **Package publishing now uses independent tags.** CI no longer treats every `v*` monorepo tag as a release for every package. Composer split packages use `core-v*` / `laravel-v*` tags, and npm packages use `sdk-v*`, `react-v*`, `vue-v*`, `ckeditor4-v*`, or `tinymce-v*` so only the changed package publishes.

- **Real upload progress.** The upload bar now shows true byte-level progress via
  `XMLHttpRequest` (`xhr.upload.onprogress`) instead of a coarse file-count
  percentage — so a single large file no longer sits at 0% then jumps to 100%.
  Overall % is `(completed files + current file's byte fraction) / total`, and
  the bar shows the current file name, an `(n/total)` counter, an animated
  spinner, and a "processing" state once bytes are sent but the server is still
  working (e.g. generating image variants). The new XHR path preserves the api()
  401 token-refresh retry and i18n error-code mapping; chunked S3/R2 uploads
  report part-level progress through the same callback.

## [0.1.1] — 2026-05-31

### Security

- **`?lang=` path traversal blocked.** `I18n::isSupported()` gated a locale only
  via `in_array()`/`file_exists()`, so with `FLUXFILES_LOCALE` unset a crafted
  `?lang=../composer` could traverse the lang dir and load an arbitrary `.json`
  into the injected page. A strict `^[a-z]{2,5}$` guard now runs before the
  `file_exists()` check. (The `/api/fm/lang/{code}` REST route was already
  regex-constrained.)
- **Inline-script JSON hardened.** The locale data embedded in the standalone
  page's inline `<script>` now uses `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`
  (in `I18n::toJson()` and the `locale`/`dir` encodes), so `<`/`>` become
  `\uXXXX` and can never break out with `</script>`.

### Changed

- **Bulk download is now reachable from the UI.** `bulkDownload()` existed but
  had no button wired to it. Added a "Download" action to the desktop toolbar,
  the mobile "more" menu, and the mobile bottom bar; `bulkDownload()` now skips
  folders (which have no direct URL).

### Documentation

- README: new "Uploading multiple files" guide (drag-drop / multi-select dialog,
  sequential upload, vs the picker's `multiple` option); corrected the
  `FM_SELECT` payload fields (no `mime`); added the `GET /api/fm/search-folders`
  route; expanded the Testing section (Playwright browser e2e, wrapper vitest,
  pack-smoke, Docker).

### Tests

- Playwright browser e2e expanded to 13 cases incl. multi-select (`FM_SELECT`
  array) and bulk delete/move/download; new `I18n` traversal-rejection +
  inline-script-safety cases.

## [0.1.0] — 2026-05-30

First public release. FluxFiles is a standalone, embeddable PHP file manager: a
zero-build frontend plus a stateless PHP API that any host app can embed by
iframe or SDK, with framework adapters for Laravel, WordPress, React, Vue/Nuxt,
CKEditor 4, and TinyMCE.

> The `0.1.0` tag re-baselines the project for its first public release. Earlier
> internal `1.x` tags predate this versioning and are not part of the public
> release line.

### Core file manager

- **File operations** — list, upload, download presign, rename, move, copy,
  delete, mkdir, and cross-disk copy/move.
- **Multi-storage via Flysystem v3** — Local, AWS S3, and Cloudflare R2. R2/MinIO
  endpoints are treated as path-style S3.
- **Cursor-based pagination** — `GET /api/fm/list?limit=&cursor=` returns
  `{ items, next_cursor, total }`; omitting `limit` keeps the legacy flat array.
  The UI auto-pages with a "Load more" control.
- **Image processing** — WebP variants (thumb/medium/large) generated on upload,
  plus inline crop with aspect-ratio presets (replace original or save as copy).
- **Duplicate detection** — SHA-based hash index; stale/system-path entries are
  self-healed instead of surfacing phantom duplicates.
- **Optional AI tagging** — Claude / OpenAI-compatible vision providers, auto-tag
  on upload or on demand; tags feed search.
- **Internationalization** — 16 locales with RTL support and CSS logical
  properties; locale passthrough from the SDK and every adapter.
- **Zero-build UI** — Alpine.js + htmx standalone frontend served from
  `packages/core/public`.

### Auth & security

- **JWT (HS256, firebase/php-jwt v7)** with claims for permissions, disks, path
  prefix, upload size, allowed extensions, storage quota, `owner_only`, and BYOB
  disks. HS256 secrets must be ≥ 32 bytes.
- **Centralized enforcement** — disk access, permissions, path scoping
  (`Claims::scopePath()`), `owner_only` (enforced at folder level too via
  `assertOwnsTree`), upload-size, and extension checks live in one place.
- **BYOB (Bring Your Own Bucket)** — storage credentials are AES-256-GCM encrypted
  in the JWT and decrypted only at runtime; never exposed. BYOB endpoints are
  SSRF-guarded (loopback/private/reserved IPs and the cloud metadata address are
  rejected) and local BYOB disks are refused.
- **Upload hardening** — served uploads carry `X-Content-Type-Options: nosniff`,
  a sandbox CSP, and attachment disposition for HTML/SVG/XML; path-traversal is
  blocked. Uploads cannot write into the internal `_fluxfiles/` / `_variants/`
  namespaces.
- **CSRF / origin checks** on mutating requests, with a same-origin fallback when
  `ALLOWED_ORIGINS` is unset.
- **Rate limiting**, **audit log**, and **storage quota** per user.

### Storage-backed metadata

- Metadata travels with user storage — no central database. Local disks use
  sidecars under the protected `_fluxfiles/meta/{key}.json` namespace (so a
  user-uploaded `*.meta.json` can never collide), with legacy `{file}.meta.json`
  sidecars migrated on read. S3/R2 use object metadata. Indexes live in
  `_fluxfiles/index.json` (search), `_fluxfiles/dirs.json` (folder search), and
  `_fluxfiles/audit.jsonl` (audit).

### Adapters

- **Laravel** — service provider, facade, publishable config, route proxy, Blade
  component, JWT middleware, and a `php artisan fluxfiles:seed` command to index
  pre-existing directories.
- **WordPress** — plugin with settings page, `[fluxfiles]` shortcode, editor media
  button, REST proxy at `/wp-json/fluxfiles/v1/`, and a `wp fluxfiles seed`
  WP-CLI command.
- **React** — `<FluxFiles>`, `<FluxFilesModal>`, and the `useFluxFiles` hook.
- **Vue 3 / Nuxt** — component + composable, including `onTokenRefresh`.
- **CKEditor 4** and **TinyMCE** — editor integrations.
- **Browser SDK** — `FluxFiles` global that manages the iframe modal and the
  `postMessage` protocol (`FM_READY` → `FM_CONFIG`, `FM_SELECT`, token refresh,
  commands, events, close).

### Tooling & tests

- **Core PHP** — unit/integration suites plus an API e2e script and an env-gated
  live S3/R2 test (MinIO/AWS/R2).
- **Browser e2e (Playwright + chromium)** — render/auth smoke and full UI
  interaction coverage: upload, folder create + breadcrumb navigation, search,
  dark-mode toggle, delete, inline crop (save-as-copy), single-pick `FM_SELECT`,
  multi-select (`multiple:true`) returning an `FM_SELECT` array, and bulk
  operations (multi-select delete + move + download).
- **Wrapper tests** — vitest for the JS adapters, stubbed-PHP smokes for
  WordPress/Laravel, and a pack-&-install smoke that typechecks published
  tarballs.
- **Docker** — dev/test image (PHP 8.1–8.4 matrix), production image
  (nginx + php-fpm), and a compose stack with MinIO.
- **CI** — GitHub Actions across core PHP, API e2e, live S3 (MinIO), wrappers,
  browser e2e, pack-smoke, and the production Docker image.

### Requirements

- PHP **8.1+** across `core`, `laravel`, and `wordpress`.
