# Changelog

All notable changes to FluxFiles are documented here.

---

## [1.26.0] — 2026-04-06

### Owner-Only File Protection

- **`owner_only` JWT claim** — when `true`, delete/rename/move/crop operations are restricted to files uploaded by the token holder
- **`uploaded_by` metadata** — every uploaded file now records the uploader's `userId` in metadata for ownership tracking
- **`assertOwner()` enforcement** — ownership checked on `delete()`, `rename()`, `move()`, `crossMove()`, `cropImage()`; returns 403 if user is not the file owner
- **Graceful legacy fallback** — files uploaded before `owner_only` was enabled (no `uploaded_by` metadata) remain accessible to all users
- **`embed.php` support** — all token functions (`fluxfiles_token`, `fluxfiles_byob_token`, `fluxfiles_mixed_token`) accept `ownerOnly` parameter

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

- **CKEditor 4 adapter** (`adapters/ckeditor4/`) — toolbar button, image `<img>` / file `<a>` insert
- **TinyMCE 4/5 adapter** (`adapters/tinymce/`) — toolbar button, auto-detects TinyMCE 4 vs 5 API
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

- Downgrade PHP requirement from 8.2 to **7.4+** (compatible with PHP 7.4 — 8.3)
- Rewrite all PHP 8.x syntax: match, readonly, constructor promotion, named args, throw expressions, union types

## [1.21.0] — 2026-03-15

- Add **Vue 3 / Nuxt 3** adapter (`@fluxfiles/vue`)
- Components: `FluxFiles.vue`, `FluxFilesModal.vue`, `useFluxFiles` composable
- Nuxt 3 plugin for global component auto-registration

## [1.20.0] — 2026-03-14

- **i18n** — 16 languages (en, vi, zh, ja, ko, fr, de, es, ar, pt, it, ru, th, hi, tr, nl)
- RTL support for Arabic
- Auto-detect locale: FM_CONFIG → URL param → Accept-Language → `en`
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
- `POST /api/fm/restore`, `DELETE /api/fm/purge`
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
