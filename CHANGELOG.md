# Changelog

All notable changes to FluxFiles are documented here.

---

## [Unreleased]

### Pagination (Core + Adapters)

- `**/api/fm/list` pagination** — endpoint now accepts optional `?limit=&cursor=` query params. When `limit > 0`, response shape becomes `{ items, next_cursor, total }`; without `limit` the endpoint still returns a flat array (backward compatible for older third-party integrations).
- **Cursor-based** — listings are sorted deterministically (dirs first, then files, both by key ASC) so a cursor equal to the last item's key yields a stable next page.
- **Metadata merge scoped to page** — metadata/variants are now merged only for the entries actually returned in each page, reducing per-request work for large folders.
- **Frontend auto-pages** — `fm.js` now requests `limit=1000` by default and renders a "Load more" button (plus a `Showing X of Y` meta line) when `next_cursor` is present. Small folders see no UI difference.
- **Pending-selection across pages** — when navigating into a folder from a global search result, the UI keeps paging until the target file is found (or pages run out).
- **Adapter parity** — `packages/laravel` and `packages/wordpress` controllers now forward `limit` + `cursor` query params.
- **i18n** — added `pagination.load_more`, `pagination.loading_more`, `pagination.showing` keys across all 16 locales.

### Laravel Adapter — Existing Directory Support

- **`php artisan fluxfiles:seed`** — new Artisan command that indexes pre-existing files and folders on a configured disk so they become searchable. Walks the disk recursively, creates a metadata record per file (with `title` derived from filename) and tracks each directory in `_fluxfiles/dirs.json`. Supports `--disk=`, `--path=`, `--overwrite`, `--dry-run`.
- **README — "Using an existing upload directory"** — end-to-end guide for teams who already have a populated upload tree (e.g. `public/uploads/user_1/`): point the `local` disk at the existing path, scope each user with the `prefix` JWT claim derived from `auth()->id()`, set filesystem perms, and run the seed command.

### WordPress Adapter — Existing Directory Support

- **`wp fluxfiles seed`** — WP-CLI command equivalent to the Laravel Artisan seed. Registered only when WP-CLI is loaded (no overhead in normal requests). Same options (`--disk=`, `--path=`, `--overwrite`, `--dry-run`) and same semantics — indexes pre-existing files in `wp-content/fluxfiles/uploads/` (or any configured S3/R2 disk) so FTS5 and folder search return them.
- **`readme.txt` — "Using an existing upload directory"** — documents the WP-CLI flow for sites installed on top of a populated uploads tree, with a fallback note (re-upload through the UI) for hosts without WP-CLI.

### Cross-adapter Fixes

- **Laravel adapter** — added missing `GET search-folders` route + `searchFolders()` controller method so global folder search works through the Laravel proxy.
- **WordPress adapter** — registered missing `/search-folders` REST route + `handleSearchFolders()` handler.
- **SDK `search` command** — calling `FluxFiles.search(q)` from a host app now triggers the debounced global search (previously it only updated the query string, so FTS5 results never loaded unless the user typed manually).
- **Rate limiter in CLI** — `RateLimiterFileStorage::consume()` guards the `Retry-After` header with `headers_sent()`, eliminating PHP warnings when the limiter fires from PHPUnit / CLI tests.
- **Test bootstrap `.env` loading** — `tests/generate-token.php` and `tests/test-byob.php` now try `packages/core/.env` first and fall back to the repo root, so `FLUXFILES_SECRET` is picked up regardless of where the env file lives. Both fail fast with a clear error when the secret is missing.

### i18n

- **14 locales backfilled** — `ar`, `de`, `es`, `fr`, `hi`, `it`, `ja`, `ko`, `nl`, `pt`, `ru`, `th`, `tr`, `zh` received the `sort.*` and `filter.*` blocks that previously only existed in `en` / `vi`. Every locale now has the same 194 keys.
- **Contributing guide rewritten** — `packages/core/lang/CONTRIBUTING.md` walks contributors end-to-end: fork → clone → `i18n/add-{code}` branch → translate → `php tests/test-i18n.php` → push → PR (both `gh pr create` and GitHub UI).

