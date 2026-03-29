# Changelog

All notable changes to FluxFiles are documented here.

---

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

- **Duplicate detection** — MD5 hash check on upload
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