### Monorepo Structure

- **Standardized package layout** — moved code into `packages/*` without rewriting runtime logic:
  - `packages/core` (Composer: `fluxfiles/fluxfiles`)
  - `packages/laravel` (Composer: `fluxfiles/laravel`)
  - `packages/sdk` (npm: `fluxfiles`)
  - `packages/react` / `packages/vue` (npm adapters)
  - `packages/wordpress`, `packages/tinymce`, `packages/ckeditor4`
- **CI paths updated** — npm publish workflow and subtree split workflow updated to point at `packages/*`.
- **Laravel adapter base path** — default lookup now targets `vendor/fluxfiles/fluxfiles` to locate core assets cleanly after the move.
- **Export safety net** — added `.gitattributes` with `export-ignore` rules to avoid shipping dev-only files when archives are built from the monorepo.
- **Docs moved** — `ROADMAP.md` moved to `docs/ROADMAP.md` (root file kept as a pointer).
- **Dev server docs updated** — documentation now runs the PHP dev server from `packages/core` (`php -S ... router.php`).
- **WordPress plugin distribution** — documented **ZIP-first** install (upload / extract a build that already includes `vendor/`); `composer.json` / `composer.lock` target **PHP 8.1+** and are for maintainers building releases. Plugin autoload order: plugin `vendor/`, then monorepo `packages/core/vendor`.
- **PHP minimum raised to 8.1** for `packages/core` and `packages/laravel` Composer metadata — aligns with League Flysystem 3 and Intervention Image v3 (earlier lower minimums did not match what Composer could actually resolve).
- **Documentation** — dropped leftover PHP 7.4 phrasing in `README.md`, `packages/wordpress/readme.txt`, and `CHANGELOG.md` (`[1.22.0]` history + upgrade notice now align with PHP 8.1+).

## [1.26.4] — 2026-04-13

### Sort & Type Filter (Core UI)

- **Sort dropdown (toolbar)** — sort files and folders by `name`, `date`, `size`, or `type`; ascending/descending toggle with a clear directional arrow indicator on the trigger button.
- **Type filter dropdown (toolbar)** — quickly narrow the current folder to `Images`, `Videos`, `Audio`, `Documents`, or `Other`. Defaults to `All`; folders are always shown regardless of filter so navigation isn't blocked.
- **Smart defaults** — choosing `Date` or `Size` starts in **descending** order (newest / largest first); `Name` and `Type` start in **ascending** (A→Z). Clicking the active sort key flips direction.
- **Stable tie-break** — numeric/date/type comparisons fall back to locale-aware name comparison (`localeCompare` with `{ numeric: true }`), so `file2.png` sorts before `file10.png`.
- **Folder-safe sorting** — folders ignore `size`/`type` sorts (no meaningful value) and silently fall back to name; date sort still works for folders using their `modified` timestamp.
- **Persisted per browser** — `sortBy`, `sortDir`, `typeFilter` are saved to `localStorage` (`fluxfiles_sort_by`, `fluxfiles_sort_dir`, `fluxfiles_type_filter`) and restored across sessions.
- **Mobile parity** — on narrow widths the toolbar dropdowns collapse; equivalent **Sort** and **Filter** entries appear inside the existing mobile "More" menu, cycling through options on tap (no nested popovers needed).
- **i18n** — new `sort.*` and `filter.*` message groups added to `en.json` and `vi.json` (other locales fall back gracefully via inline defaults in the UI).
- **Search-compatible** — sort and type filter run on top of the existing local search filter; global (cross-disk) search results are unaffected.

### UX Notes

- The sort button shows both the active key (e.g. `Date`) and a small ↑/↓ arrow; on the dropdown itself, the active row is highlighted and marked with the current direction.
- The type-filter button highlights (active state) whenever the filter is not `All`, so users don't forget they've scoped the view.
- Dropdowns close on outside-click (`@click.away`) and when a choice is made.

## [1.26.3] — 2026-04-10

- **Search (toolbar)** — mobile fullscreen search stopped calling `loadFiles()` on each input (that cleared selection and did not improve filtering). Client-side file filtering now matches `alt_text` and `caption` like title/tags, with safe handling when metadata fields are not strings.

## [1.26.2] — 2026-04-09


| Area      | Change                                                                                                                                                                 |
| --------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Core UI   | Global search now queries `/api/fm/search` (metadata index) instead of only filtering the current folder.                                                              |
| Core UI   | Folder search added via `/api/fm/search-folders` backed by `_fluxfiles/dirs.json` directory index; results include folders and files.                                  |
| Core API  | Directory index is updated best-effort on `upload`, `mkdir`, `move`, `rename`, `delete`, `copy`, `cross-copy`, `cross-move` so folder names like `test2` can be found. |
| Mobile UX | Mobile fullscreen search no longer reloads the current folder on every keystroke (prevents losing selection/detail).                                                   |


## [1.26.1] — 2026-04-08

### Bug Fixes

- **CKEditor 4 plugin path** — test page used relative path in `addExternal()` which resolved against CKEditor's CDN base URL instead of the page URL, causing plugin.js to 404. Fixed by using absolute URL via `new URL()`.
- **TinyMCE 5 missing icon** — `icon: 'browse'` only exists in TinyMCE 4's skin CSS, not in TinyMCE 5's SVG icon set. Button rendered invisible in TinyMCE 5. Fixed by registering a custom folder SVG icon via `editor.ui.registry.addIcon()` for v5+.
- **React adapter missing `updateToken` / `onTokenRefresh`** — `<FluxFiles>` component didn't pass `onTokenRefresh` to the hook and omitted `updateToken` from the imperative handle, causing TS build error. `<FluxFilesModal>` had the same missing `onTokenRefresh` passthrough.
- **React/Vue exports order** — `types` condition in `package.json` exports placed after `import`/`require`, making it unreachable. Moved `types` first per Node.js resolution order.

## [1.26.0] — 2026-04-06

### Owner-Only File Protection

- `**owner_only` JWT claim** — when `true`, delete/rename/move/crop operations are restricted to files uploaded by the token holder
- `**uploaded_by` metadata** — every uploaded file now records the uploader's `userId` in metadata for ownership tracking
- `**assertOwner()` enforcement** — ownership checked on `delete()`, `rename()`, `move()`, `crossMove()`, `cropImage()`; returns 403 if user is not the file owner
- **Graceful legacy fallback** — files uploaded before `owner_only` was enabled (no `uploaded_by` metadata) remain accessible to all users
- `**embed.php` support** — all token functions (`fluxfiles_token`, `fluxfiles_byob_token`, `fluxfiles_mixed_token`) accept `ownerOnly` parameter

### System Path Protection

- `**_fluxfiles/` and `_variants/` blocked** — API returns 403 for any list/delete/rename/move on internal directories
- **Hidden from file listing** — system directories and `.meta.json` files filtered out of `list()` results
- **Error transparency** — generic "This file cannot be modified" message, no internal path details leaked

### Error Localization (i18n)

- `**error_code` + `error_params`** — API returns structured error data (`{ error, error_code, error_params }`) for frontend i18n mapping
- **28 error keys** — all API errors now have translatable codes: `upload_too_large`, `quota_exceeded`, `ext_not_allowed`, `owner_only`, `system_path`, `permission_denied`, `rate_limited`, etc.
- **Dynamic error messages** — `{max}`, `{used}`, `{ext}` placeholders in translations (e.g. "File is too large (max {max})")
- **Frontend error toasts** — `uploadFiles()`, `loadFiles()`, `saveMeta()`, bulk operations now show error toasts instead of silently failing
- **Backwards compatible** — raw `error` string still present for clients not using i18n

### Locale Default Changed to English

- **Default locale is now `en`** — removed auto-detection from browser `Accept-Language` header
- **Explicit locale required** — use `?locale=` / `?lang=` URL param, SDK `locale` option, or `FLUXFILES_LOCALE` env to set language
- **Locale priority:** SDK `locale` > URL param (`?locale=` / `?lang=`) > `FLUXFILES_LOCALE` env > `en`

### URL Parameters (Standalone Mode)

- **Documented URL params** — `token`, `disk`, `disks`, `path`, `locale`/`lang`, `theme`, `multiple` now documented in README
- `**?lang=` alias** — frontend standalone mode now accepts `?lang=` as alias for `?locale=`

### Mobile UX Overhaul

- **Mobile search** — search icon in topbar opens fullscreen search overlay with auto-focus input
- **Sidebar drawer** — upgraded to 300px max, spring animation (`cubic-bezier(0.32, 0.72, 0, 1)`), blur overlay, 44px tap targets
- **File grid** — larger thumbnails (56x48px), 2-line filenames with `word-break`, 10px gap
- **Toolbar "More" menu** — 3-dot button with view toggle, refresh, and contextual actions; replaces inline toolbar buttons on mobile
- **Bottom action bar** — fixed bottom bar when items selected with Delete/Move/Copy/Cancel buttons, safe-area padding
- **Detail panel slide-up** — 90vh fullscreen modal with slide-up animation, drag handle, sticky header, rounded top corners
- **Action sheet** — bottom sheet replaces context menu on mobile; triggered via long-press on file/folder cards
- **Spacing & touch** — increased padding, `-webkit-tap-highlight-color: transparent`, `touch-action: manipulation`
- **Dark mode mobile** — component-specific dark overrides for all new mobile elements

## [1.25.0] — 2026-04-05

### Token Refresh System

- **Automatic token renewal** — when the API returns 401, the iframe requests a fresh JWT from the host app via `FM_TOKEN_REFRESH` / `FM_TOKEN_UPDATED` / `FM_TOKEN_FAILED` postMessage protocol
- **Request coalescing** — multiple concurrent 401s trigger only one refresh request; all waiting requests retry after the new token arrives
- **Retry with circuit breaker** — failed requests auto-retry once with the new token; after 2 consecutive refresh failures, shows the expired screen instead of looping
- **10s timeout** — if the host app doesn't respond within 10 seconds, the iframe falls back to the expired auth screen
- **SDK `onTokenRefresh` callback** — `FluxFiles.open({ onTokenRefresh: async () => fetchNewToken() })` enables automatic renewal in the vanilla JS SDK
- **SDK `updateToken()` method** — `FluxFiles.updateToken(newJwt)` lets the host push a new token proactively (e.g. background refresh timer)
- **Auth screen states** — three visual states: "missing" (no token), "refreshing" (spinner), "expired" (retry button + close)
- **React adapter** — `onTokenRefresh` prop + `updateToken()` on the handle; `TokenRefreshHandler` type exported
- **Vue adapter** — `onTokenRefresh` option + `updateToken()` composable method; `TokenRefreshHandler` type exported
- **Event system** — `auth:refreshed` and `auth:expired` events emitted via `FM_EVENT` for host app observability

## [1.24.0] — 2026-04-05

### Security Fixes

- **[CRITICAL] postMessage origin validation** — SDK (`fluxfiles.js`) and iframe (`fm.js`) now validate `e.origin` on all `message` events. SDK locks to the iframe's origin; iframe locks to the first `FM_CONFIG` sender. Outbound `postMessage` calls target the trusted origin instead of `'*'`.
- **[HIGH] CSRF / Origin header check** — All POST/PUT/DELETE requests now verify the `Origin` header against `FLUXFILES_ALLOWED_ORIGINS`. Requests from unlisted origins are rejected with 403.
- **[MEDIUM] XSS in search highlights** — `StorageMetadataHandler::highlight()` now escapes HTML entities before applying `<mark>` tags, preventing stored XSS via metadata fields.
- **[MEDIUM] SHA-256 replaces MD5** — Duplicate detection now uses `hash_file('sha256')` instead of `md5_file()`.
- **[MEDIUM] Presign parameter validation** — `presign()` now rejects methods other than `GET`/`PUT` and caps TTL at 86400 seconds (24 hours).
- **[MEDIUM] Metadata index file locking** — All read-modify-write operations on `_fluxfiles/index.json` now use `flock(LOCK_EX)` to prevent race conditions under concurrent requests (local disk).
- **[LOW] Rate limit file permissions** — New rate limit files are created with `0600` permissions.
- **[LOW] Audit log rotation** — Audit log (`_fluxfiles/audit.jsonl`) is automatically trimmed to the last 5000 entries when exceeding 5MB.

### Other Changes

- Removed unnecessary `str_contains` polyfill (PHP 8.2+ is the runtime target)
- Updated README.md with comprehensive deployment guide, API reference, and security documentation

## [1.23.0] — 2026-03-30

- **CKEditor 4 adapter** (`packages/ckeditor4/`) — toolbar button, image `<img>` / file `<a>` insert
- **TinyMCE 4/5 adapter** (`packages/tinymce/`) — toolbar button, auto-detects TinyMCE 4 vs 5 API
- **BYOB (Bring Your Own Bucket)** — users connect their own S3/R2 buckets via AES-256-GCM encrypted JWT credentials
- **Metadata storage redesign** — replaced SQLite with storage-based metadata (S3 object metadata / local sidecar JSON), metadata travels with files
- `CredentialEncryptor` — AES-256-GCM encryption for BYOB credentials
- `StorageMetadataHandler` — metadata, search index, audit log stored in user's own bucket
- `fluxfiles_byob_token()` and `fluxfiles_mixed_token()` helper functions in `embed.php`
- Rate limiter switched to file-based storage (`RateLimiterFileStorage`)
- Audit log switched to storage-based (`AuditLogStorage`)
- Added test suites: BYOB, metadata handler, CKEditor 4, TinyMCE
- Bug fixes: multi-select, purge API, rename, UI improvements

## [1.22.0] — 2026-03-15

- Broadened **source-level** PHP compatibility (removed PHP 8-only syntax: match, readonly, constructor promotion, named args, throw expressions, union types) so the tree could target older runtimes at the time
- **Current note:** dependency upgrades (Flysystem 3, Intervention Image 3) mean **PHP 8.1+** is now required for installs via Composer / bundled `vendor/`; see package `composer.json` files

## [1.21.0] — 2026-03-15

- Add **Vue 3 / Nuxt 3** adapter (`@fluxfiles/vue`)
- Components: `FluxFiles.vue`, `FluxFilesModal.vue`, `useFluxFiles` composable
- Nuxt 3 plugin for global component auto-registration

## [1.20.0] — 2026-03-14

- **i18n** — 16 languages (en, vi, zh, ja, ko, fr, de, es, ar, pt, it, ru, th, hi, tr, nl)
- RTL support for Arabic
- Locale priority: SDK `locale` → `?locale=`/`?lang=` URL param → `FLUXFILES_LOCALE` env → `en`
- `I18n.php` backend + Alpine.js `t()` helper

## [1.19.0] — 2026-03-12

- **AI auto-tag** — Claude / OpenAI vision API integration
- `POST /api/fm/ai-tag`: analyze image → suggested alt_text, title, caption, tags
- Auto-tag on upload (opt-in via `FLUXFILES_AI_AUTO_TAG=true`)

## [1.18.0] — 2026-03-10

- **Image crop inline** — Cropper.js in detail panel
- Crop with aspect ratio presets, save as copy or replace original
- Regenerate WebP variants after crop

## [1.17.0] — 2026-03-08

- **Bulk operations** — multi-select with Ctrl+click / checkbox
- Bulk delete, move, copy, download (max 50 files per action)
- `FM_SELECT` returns array when `multiple: true`

## [1.16.0] — 2026-03-06

- **Cross-disk copy/move** — transfer files between Local ↔ S3 ↔ R2
- Server-side stream (no client download), metadata + variants transferred together
- `POST /api/fm/cross-copy`, `POST /api/fm/cross-move`

## [1.15.0] — 2026-03-04

- **React adapter** (`@fluxfiles/react`)
- `<FluxFiles>`, `<FluxFilesModal>`, `useFluxFiles` hook
- TypeScript types, React 18/19

## [1.14.0] — 2026-03-02

- **Laravel adapter** (`fluxfiles/laravel`)
- ServiceProvider + auto-discovery, `<x-fluxfiles>` Blade component
- `FluxFiles::token()` facade, proxy mode + standalone mode

## [1.13.0] — 2026-02-28

- **WordPress plugin**
- Settings page, shortcode `[fluxfiles]`, media button integration
- Gutenberg block + Classic Editor support

## [1.12.0] — 2026-02-25

- **Dark mode** — auto-detect `prefers-color-scheme`, manual toggle
- CSS custom properties for full theming

## [1.11.0] — 2026-02-22

- **FM_EVENT subtypes** — granular events: `upload`, `delete`, `move`, `copy`, `mkdir`, `restore`, `purge`, `crop`, `ai_tag`
- Host app listens via `FluxFiles.on('FM_EVENT', cb)`

## [1.10.0] — 2026-02-20

- **FM_COMMAND** — host app controls iframe programmatically
- Commands: `navigate`, `setDisk`, `refresh`, `search`, `crossCopy`, `crossMove`, `crop`, `aiTag`

## [1.9.0] — 2026-02-18

- **Full-text search** — SQLite FTS5 across file names, titles, alt text, captions, tags
- `GET /api/fm/search?q=...` with ranked results + highlight snippets

## [1.8.0] — 2026-02-15

- **Storage quota per user** — enforced via JWT `max_storage` claim
- `GET /api/fm/quota` returns usage / limit / percentage

## [1.7.0] — 2026-02-12

- **Duplicate detection** — Hash check on upload (upgraded to SHA-256 in v1.24.0)
- Returns existing file URL + warning instead of re-uploading
- `force_upload` option to override

## [1.6.0] — 2026-02-10

- **Audit log** — all write actions logged to `fm_audit` table
- `GET /api/fm/audit` with user_id filter, pagination

## [1.5.0] — 2026-02-08

- **Rate limiting** — token bucket per user (60 reads, 10 writes per minute)
- Returns `429 Too Many Requests` with `Retry-After` header

## [1.4.0] — 2026-02-05

- **Trash / soft delete** — recoverable deletes with `is_trashed` flag
- (legacy note) `POST /api/fm/restore`, `DELETE /api/fm/purge`
- Auto-purge after 30 days

## [1.3.0] — 2026-02-02

- **Chunk upload** — S3 multipart for files > 10MB
- 5MB chunks, parallel upload (max 3), resume on disconnect

## [1.2.0] — 2026-01-30

- **File preview inline** — images, PDF, video, audio in detail panel
- Fallback icon for unsupported types

## [1.1.0] — 2026-01-28

- **Image optimization** — auto WebP variants on upload
- 3 presets: thumb (150px), medium (768px), large (1920px)
- Variants stored in `_variants/` subfolder

## [1.0.0] — 2026-01-25

- Initial release
- Multi-storage: Local disk, AWS S3, Cloudflare R2 (via Flysystem v3)
- JWT HS256 authentication with granular claims
- File operations: upload, download, move, copy, rename, delete, mkdir
- SEO metadata: title, alt text, caption per file
- Alpine.js + htmx frontend, iframe embed via postMessage SDK
- PHP embed helper (`embed.php`)

