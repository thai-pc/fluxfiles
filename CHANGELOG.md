# Changelog

All notable changes to FluxFiles are documented here. This project adheres to
[Semantic Versioning](https://semver.org/).

## [0.3.00] — 2026-09-01

> Released: `core-v0.2.80`. Laravel/WordPress adapter floors bumped to
> `^0.2.80` — `WpDbMetadataHandler`/`LaravelDbMetadataHandler` implement
> `MigrationImportInterface`, which core only gained at this tag; the
> `Adapter ↔ core floor guard` CI job caught the stale `^0.2.79` floor
> (fatal "Interface not found" against the old worktree).

### Changed — `MetadataRepositoryInterface` widened (BC-break for direct implementers)

Phase 1 of the DB-storage-migration design (`docs/DB-STORAGE-MIGRATION-DESIGN.md`
§2): `MetadataRepositoryInterface` grew from 11 to 26 methods — folder-index
(`trackDir`/`trackParents`/`dirsCreated`/`renameDirPrefix`/`deleteDirPrefix`/
`searchFolders`), audit (`readAudit`/`audit`/`readAuditArchive`/
`purgeAuditBefore`), trash (`allTrash`/`getTrash`/`addTrash`/`removeTrash`),
and `indexFile` — plus a signature fix: `search()` was missing the
`bool $includeHidden = false` parameter it actually needed (production code
was already calling it with 5 args against the concrete class; the interface
had drifted out of sync). **If you implement `MetadataRepositoryInterface`
directly** (rather than using the built-in `StorageMetadataHandler`), you
must now implement these 15 additional methods and the corrected `search()`
signature, or your class will trigger a PHP fatal error.

`FileManager.php`'s 19 `instanceof StorageMetadataHandler` guards (4 of which
threw `trash_unavailable` when the check failed) are removed — the interface
now always guarantees these methods, so trash/audit/folder-indexing are no
longer conditional on the concrete class. No behavior change for anyone using
`StorageMetadataHandler` (the only implementer today); this is the seam a
future DB-backed handler plugs into. `index.php` and the Laravel/WordPress
adapters now type-hint the interface instead of the concrete class at their
metadata-repo consumption points; construction is unchanged. Added
`tests/unit/test-metadata-repository-interface.php`, a reflection-based
conformance guard between the interface and `StorageMetadataHandler`.

### Fixed — ACL role presets: `viewer`/`editor` could chmod on SFTP disks

`docs/ACL-ROLE-PRESETS-DESIGN.md`'s `viewer`/`editor` presets omitted
`allow_extract`/`allow_chmod` on the assumption that an absent claim decodes
false — but `Claims::fromJwtPayload` actually defaults those two specifically
to **true** when absent (unlike `allow_code_edit`/`show_hidden`, which do
default false). A minted `viewer`/`editor` token therefore carried an
unintended `allow_chmod=true`, letting a read-only/contributor role change
file permissions on an SFTP disk. Fixed by setting `allow_extract`/
`allow_chmod` explicitly in all four role-preset builders (`embed.php`,
`packages/node/src/token.ts`, `FluxFilesManager.php`, `FluxFilesPlugin.php`).
Also fixed the test gap that let this ship: `test-role-preset.php` and the
Laravel/WordPress/Node smoke suites only asserted `isset()` on the raw JWT
payload, which can't distinguish "claim absent" from "claim explicitly
false" — they now assert the actual decoded `Claims::fromJwtPayload(...)`
value.

### Fixed — Share Analytics: `embed.php`/Node lacked named claim forwarding

`share_analytics` had named forwarding in the Laravel/WordPress token
builders but only the generic raw-claims escape hatch in `embed.php` and
`@fluxfiles/node` — functionally reachable but inconsistent with the other
three Share landing claims. Added `shareAnalytics` to both, matching
`sharePreview`'s pattern.

## [0.2.99] — 2026-08-31

> Released: `ckeditor4-v0.3.3`, `tinymce-v0.3.3`, `summernote-v0.1.3`,
> `vue-v0.2.9`. No core/Laravel/WordPress change in this entry.

### Fixed — Editor plugins: stored XSS via unescaped `"` in inserted attributes

The CKEditor 4, TinyMCE, and Summernote plugins built `<img src="..." alt="...">`/
`<a href="...">` markup with `CKEDITOR.tools.htmlEncode`-equivalent helpers that
only escape `&`/`<`/`>` — correct for text-node context, not attribute-value
context. A file's `alt_text`/title/URL containing a `"` (settable by any user
who can rename or set metadata on a file the editor operator can browse) could
break out of the attribute and inject arbitrary HTML/JS into the inserted
content — a stored XSS. Fixed by using each editor's attribute-escaping helper
(`htmlEncodeAttr` for CKEditor 4; equivalent inline escapers added for TinyMCE/
Summernote) for every `src`/`href`/`alt`/`title` value, with regression tests
asserting a crafted `alt_text`/URL can no longer break out of its attribute.

### Fixed — Vue: `useFluxFiles` froze props at initial mount

`FluxFiles.vue` passed a plain object literal to `useFluxFiles`, so a `computed`
wrapping mutable props (`token`, `disk`, `locale`, etc.) was never read — prop
changes after mount (e.g. a refreshed token) silently had no effect. Wrapped
the options in a `computed()` so `useFluxFiles`' internal `(options as any).value
?? options` unwrap tracks every prop. Also exposed `setLocale`/`updateToken`
on the component's `defineExpose()`, which existed on the underlying handle
but weren't reachable from a `<FluxFiles>` template ref.

## [0.2.98] — 2026-08-31

> Released: `core-v0.2.79`, `wordpress-v0.2.44`. Laravel untouched by this
> entry — its floor stays `^0.2.77`.

### Fixed — WordPress proxy: gated stream/img URLs 404'd on a real site

Found while running the real-adapter e2e for stream/img (not by inspection):
`FileManager::gatedLocalUrl()`/`imgBaseUrl()` hardcoded a bare `/api/fm/...`
path, correct only when the API is mounted at the iframe's own origin root
(true for standalone and the Laravel proxy). WordPress's REST API never
resolves at a bare root — only under `/wp-json/{namespace}/...` — so every
`<img>`/`<video>` pointed at gated media (`fluxfiles_local_private`) 404'd
in a real WordPress browser. This is a total-failure bug, not a cosmetic
one: any WordPress site with gated local media enabled had broken previews.

- New `FileManager::setApiBasePath(string $base)` (core), defaulting to
  `/api/fm` — unchanged behavior for standalone/Laravel. WordPress's shared
  `fileManager()` builder now calls it with
  `rest_url('fluxfiles/v1') . '/api/fm'`.
- WordPress adapter's core floor bumped to `^0.2.79` (the tag that first
  ships `setApiBasePath()`).

## [0.2.97] — 2026-08-31

> Released: `laravel-v0.2.37`. No core or WordPress change in this entry.

### Fixed — Laravel proxy: stream/img served wrong Content-Type

A controller action returning `void`/`null` lets Laravel's kernel build its
own default `Response`, and Symfony's `Response::sendHeaders()` always
re-asserts `Content-Type` — silently overwriting the real image/video MIME
type `stream()`/`img()`/`serveBytes()` already sent, so every `<img>`/
`<video>` hitting these endpoints got back `text/html`. Added the same
`exit;` after raw output that `publicLink()` already used, plus a static
regression test guarding against a bare `return;` creeping back in.

## [0.2.96] — 2026-08-30

> Released: `core-v0.2.78`. No paid-module version bump: `AiVisionModule`'s
> server side (`/api/fm/ai-vision`, `allow_ai_vision` claim) is untouched,
> only its UI wrapper.

### Added — AI Vision operator UI

`AiVisionModule` and its `/api/fm/ai-vision` route (bg-remove / upscale /
smart-crop via a BYO vision-provider key) were fully implemented server-side
but had no client, the same gap already closed for Share/Intake/Versioning.

- fm.js/index.html: an AI Vision modal (bg_remove/upscale/smart_crop actions,
  busy/error/result states), a detail-panel button, and context-menu +
  action-sheet entries — raster-only (`canAiVisionFile` mirrors
  `ImageOptimizer::isImage()`'s extension set, no SVG), folded into the same
  three-state `proGate('allow_ai_vision', 'ai')` (on/hidden/locked) treatment
  as the other standalone paid SKUs.
- `aivision.*` i18n namespace (9 keys) added across all 16 locales.

## [0.2.95] — 2026-08-29

> Released: `core-v0.2.77`, modules `audit-export v1.0.0` (new) and `sso v1.0.0`
> (new). Both new modules' composer floors are `^0.2.77` — they consume
> `ModuleRegistry::requireServer()`, `Claims::$allowAuditExport`/
> `$auditRetentionDays`, and `StorageMetadataHandler::readAuditArchive()`/
> `purgeAuditBefore()`, all first shipped in that core tag.

### Added — Enterprise Compliance Bundle: audit export + SSO bridge

Fills the last two gaps in the Enterprise compliance story (virus scan + C2PA
provenance already shipped): audit log retention/export, and a login screen
for the standalone UI.

- **Free/core fix**: `StorageMetadataHandler::audit()`'s 5MB/5000-line
  rotation used to destructively drop old entries; it now archives the
  dropped tail to `_fluxfiles/audit/archive/audit-<ts>-<hex>.jsonl` under the
  same lock before truncating — no module required, ships for every install.
  New `readAuditArchive()`/`purgeAuditBefore()` storage primitives and
  `AuditLogStorage::exportAll()` (merges live + archived, tenant-scoped,
  capped).
- **New paid module `audit-export`**: `GET /api/fm/audit/export` streams the
  full audit history as a file download, NDJSON or CSV (CSV rows are
  formula/DDE-injection guarded — a leading `=`/`+`/`-`/`@`/tab/CR gets
  quote-prefixed). `POST /api/fm/audit/purge` deletes entries older than a
  cutoff. Purge is **admin-only** (requires an unscoped token, since
  `audit.jsonl` is per-disk, not per-tenant) and separately checks
  `hasDisk()`, since `pathPrefix` and `allowedDisks` are independent claims.
  New `allow_audit_export`/`audit_retention_days` claims.
- **New paid module `sso`**: an OIDC login bridge for the standalone
  `/public` UI, for deployments with no host app minting tokens via
  `fluxfiles_token()`. Three pre-auth routes — `/sso/login`, `/sso/callback`,
  `/sso/exchange` — gated by a new `ModuleRegistry::requireServer()`
  (installed + licensed only; there's no `Claims` yet for the usual
  per-token check, so the third gate is the server env flag
  `FLUXFILES_SSO_ENABLED`). Group-to-claims mapping via
  `FLUXFILES_SSO_CLAIMS_MAP` (dot-path group claim, first match wins,
  fail-closed `403 sso_no_mapping`). The real JWT is only ever handed off
  through a URL **fragment** (`#boot=<one-time token>`, 60s TTL), traded for
  the real token over a `POST` body — never a query string, never logged.
  Per-client-IP rate limiting on all three routes
  (`FLUXFILES_SSO_{LOGIN,CALLBACK,EXCHANGE}_LIMIT`).
- Hardening from review: closed a WHATWG backslash-normalization
  open-redirect bypass in the login flow, stopped a 500 response from
  leaking the missing env var's name to the client, caught malformed-JWKS
  parses instead of leaking an uncaught exception with a 200 status, made
  `purgeAuditBefore()` fail loudly instead of silently swallowing errors on
  a destructive admin-only operation, and locked the OIDC discovery/JWKS
  disk cache to directories the process actually owns (closes a local
  cache-poisoning path when `FLUXFILES_STORAGE_PATH` is unset).
- New claims + env vars documented in `docs/CONFIG.md`; new routes in
  `docs/API.md` and `.claude/api-map.md`.

## [0.2.94b] — 2026-08-28

> Released: `core-v0.2.77` — same core tag as `0.2.94`/`0.2.95` above.

### Added — Intake: notify-on-receipt webhook

`PublicLinks.php` now fires a `intake_received` event through the existing
Webhooks module whenever a visitor uploads through an intake portal link, so
an operator with `allow_webhooks` configured hears about a submission without
polling the intake list. Config is baked into the intake record at create
time (not the portal JWT, which is visitor-facing), matching the pattern
`docs/INTAKE-NOTIFY-ON-RECEIPT-DESIGN.md` lays out; dispatch is
post-response, same at-most-once semantics as every other webhook event. New
self-booting e2e coverage in `tests/e2e/test-intake-notify-http.php`. No new
claims — reuses `allow_webhooks`/`webhook_url`/`webhook_events`/
`webhook_secret`.

## [0.2.94] — 2026-08-28

> Released: `core-v0.2.77` — the same core tag as `0.2.95` above (both are
> core-side changes stacked on `core-v0.2.76`, released together). No paid-
> module version bump: `versioning`'s server side is untouched, only its UI
> wrapper.

### Added — Versioning operator UI

`VersioningModule` and its `/api/fm/versions*` routes were fully implemented
server-side but had no client, mirroring the gap already closed for
Share/Intake.

- fm.js/index.html: a Version History modal (open/close/load/restore), a
  detail-panel button, and context-menu + action-sheet entries, folded into
  the existing toolbar Pro-lock pill via the same three-state `proGate`
  (on/hidden/locked) treatment as the other standalone paid SKUs.
- `versions.*` i18n namespace added across all 16 locales.
- **Fixed alongside**: Laravel and WordPress forwarded
  `allow_versioning`/`versioning_max`/`versioning_max_mb` unconditionally,
  but `/api/fm/versions*` isn't proxied by either adapter — the same class of
  bug already fixed for `allow_terminal`/`allow_share`/`allow_intake`.
  Laravel now mode-gates the claim behind `standalone` mode; WordPress drops
  it entirely.

## [0.2.93] — 2026-08-27

> Released: `core-v0.2.76`, module `intake v1.2.0`. Intake's composer floor bumped to
> `^0.2.76` (the new `/api/fm/intake/analytics` route calls `IntakeModule::analytics()`
> directly).

### Added — Intake branding + per-event analytics

Deepens Intake (the inbound twin of Share, and the other named hero SKU) to match
what Share got in `0.2.92`, closing the gap between the two paid heroes:

- **Branding**: new `intake_brand_name`/`intake_brand_logo_url`/`intake_brand_color`/
  `intake_brand_link_url` claims, sanitized via a `sanitizeBrandFields()` helper now
  shared with Share (`sanitizeShareBrand()` refactored to call it). Baked into the
  portal record at `createPortal()` time; rendered on the public `intake.html`
  landing page the same way `share.html` renders Share's branding.
- **Analytics**: new opt-in `intake_analytics` claim (default `false`) logs a
  per-event JSONL record (`_fluxfiles/intake-events/<jti>.jsonl`) for every
  `received` and `rejected` upload attempt, the latter carrying a `reason`
  (password/cap/ext/size/quota/virus) and both carrying the file's `name`. New
  `GET /api/fm/intake/analytics` endpoint (`disk`, `jti`, `limit`, `offset`,
  `event` params), `owner_only`-gated like the portal itself. The `rejected`
  aggregate counter on `intakes.json` is unconditional (mirrors Share's
  `unlock_fails`); only the per-event log is gated by the claim.
- **Fixed alongside**: `revokePortal()`'s tombstone now retains `owner` (was
  missing — `owner_only` couldn't be checked on a revoked portal); `cleanLabel()`
  gained a configurable max-length param instead of a hardcoded 120.
- New `packages/core/tests/e2e/test-intake-http.php` self-booting HTTP e2e suite
  (Intake previously had no e2e coverage at this level, unlike Share).

New claims documented in `docs/CONFIG.md`. See `docs/INTAKE-BRANDING-ANALYTICS-DESIGN.md`
for the full design.

## [0.2.92] — 2026-08-26

> Released: `core-v0.2.75`, module `share v1.2.0`. No composer floor changes beyond
> `share`'s own bump to `^0.2.75` (consumes the new `Claims::$shareAnalytics`).

### Added — Share per-event analytics

Deepens Share (the flagship paid SKU) beyond its existing aggregate `views`/
`downloads`/`unlock_fails` counters: a new opt-in `share_analytics` claim (default
`false`, kept separate from `allow_share` since it persists visitor IP/user-agent —
a privacy/compliance footprint the plain counters don't have) logs a per-event JSONL
record (`_fluxfiles/share-events/<jti>.jsonl`) for every `view`/`download`/
`unlock_fail` on a share. New `GET /api/fm/share/analytics` endpoint (`disk`, `jti`,
`limit`, `offset`, `event` params), `owner_only`-gated; the revoke tombstone now
retains `owner` so this still works on a revoked share. Rotates at 1MB / keeps the
last 2000 lines per share; the per-share event file is deleted when its tombstone is
pruned. Zero new i18n strings — reuses existing `share_revoked`/`perm_denied` error
codes. See `docs/SHARE-ANALYTICS-DESIGN.md` for the full design.

## [0.2.91] — 2026-08-26

> Released: modules `share v1.1.0`, `intake v1.1.0`, `versioning v1.1.0`, `webhooks v1.1.0`,
> `ai v1.1.0`, `ocr v1.1.0`, `virus v1.1.0`, `backup v1.1.0`, `c2pa v1.1.0`. No core tag —
> these are private-repo module releases only; each module's composer floor was bumped to
> `^0.2.74` where it consumes a core API from that tag (`writeScopedFile`,
> `writeScopedStream`, `assertCanReadTree`, `setVersionPurger`, `SsrfGuard::assertConnectedIpSafe`).

### Fixed — security/reliability pass across all 9 paid modules

Each of the 9 private paid modules had uncommitted fixes sitting alongside pending
docs-only commits; batched into one `v1.1.0` per module rather than shipping the docs
alone and leaving these unreleased:

- **share**: `_fluxfiles/shares.json` writes now run under a per-tenant `flock()`
  (create/download/revoke), closing a lost-update race on concurrent hits; the
  password-wrong case now returns `share_password_wrong` instead of reusing
  `share_password` (the "nothing submitted" code).
- **intake**: same `flock()` treatment for `intakes.json`; `receiveUpload()` reserves a
  slot atomically before the upload runs and releases it again on failure, so two
  concurrent uploads near a portal's cap can't both land and a rejected file doesn't
  permanently burn a reservation; `intake_password`/`intake_password_wrong` split;
  `allow_virus_scan` now forwarded into the portal's own token so anonymous uploads get
  the same fail-closed scan as authenticated ones.
- **versioning**: the manifest read-modify-write (`keep()`, new `purge()`) is now
  locked per version directory; `restore()` writes through `writeScopedFile()` so a
  restored version re-runs the same ext/filename checks and virus scan as any other
  write; new `purge()` erases a file's version history on permanent delete, wired via
  `setVersionPurger()`, so `/delete` being "permanent" can't be undone by restoring an
  older version.
- **webhooks**: added a DNS-rebinding SSRF backstop — `SsrfGuard::assertConnectedIpSafe()`
  re-checks the IP curl actually connected to after `curl_exec`, even on a failed
  request, so a rebind that connects to an internal host can't leak its IP back to the
  caller through the raw `curl_error()` text.
- **ai**: AI-generated result bytes now write through `writeScopedFile()` instead of a
  raw Flysystem write, picking up the same scoping/metadata enforcement as any other
  write.
- **ocr**: enforces a size cap (`max_upload_mb`, fallback 25MB) before reading a file
  into memory, and a wall-clock timeout around the `tesseract` subprocess (SIGKILL +
  `ocr_timeout` on expiry); the `-l` language arg is now whitelist-sanitized in an
  extracted, unit-tested function.
- **virus**: `scanWithClam()` is now bounded by `FLUXFILES_CLAMSCAN_TIMEOUT` (default
  60s) — an unresponsive `clamd` is SIGTERM'd then SIGKILL'd and the write refused
  (`virus_timeout`, fail-closed) instead of hanging the request forever.
- **backup**: `run()` walks raw Flysystem, so `owner_only` was never actually enforced —
  added an explicit `assertCanReadTree()` check before any copying starts; copies now
  route through `writeScopedStream()` so synced bytes get ext/filename/virus-scan
  checks; the per-run file cap now only counts files actually copied (skips are free),
  so a subtree bigger than the cap converges over repeated scheduler runs instead of
  hitting the same cutoff every time.
- **c2pa**: `verify()`/`sign()` subprocess calls are now bounded by a 30s timeout
  (SIGKILL + `c2pa_timeout`); `sign()` no longer returns `c2patool`'s raw stderr to the
  caller (it could echo back server file-layout paths) — logged server-side instead;
  signed output now writes through `writeScopedFile()`.

No new claims and no `docs/CONFIG.md` changes — every fix uses claims/core APIs that
already existed as of `core-v0.2.74`.

## [0.2.90] — 2026-08-26

> Released: core `core-v0.2.74`. No adapter floor bump — everything in this entry is
> standalone-core (`public/share.html`, `Claims.php`, `lang/*.json`); no adapter touches
> any of it.

### Added — Branded Share (logo/name/color/link on the public landing page)

`ShareModule::card()` and the password-gated `shareInfo()` stub have hardcoded
`'brand' => null` since Share shipped — a deliberate seam the landing page's own copy
("Branded Share" / "your logo, your domain") already sold against, unbuilt. Four new
claims fill it: `share_brand_name` (≤80 chars), `share_brand_logo_url` (http/s only),
`share_brand_color` (strict `#rgb`/`#rrggbb`), `share_brand_link_url` (http/s only) —
sanitized in one `Claims::sanitizeShareBrand()`, the same flat-claim-family shape as
`sanitizeWatermark()`. All four blank ⇒ `null`, so there's no separate enable flag.

**Baked in at create time**, like `share_url_ttl`/`share_preview`: a public share link
carries no JWT, so `ShareModule::createShare()` reads `$claims->shareBrand` once and
writes it into the record. Changing an operator's brand claims only affects shares
created afterward — existing shares keep whatever branding they were created with,
same trade-off `url_ttl`/`preview` already accept.

Rendering follows the same URL-scheme discipline as the existing `preview_url` case:
`logo_url`/`link_url` are parsed with `new URL()` and only `http:`/`https:` protocols
ever reach `img.src`/`a.href`; `color` is re-validated against the strict hex regex
before it's written to `--accent` via `style.setProperty` — never string-interpolated
into a `<style>` block. Shows on both the normal card and the password-lock screen
(a recipient sees the operator's brand before entering the password).

Tests: 5 new cases in `tests/browser/share-landing.spec.ts` (brand-null no-op, full
render incl. `target=_blank rel=noopener noreferrer`, lock-screen rendering pre-unlock,
hostile `javascript:`/`data:`/`vbscript:` schemes on both URL fields, invalid-color
values left at the CSS default) plus the existing free-core `brand === null` regression
coverage.

### Fixed — the public download button dead-ended on a raw JSON error page

The download route is stateful — every hit counts against `max_downloads`, and on
S3/R2 a successful hit's response *is* a cross-origin 302 to a presigned URL — so it
can only ever be triggered by a real navigation, not a `fetch` (a separate "check"
request would burn a second download for one click, and following the S3 redirect
needs CORS the bucket may not grant). `share.html` was doing exactly that:
`location.href = url`, which meant any server error — link exhausted, grant expired —
navigated the whole tab away to a bare `{"error":...}` body with no way back.

Downloads now go through a hidden iframe: our own error responses are same-origin JSON
and land in the iframe's readable `contentDocument`; real bytes or a followed
cross-origin redirect either don't render into the frame or throw on access, and either
is treated as success (the browser's own download UI takes over). `share_grant_invalid`
re-locks the card and clears the stale grant instead of erroring; `share_exhausted`
disables the button in place; a `grant_expires` (600s TTL) that's already lapsed is
caught client-side on click, before a round-trip that was always going to fail. A `0`
`remaining` on page load — someone else took the last download between their view and
yours — now renders "No downloads remaining" up front instead of a doomed button.

### Fixed — two missing i18n keys (`doctor.lifecycle_snippet`, `check.lifecycle`)

Added across all 16 locales.

## [0.2.89] — 2026-08-06

> Released: core `core-v0.2.73`. The Laravel and WordPress adapters gained a
> pass-through argument but **no floor bump** — see below for why none is needed.

### Fixed — `FLUXFILES_AI_PROVIDER=gemini` was documented and rejected

`AiTagger` dispatched on a two-arm `switch`: `claude`/`anthropic` and `openai`.
Anything else fell through to `400 Unsupported AI provider`. The repository's own
`.env` had `FLUXFILES_AI_PROVIDER=gemini` with `FLUXFILES_AI_AUTO_TAG=true`, so every
image upload on that install threw on the auto-tag step. Nothing in the config surface
said `gemini` was unsupported — the `.env.example` comment read "'claude' or 'openai'",
which is a hint, not a constraint, and the failure only surfaced at upload time.

The switch is now a provider table. Three wire protocols already covered the field —
Anthropic Messages, Google `generateContent`, and OpenAI chat-completions, which most
of the industry has cloned — so adding providers is a table row, not a code path:

| name | protocol | default model |
|---|---|---|
| `claude`, `anthropic` | Anthropic Messages | `claude-sonnet-4-20250514` |
| `gemini`, `google` | Google generateContent | `gemini-flash-latest` |
| `openai` | OpenAI chat-completions | `gpt-4o` |
| `openrouter`, `groq`, `mistral`, `xai`, `grok`, `ollama` | OpenAI-compatible | per-gateway |
| `compatible` | OpenAI-compatible | BYO (`_BASE_URL` + `_MODEL`) |

Provider names are trimmed and lowercased, so a pasted `  Gemini ` resolves.

### Fixed — the pinned Gemini model 404s for new API keys

A live call against a fresh key returned `models/gemini-2.5-flash is no longer
available to new users`. Google retires point releases for new keys while keeping them
alive for existing ones, so a pinned version doesn't fail at release time — it fails
later, for new installs only, and looks like a credentials problem. The default is the
`-latest` alias, which Google keeps pointed at a live model.

### Added — `FLUXFILES_AI_BASE_URL`

Points the OpenAI-compatible path at a self-hosted or corporate endpoint (Ollama,
LiteLLM, vLLM, a gateway). Required for `compatible`, optional elsewhere; `analyze()`
now returns `400` naming the missing setting instead of firing a request at an empty
base URL. A keyless local endpoint sends no `Authorization` header at all, because
`Authorization: Bearer ` is a 401 waiting to happen.

**No adapter floor bump.** The adapters pass the base URL as a 4th constructor
argument, and PHP silently drops extra arguments to a userland function — against a
core older than the argument, the call still works and the override is simply ignored.
The constraint stays honest without a coordinated release.

## [0.2.88] — 2026-08-05

> Released: core `core-v0.2.72`. The WordPress plugin picks this up through its bundled
> core, so no adapter tag is needed — but a **plugin rebuild is required**, because the
> bundle is assembled by a script rather than resolved by Composer.

### Fixed — the module autoloader never reached WordPress or Laravel

`0.2.86` fixed "an installed paid module was never actually loaded" for exactly the
platforms that were easiest to test, and shipped it as if it were general. The
registration went into `packages/core/autoload.php` — a file only the **standalone**
entrypoints require (`api/index.php`, `embed.php`, `bin/fluxfiles`). Anything consuming
core as a library goes through Composer's `vendor/autoload.php` and never touches it.
That is both PHP adapters.

Measured against one module installed exactly where `ACTIVATE.md` says to put it:

| entrypoint | `class_exists(ShareModule)` before |
|---|---|
| `vendor/autoload.php` — WordPress, Laravel | `false` |
| `packages/core/autoload.php` — standalone | `true` |

So the release immediately before this one, `wordpress 0.2.43`, was hollow in practice.
It opened the REST routes and stopped stripping `allow_share`/`allow_intake`, but the
gate's first layer is `class_exists()`, and that still answered false — a WordPress
customer could buy Pro, enter a valid key, unpack the zip correctly, and get
`501 module_not_installed` from the channel `ROADMAP.md` calls the hero channel.

The registration now lives in its own file, `packages/core/modules-autoload.php`,
reached two ways: core's `composer.json` lists it in `autoload.files` (so every library
consumer gets it), and `autoload.php` requires it (so the standalone path is unchanged).
A static guard plus `require_once` keeps that to a single `spl_autoload_register` when
both happen in one process — otherwise every free-core miss would cost two filesystem
probes instead of one.

`scripts/build-wordpress.sh` copies the new file into the plugin bundle. That script
assembles the plugin file by file instead of resolving it, so a new root-level runtime
file is exactly the kind of thing it silently omits — and omitting this one puts the
plugin back to `501` for everything paid. Verified on a real built bundle, loaded the
way `fluxfiles.php` loads it.

### The test that should have existed the first time

`tests/integration/test-module-autoload.php` asserts **both** entrypoints separately, in
child processes — an autoloader is process-global, so checking twice in one process
passes for the wrong reason. It also pins the things whose loss is silent: the
`autoload.files` key, the bundle script's copy, single registration, an absent module
still resolving to nothing (or free core would stop answering `501`), and the
`^[a-z0-9]+$` module-name guard that stops a crafted class name walking the filesystem.

Confirmed by reverting the fix: three of its seven cases fail, including the WordPress
one. A test that cannot fail is not a guard.

---

## [0.2.87] — 2026-08-05

> Released: core `core-v0.2.71`. Adapters unchanged (this is inside the iframe).

### Fixed — the links table's Link column had no header of its own

Both tables in the Links panel labelled their URL column with `links.link_hidden`, a
string written to explain that a token is shown once and not stored. As a column
header it read as a sentence where a noun belonged. The column now uses its own
`links.col_link` key ("Link"), added across all 16 locales; `link_hidden` keeps its
original job in the reveal modal.

### Added — a runbook for the steps that are infrastructure, not code

`docs/OPERATIONS.md` is the seller-side counterpart to `ACTIVATE.md`: rotate leaked
tokens, host the module artifacts, deploy the update and licence servers, point Polar's
webhook at it, create the production products, switch the landing over. Order matters —
each step depends on the one before — and the known limits are written down (the
one-hour `/claim` window, no early revoke on cancellation, module floors checked by
hand) so they are not discovered during a first sale.

`polar-setup.php` now reads its token from the repo-root `.env`, so it never has to be
typed on a command line or land in shell history. It picks the variable matching the
environment, which is what stops a sandbox token from reaching production.

*(The runbook and licence server ship in the repository, not in a released package —
this release is the core change above.)*

---

## [0.2.86] — 2026-08-05

> Released: core `core-v0.2.70`. Adapters unchanged.

### Fixed — an installed paid module was never actually loaded

Writing the activation guide turned up something worse than a missing page: the
documented install path did not work. `fluxfiles update share` verifies a signed
manifest, checks the zip's sha256 and unpacks into `vendor/fluxfiles/share/` — and
then nothing loaded it. Composer only autoloads what is declared in `composer.json`,
and a private package dropped into `vendor/` is not, so `class_exists()` stayed false
and the gate answered `501 module_not_installed`. A customer could pay, install
correctly, and be told the module was not installed, with nothing to suggest why.

`packages/core/autoload.php` now registers a lazy autoloader for `FluxFiles\<Module>\`
that resolves against the layouts a real install produces. It only touches the
filesystem when such a class is requested — which happens only when a paid gate runs —
so free core pays one `is_file()` miss.

A monorepo sibling (`packages/share` next to `packages/core`) is deliberately **not**
searched. It exists only in this repository, and treating it as installed would make
the free-core path untestable on the one machine that has the private packages: every
"module absent → 501" test would see the module. The suites that want a module loaded
require its source explicitly, which is the honest way to say "this run has it".

### Added — `docs/ACTIVATE.md`

What to do with a licence key, per platform. It leads with the wrong first step almost
everyone takes — `composer require fluxfiles/share`, which fails because the package is
private — and covers WordPress separately, since a shared-hosting site has no shell to
run the installer from and no way to set an environment variable.

It also maps the three gates to what each error means: `501` is the code missing, `402`
is the licence, `403` is the token. They are separate checks on purpose, and the error
says which one to fix.

Every command, module id and error code in it was checked against the source rather
than written from memory.

## [0.2.85] — 2026-08-05

> Released: core `core-v0.2.69`, wordpress `0.2.43`. Laravel unchanged — it still does
> not proxy these routes, and its route-parity list still says so.

### Added — Share and Intake work on WordPress

`ROADMAP.md` calls WordPress the hero channel for the file-portal story, and
`COMMERCIAL-STRATEGY.md` calls persona A (agency/WP) the bread-and-butter of the funnel.
Neither hero product worked there: the REST proxy exposed none of the endpoints and the
plugin explicitly stripped `allow_share`/`allow_intake` from every token. A customer
could buy Pro, and now enter their licence key, and still have nothing to click.

**The recipient pages already existed.** `share.html` and `intake.html` have shipped
inside the plugin bundle all along — complete, tested and translated — and were simply
unreachable, because they resolved the API from the site root (`/api/fm/…`) where
WordPress answers under `/wp-json/`. They now read `window.__FM_API_BASE__`, defaulting
to the old value so standalone is untouched, and `public-link.php` serves them with the
REST base injected.

**The public routes call core, not a copy.** The five recipient endpoints
(`share/info|unlock|file`, `intake/info|upload`) delegate to the same handlers
standalone uses, moved into `api/PublicLinks.php` so they can be included without
running index.php's auth and routing as a side effect. Reimplementing them was the
alternative and was rejected: ~400 lines of traversal guards, MIME-by-extension (never
sniffed), the two-bucket password brute-force limiter, presigned-redirect versus
streamed bytes, and the download counter — a second copy is how a hole appears on one
platform only.

Those five routes are registered `permission_callback => '__return_true'`, which is
correct and load-bearing: a recipient has no WordPress account and no JWT, and the share
token in the query string is the whole gate. A test asserts it, because "tightening"
them to `checkAuth` would 401 every share link for exactly the people it is for.

The handlers are passed WordPress's own DiskManager. Core resolves the local disk to
`api/../storage/uploads` while WordPress keeps uploads under `wp-content`, so the
default would have resolved every share against a directory holding none of the files.

`share_base_url` / `intake_base_url` now default to this site's own page rather than
being left empty — empty makes the module fall back to the request origin plus
`/public/share.html`, a path a WordPress site does not serve.

One earlier test asserted the exact opposite (that the claims are always stripped). It
was right when the endpoints did not exist and is now rewritten, with the reasoning kept
so the reversal is not mistaken for drift.

## [0.2.84] — 2026-08-05

> Released: wordpress `0.2.42`. Core and the other adapters unchanged.

### Fixed — a WordPress customer could not activate or update what they bought

Two gaps that only existed on WordPress, and only mattered to people who had paid.

**There was nowhere to put a licence key.** The core reads `FLUXFILES_LICENSE_KEY` from
the environment, which is fine for Docker or Laravel and useless on the shared hosting
most WordPress sites run on — there is no shell to export it from. The plugin had
fourteen settings and none of them was the licence. Dropping Freemius (see `[0.2.83]`)
removed the last path that would have worked, so Pro was unactivatable on WordPress.

The plugin now stores the key as an option, preferring it over the environment and
falling back to it so existing env-based installs keep working. Every paid-module gate
goes through `FluxFilesPlugin::license()` instead of `LicenseManager::fromEnv()` —
otherwise a customer who pasted a perfectly good key would still be told they had none.
The settings screen reports the verified edition, status and expiry back, because a key
field that swallows input and says nothing is indistinguishable from a broken one, and
it says plainly when a key was *not* accepted rather than showing a reassuring edition
name the customer does not have. The key is stored verbatim: a signed token is long and
punctuation-heavy, and `sanitize_text_field` would quietly corrupt a valid one.

**There was no update mechanism at all.** The plugin ships as a zip rather than through
wordpress.org, so a site had no way to learn a new version existed — including a
security release. Laravel and standalone have had `fluxfiles update` all along;
WordPress had nothing.

`FluxFilesUpdater` now checks the FluxFiles update server and hands wp-admin an update
the normal way. The manifest is **signed** and verified with the same `UpdateClient` and
embedded release key the CLI uses, and it must name this artifact — a validly signed
manifest for a different module would otherwise install the wrong zip. WordPress
downloads the package itself and so never re-checks the manifest's sha256, which makes
the signature on the URL the protection that actually matters. Results are cached
(including failures) because this runs on every admin page load. With no endpoint
configured it registers no hooks at all: a self-hosted install that never phones anywhere
is a supported setup, not an error to nag about.

The plugin header also gains `Update URI:`. Without it, anyone publishing a plugin under
this slug on wordpress.org would have their updates served to our users.

Tests: eight more in `test-wp-smoke.php` (33 total), including a behavioural check that
an update is offered only when genuinely newer — the same version is not offered (an
update notice that reinstalls the current build is an infinite nag), an older one is
never offered (a rolled-back server must not downgrade a site), and a cached failure
offers nothing rather than crashing.

## [0.2.83] — 2026-08-04

> Released: core `core-v0.2.68`. Adapters unchanged (this is inside the iframe — no
> `postMessage` command is added, and the React/Vue/SDK/editor wrappers are untouched).

### Added — the operator UI for Share links and Upload portals

Pro (`share` + `intake`) was fully implemented server-side and had **no client**. An
operator who bought it, installed both packages and opened `/public/` got a file
manager with no button — the only way in was hand-writing `POST /api/fm/share` against
a self-minted JWT. That is a delivery defect on an already-sold SKU, not a new feature.

**The UI is free/core.** All minting, enforcement, counters, tombstones and licensing
stay in the paid packages; a gitignored package cannot inject markup, JS, CSS or locale
keys into a zero-build Alpine bundle, so putting the client in the module would mean
inventing an asset-injection system for one consumer. Without the module every button
gets a `501` and the panel is an empty shell — core supplies only the chrome.

- **Detail panel**: *Share link* on a file (needs `read`), *Upload portal* on a folder
  (needs `write`). **Toolbar**: a Links panel listing both, per disk, with Revoke.
- **Create modal** — one shell, two configurations. Label, expiry and password are
  shared; the field sets are not (`max_downloads` vs `max_files`/`max_mb`/`allowed_ext`),
  and neither are the TTL bounds. Submit is disabled in flight, because a double click
  would mint two live links and a duplicate is revocable-only.
- **One-shot reveal.** The token is returned once and never stored, so the modal shows
  the URL in a read-only field with Copy and a non-dismissible warning; closing before a
  copy asks first. The raw token is never rendered, and `FM_EVENT` carries
  `{jti, expires, has_password}` — no token, no url. A host that wants the link asks the
  API itself.

### The three-state gate (the part most likely to regress)

`proGate()` discriminates by **licence**, not only by claim:

- **on** — claim on → the full panel; the server still enforces.
- **hidden** — claim off but this server *is* licensed for the module. The operator
  deliberately withheld the feature from this tenant, and advertising it inside their
  product would sell against them.
- **locked** — claim off **and** the server is unlicensed **and** we are not framed
  (top-level `/public/`, i.e. the Docker evaluation) **and** `pro_hints` is on → a small
  Pro affordance that issues no API calls.

This departs from the optimize/terminal precedent (claim-gated, invisible when off) on
purpose: those are capability toggles whose absence is meant to be invisible, whereas
Share/Intake *is* the SKU and an unlicensed server has no paying operator to embarrass.
The departure is bounded to the unlicensed, unframed case. The order short-circuits, so
the only context paying an extra `GET /api/fm/license` is that evaluation case.

### Also

- **New claim `pro_hints`** (bool, default true) — the hard off switch for the locked
  affordance, for operators shipping free core in production. UI-only.
- **`has_password` on both list responses.** `listShares`/`listPortals` stripped
  `password_hash` but published nothing in its place, so the lock glyph could never
  render. The flag replaces the hash; the hash still never leaves the server.
- **`loadLicense()` no longer pins a failure as an answer.** It now memoizes only a
  definitive response and shares one in-flight promise. Previously a transient error was
  cached as `edition: free` for the session — harmless while nothing fetched the licence
  before the dashboard opened, and a real "your Pro server says Free" bug now that the
  gate can fetch it during the first render.
- i18n: 52 keys × 16 locales, split so that no noun-bearing string is shared between the
  two features — in ru/tr/ar/de "share link" and "upload portal" decline differently, so
  one template with a `{noun}` parameter would mistranslate in 16 locales at once.
- Errors: `module_not_installed` / `license_required` / `license_expired` render inline
  in the modal body as a terminal state with a docs link, not as a transient toast — a
  501 is not retryable. The panel does not fall back to the locked state: the claim is
  on, so the install is broken and saying so is the useful message.

Tests: `tests/browser/share-intake-ui.spec.ts` (9) — every gating state including
*licensed-but-withheld* and *framed*, the real 501 against a stock `php -S` with no
private packages, the one-shot copy/confirm flow, a hostile `javascript:` url rendered
as text only, and list + revoke. `usage.spec.ts` now installs its route mocks before
the app loads, because the gate may fetch the licence during the first render.

## [0.2.82] — 2026-08-04

> Released: react `0.2.9`, vue `0.2.8`, sdk `0.2.7`. Core unchanged (the emitting side
> shipped with the modal anti-flash work; this is the host-side half).

### Fixed — the modal chrome stayed light after a runtime dark-mode toggle

The modal chrome (React/Vue/SDK) lives in the **host** origin and cannot read the
cross-origin iframe's resolved theme, so toggling dark mode inside the embedded UI
only darkened the iframe — the surrounding window and header stayed light. The
existing fix covered *boot* (resolve from `localStorage`/OS before first paint, see
`[0.2.60]`); it did not cover the theme changing while the modal was open.

- The iframe now emits **`FM_THEME`** from `_updateThemeClass()` whenever the theme
  changes — toggle, host config, or an OS change while in `auto` — carrying the
  **resolved** theme (`'dark'`/`'light'`, never `'auto'`), because the host has no way
  to resolve `auto` on the iframe's behalf.
- The SDK routes it and recolors its own modal chrome; it is a no-op when embedded in
  a host `container` (there is no chrome to recolor).
- React/Vue expose it as **`onThemeChange`** and make the chrome's `dark`-ness
  stateful, so it tracks runtime changes on top of the boot-time resolution.
- Additive message — the `postMessage` protocol stays backward compatible, so an older
  host paired with a newer iframe simply ignores it.

## [0.2.81] — 2026-08-04

> Released: core `core-v0.2.67`; wordpress `0.2.41`, laravel `0.2.36` (both adapters
> gained the scanner wiring and now declare a `^0.2.67` core floor — the floor guard
> skips with a warning until `core-v0.2.67` is tagged, then enforces it).
> node/react/vue/sdk unchanged.

### Fixed — the Virus module was registered but never called

`ModuleRegistry::$map` has listed `virus => VirusScanModule` since the module gate
existed, and `allow_virus_scan` has been a parsed claim and an `enterprise` preset
default the whole time — but **nothing in core ever called it**. `scanBytes()` and
`uploadHook()` had no call site anywhere, and core had no upload-hook registry for
`uploadHook()` to be picked up by. A customer could buy the module, install it, mint a
token with the claim, and every file would be stored unscanned with no error. It was
the only one of the nine paid modules with no call site.

- **Wired into every write path where the server actually holds the bytes:**
  `upload()` (which also covers `/api/fm/import-url`, sharing the same pipeline),
  `putContent()` (the code editor — a webshell pasted into an existing `.php` is
  exactly what ClamAV catches), and **each entry of `extractZip()`**, scanned before
  it is written. Infected → `422 virus_detected` and nothing is stored.
- **Fail-closed, deliberately.** With the claim on, anything that stops a scan from
  happening — module not installed (`501`), licence missing/expired (`402`), token
  refused (`403`), engine unreachable (`502`) — refuses the write. Swallowing those
  would store an unscanned file while the operator believes scanning is on, which is
  the one failure mode a scanner must not have. A malformed verdict counts as not
  clean. The module gate resolves **lazily inside the callback**, so a tenant with the
  claim can still list and read; only writes that needed a scan fail.
- **The chunk-upload side door is closed.** S3 multipart sends bytes browser→S3 on
  presigned URLs; they never reach the server and cannot be scanned. `/api/fm/chunk/*`
  now returns `409 virus_unscannable` while the claim is on, rather than remaining the
  documented way around the scanner.
- **The adapters were the same hole.** The Laravel controller and the WordPress REST
  proxy build their own `FileManager` and handle `/upload` themselves, so a token that
  was scanned in standalone was silently unscanned through either adapter. Both now
  wire the scanner with the identical lazy gate.
- **A blocked file is audited** (`virus_blocked`). The audit log only records
  successful writes, so without this the one security event a scanner exists to
  produce would be the only thing it never recorded.
- **The module now scans by path, not by bytes.** `scanPath()` is the primary entry
  point: an upload is already a temp file, so a scan no longer loads a whole
  (possibly multi-GB) file into memory, and ClamAV reads the caller's temp directly
  instead of a copy. `scanBytes()` remains for in-memory content and delegates to it.
  VirusTotal hashes the file with `hash_file` — still SHA-256 lookup only, bytes never
  leave the server.
- i18n: `virus_detected`, `virus_unavailable`, `virus_failed`, `virus_unscannable` and
  the `virus_blocked` audit label across all 16 locales.

Tests: `tests/integration/test-virus-scan.php` (13 — the FileManager seam through
injected scanners: clean, infected, throwing, malformed verdict, and that nothing is
written or indexed in each refusal) and `tests/e2e/test-virus-http.php` (6 — the
wiring decision through the real router: fail-closed 501, lazy gate, chunk refusal).

## [0.2.80] — 2026-07-30

> Released: core `core-v0.2.66`; wordpress `0.2.40` (bundled core only — no plugin
> source change; the plugin version is the only version a WordPress user can see, and a
> data-loss fix needs to be tellable apart). Laravel/node unchanged.

### Fixed — silent data loss on directory operations

Reported as "renaming a folder on the Laravel adapter makes it vanish until reload". It
was neither Laravel-specific nor a display bug: **directories containing no files were
destroyed**, and the same flaw turned out to run through three operations.

- **Folder rename destroyed empty directories.** `rename()` moved only `isFile()`
  entries, so the destination only ever came into existence as a side effect of moving a
  file into it. A folder with no files anywhere had nothing moved, the destination was
  never created, and `deleteDirectory()` then removed the original — taking any
  subdirectories with it. The folder index was left holding an entry for a directory that
  no longer existed, which is what looked like "it comes back after a reload": the listing
  reads the filesystem (gone) while the tree and search read the index (a phantom).
- **Folder move was broken on object stores.** The mirror image: `move()` called
  `$fs->move()` straight on the directory, which is a `rename()` syscall on local/SFTP
  (correct, atomic) but `CopyObject` on one exact key for S3/R2 — where a directory prefix
  is not an object — so folder move threw a raw 500. Both now share one
  `moveDirectoryTree()` that dispatches on the driver, creates every directory before
  moving files, and deletes the source only once the destination is confirmed to exist.
- **Trash destroyed empty subdirectories.** `trashDirectory()` recorded only files, so
  restore rebuilt only the directories implied by file paths. The manifest now carries
  `dirs[]`; manifests written before this restore exactly as they did before. This one
  mattered most — the UI sends everything to trash, so users reasonably expect it to be
  undoable.

### Added

- `400 move_into_self` — moving a folder into its own subtree was guarded only in the
  browser. On local the rename syscall happened to fail; with the new walk it would have
  written the destination inside the source and then deleted the source, so a client-side
  check became server-side data loss on object stores. Now refused server-side.
- `400 copy_dir_unsupported` instead of a raw `UnableToCopyFile` when copying a folder.
  Recursive folder copy is still not implemented — it now fails cleanly rather than 500ing.

### Notes

Existing phantom folder-index entries are **not** repaired; the fix stops new ones. They
surface only as a folder-search hit that 404s when clicked, since `list()` does not read
the index.

## [0.2.79] — 2026-07-30

> Released: core `core-v0.2.65`; node `0.1.26`, laravel `0.2.35`, wordpress `0.2.39`.

### Security

- **Intake portals leaked between users of the same tenant under `owner_only`.**
  `IntakeModule` never recorded an `owner` and `listPortals` never filtered on one, so a
  token scoped with `owner_only` still listed — and could revoke — every other user's
  portals. `/api/fm/intake/list` has been routed the whole time, so this was reachable
  today by calling the API directly, not just by the UI now being built. Portals now carry
  an `owner`, and both `listPortals` and `revokePortal` enforce it.
  Migration is deliberately **fail-closed**: a portal created before this has no `owner`,
  so under `owner_only` it becomes invisible and un-revokable while staying live for its
  visitors until it expires (the 90-day cap makes this self-clearing); the remedy is an
  operator token without `owner_only`. The `owner` is **not** backfilled to whoever reads
  the record first — that would hand ownership away.
- **A revoked intake portal could come back.** `revokePortal` used `unset` while
  `receiveUpload` rewrites the whole tenant map on every upload, so an interleaved upload
  restored the deleted record. Revoke now writes a tombstone and `putRecord` refuses to
  write over one — the same fix Share got in 0.2.78. The underlying read-modify-write race
  is unchanged and still accepted.

### Fixed

- **Proxy-mode hosts were told they could use Share and Intake.** Both adapters forwarded
  `allow_share`/`allow_intake` unconditionally even though none of those endpoints are
  proxied, so a Laravel/WordPress host would render controls for routes that 404. Laravel
  now forwards them (and `share_url_ttl`/`share_base_url`/`share_preview`/
  `intake_base_url`) only in `standalone` mode, mirroring the rule `allow_terminal` has
  followed since it shipped; WordPress drops them entirely. This also covers the
  `edition: pro|agency|enterprise` presets, which seed those claims straight into the
  payload and would otherwise have slipped past the gate.

### Added

- **`intake_base_url`** — the public base an intake create response builds its portal link
  from, mirroring `share_base_url`. Empty falls back to the request origin +
  `/public/intake.html`, so **set it explicitly behind a proxy/CDN**. Until now
  `POST /api/fm/intake` returned no recipient URL at all, so there was nothing to hand a
  visitor. The two fallback call sites are now one testable `ff_public_link_url()` helper.

## [0.2.78] — 2026-07-28

> Released: core `core-v0.2.64`; node `0.1.25`, laravel `0.2.34`, wordpress `0.2.38`.

### Added

- **Share: the public landing page.** An operator could mint a share
  (`POST /api/fm/share`) and there was no URL a recipient could open —
  `resolveShare()` had exactly one caller in the repo (its own test). The consumption
  half now ships in the **free MIT core**: `public/share.html` (single file, no build
  step, brand-neutral — every brandable value comes from the paid module's response)
  plus three pre-auth public routes, registered before the auth block next to Intake's
  and gated **installed + licensed only** (a public request carries no main JWT, so
  there is no claim to check): `GET /api/fm/share/info`, `POST /api/fm/share/unlock`,
  `GET /api/fm/share/file`. Free core → `501 module_not_installed`, and the page says
  the link isn't available (exactly like `intake.html`).
- **`POST /api/fm/share/revoke` + `GET /api/fm/share/list`** — the module methods
  existed but were never routed. Revocation is the only kill switch for a link that is
  now genuinely public, so it ships with the landing. Both are owner-filtered under
  `owner_only`.
- **`ShareGrant`** (`api/ShareGrant.php`, `t=share_grant`) — the password never rides a
  query string: `unlock` exchanges it for a short-TTL, jti-bound grant that the bytes
  GET carries. Distinct type from `stream`/`img`, so none of the three works on
  another's endpoint. A password-protected share now reveals **nothing** pre-unlock —
  not even the filename or size (the filename is often the secret).
- **Claims `share_url_ttl` (60s, clamped 10–300), `share_base_url` (http/s),
  `share_preview` (true)** + env `FLUXFILES_SHARE_RATE_LIMIT` (60/min per share),
  `FLUXFILES_SHARE_UNLOCK_LIMIT` (5/min per share + IP) and
  `FLUXFILES_SHARE_UNLOCK_TOTAL` (30/min per share, no IP component). All three claims
  are read at create time and baked into the share record, so a public request needs no
  claims. Forwarded by `embed.php`, `@fluxfiles/node`, Laravel and WordPress.
- 8 `error.share_*` keys across all 16 locales (there were zero).

### Fixed

- **A share token was not self-contained.** `resolveShare()` took the tenant prefix as
  a *caller* argument and `createShare()` never put it in the token, so a public caller
  had no way to locate `shares.json` — the multi-tenant path was unreachable and
  untested (every existing test passed `prefix = ''`). The token now carries
  `store` (like Intake's portal token) and **`resolveShare()` lost its `$prefix`
  parameter** — the bug class is gone by construction. Shares minted before this change
  stop resolving; nothing consumed them, so there is no migration.
- **A share on a public local disk was cosmetic.** The bytes route dispatches on the
  **disk driver**, never on the file's `url`: `s3` (S3 + R2) enforces + counts and then
  302s to a `share_url_ttl`-bounded presigned URL, while gated-local, **public-local**
  and SFTP all stream through the app. A static local URL is never emitted.
  *Documented limitation:* on S3/R2 `max_downloads` counts **grants, not downloads** — a
  handed-out presigned URL stays fetchable until it expires; `share_url_ttl` (60s)
  bounds that window, and an exact cap needs a local/SFTP disk.
- `DiskManager::presignGetUrl()` takes an optional `$disposition`
  (`ResponseContentDisposition`), so an S3 share download lands with the right filename
  instead of the raw object key.

### Security

- **A share/portal token was a fully usable main access JWT.** Both are signed with
  `FLUXFILES_SECRET` and carry `perms`/`disks`/`prefix`, so a recipient could replay the
  share token as `Authorization: Bearer` on `/api/fm/content`, `/zip` or `/presign` and
  read the file with **no password, no `max_downloads`, no `share_url_ttl` and no
  working revocation** (revoke only deletes the storage record while the JWT lives to
  `exp`). Both now carry a token TYPE marker — `t=share` / `t=intake`, the existing
  `StreamToken`/`ImageToken` pattern — and `JwtMiddleware::handle()` refuses **any**
  typed token (plus the legacy `share`/`intake` booleans) with `403 token_not_access`,
  translated in all 16 locales. Belt and braces, the share payload also sets
  `allow_download`/`allow_zip`/`allow_code_edit` to false. Share tokens minted before
  this change no longer resolve (nothing consumed them); portal links keep working.
- **Revocation is now durable.** `revokeShare()` writes a tombstone instead of
  `unset`-ing the record: every landing view rewrites the whole tenant map, so a view
  interleaving with a revoke used to write the pre-revoke map back and **resurrect the
  revoked link**. `putRecord()` also refuses to write over a tombstone, `listShares()`
  skips them, and `save()` prunes them once the token they killed could no longer
  resolve. (The underlying read-modify-write race remains the accepted stateless trade.)
- **Password brute force had no ceiling.** The unlock bucket was keyed on
  `REMOTE_ADDR` — the one value an attacker rotates for free — so attempts were
  effectively unbounded. A second bucket, `FLUXFILES_SHARE_UNLOCK_TOTAL` (30/min per
  share, no attacker-controlled component), now caps a whole share; the per-IP bucket
  stays as the tighter single-guesser limit. A failed unlock records `unlock_fails` on
  the share record (never `views`), so an attacked link is visible in `share/list`.
- `?token[]=a` no longer emits an "Array to string conversion" warning ahead of the JSON
  envelope (which, with `display_errors` on, disclosed the absolute server path). A
  shared `ff_str_param()` helper collapses any non-scalar param to its default — applied
  to the share, intake, `/stream` and `/img` token reads.
- The share bytes route's SFTP branch now sends `Content-Length` + `Accept-Ranges: none`
  and stops on a mid-stream read failure, instead of appending a JSON error envelope to
  partial file bytes.
- `share.html` only accepts a preview URL that matches one of its own routes
  (`/api/fm/img` or `/api/fm/share/file`) before assigning it to `iframe.src`.

## [0.2.77] — 2026-07-28

> Released: Laravel `laravel-v0.2.33`, WordPress `wordpress-v0.2.37`. Core unchanged
> (test-only additions).

### Fixed

- **Webhooks/Versioning were enable-only from the PHP adapters.** Both adapters
  forwarded the module *gate* claims (`allow_webhooks`, `allow_versioning`) but none of
  their config claims, so a Laravel/WordPress host could switch Webhooks on and have it
  POST nowhere — the module was enabled but inert unless the operator dropped down to the
  raw `claims` escape hatch. `@fluxfiles/node` had forwarded all of them since the
  modules shipped; the PHP adapters now match: `versioning_max`, `versioning_max_mb`,
  `webhook_url`, `webhook_events`, `webhook_secret`. Validation stays in the core (a
  non-http(s) `webhook_url` is still dropped on decode). Locked by a smoke test in each
  adapter. `webhook_events` accepts an array **or** a comma-separated string, so a plain
  config/settings text field works.

### Added

- **Core test coverage for the versioning/webhook claims** (`tests/unit/test-claims.php`).
  `Claims` validated `webhook_url` to http(s) only — a scheme-confusion guard, since the
  module POSTs to that URL — with no test behind it; that, the `webhook_events`
  normalization (array + CSV, trimmed/lowercased), and the `versioning_*` clamping are
  now locked.

### Docs

- `.claude/CLAUDE.md` + `AGENTS.md` listed the PDF/office/e-sign BYO-embeds as *planned*;
  all four (`terminal_pty_url`, `pdf_tools_url`, `office_url`, `esign_url`) have shipped.
- The WordPress plugin now records *why* the SSH terminal claims are not forwarded
  (proxy-only, no `/api/fm/terminal` endpoint), matching the existing note on the overlay
  watermark — the remaining forwarding gaps are all deliberate and now all explained.

## [0.2.76] — 2026-07-05

> Released: WordPress `wordpress-v0.2.36`. Core unchanged.

### Added (WordPress adapter — the last low-priority parity items)

- **Responsive srcset for offloaded images.** A pick's FluxFiles WebP variants
  (thumb 150w / medium 768w / large 1920w + the original) are stored on the attachment and
  emitted as a real `srcset` via a `wp_calculate_image_srcset` filter — offloaded images
  are now responsive, not single-size. Variant URLs are stable on the (required) public
  disk. Passed through the media button, block, native picker + `/attach`.
- **Classic-editor featured image** is covered by the native-picker integration (the
  "From FluxFiles" button appears in the classic featured-image `wp.media` panel too).
- **Native-picker e2e**: a real-WordPress Playwright test enables
  `fluxfiles_replace_picker` (via setup.sh) and asserts the "From FluxFiles" button is
  injected into an opened `wp.media` modal — real coverage for the experimental feature.

## [0.2.75] — 2026-07-05

> Released: WordPress `wordpress-v0.2.35`. Core unchanged.

### Added / Fixed (WordPress adapter — close the audit gaps)

- **Delete sync (WP → FluxFiles)**: deleting a FluxFiles-backed attachment can now also
  delete the storage file — opt-in via Settings → "Delete from storage" /
  `fluxfiles_delete_storage` (off by default; the file stays safe otherwise). Replaces the
  dead `wp_delete_file` no-op guard with a real `delete_attachment` handler.
- **Migration + housekeeping (WP-CLI)**: `wp fluxfiles migrate` moves existing local
  Media Library attachments into a FluxFiles disk and offloads them (with `--dry-run`,
  `--limit`, `--delete-local`); `wp fluxfiles verify [--delete]` finds/removes attachments
  whose backing file is gone (the FluxFiles → WP direction).
- **Real-WordPress e2e**: a Playwright test hits `/attach` against a live WP (via
  `@wordpress/env`) and asserts a real attachment is created, is idempotent on (disk,key),
  serves the rewritten FluxFiles URL, and syncs alt text — real coverage the stubbed smoke
  couldn't give.
- **Documented offload requirement**: offload needs a stable public URL (public S3/R2/CDN
  or public local); private buckets / SFTP / gated local serve expiring URLs and are
  rejected by migration. `stableUrl()` enforces this.

## [0.2.74] — 2026-07-05

> Released: WordPress `wordpress-v0.2.34`. Core unchanged.

### Added (WordPress adapter — closer to native media parity)

- **Alt text + caption sync**: a FluxFiles pick now writes `_wp_attachment_image_alt`
  and the caption (post excerpt) onto the attachment, and the inserted `<img>` uses the
  real alt.
- **Featured image from FluxFiles**: the Gutenberg block gains a "Set as featured image"
  toolbar action.
- **Native picker integration (experimental, opt-in)**: a *From FluxFiles* button in
  WordPress's own `wp.media` modal, so Featured Image, the core Image block and the
  Customizer can pull from FluxFiles too (each pick → an offloaded attachment). Off by
  default (`fluxfiles_replace_picker`); the button + block work without it. Documented
  limitation: responsive `srcset` isn't auto-generated for offloaded images (the on-demand
  `/img` resizer is token-gated by design) — WP serves the full-size image with correct
  dimensions.

## [0.2.73] — 2026-07-05

> Released: WordPress `wordpress-v0.2.33`. Core unchanged.

### Added (WordPress adapter)

- **Media offload / 3-in-1 media manager** (`FluxFilesAttachments`). A file picked from
  FluxFiles is now registered as a real **WordPress attachment** whose URL points at your
  FluxFiles storage (S3/R2/SFTP/local) — never copied into `wp-content/uploads`. URL
  filters (`wp_get_attachment_url`, `wp_get_attachment_image_src`) rewrite these to serve
  from your bucket, so files appear in the Media Library and work in posts/themes/blocks
  like native attachments but stay offloaded. One plugin replaces FileBird + WP Media
  Folder + WP Offload (folders + cloud + offload in one). Toggle: Settings → FluxFiles
  "Media offload", or the `fluxfiles_enable_offload` filter (on by default).
- **Gutenberg block** `fluxfiles/image` (no build step; registered via wp.blocks +
  wp.element) — pick from FluxFiles inside the block editor; inserts an attachment-backed
  image served from your bucket.
- **`POST /wp-json/fluxfiles/v1/api/fm/attach`** — registers a picked file as an
  attachment (idempotent on disk+key), gated by the `upload_files` capability. The classic
  media button now creates real attachments (`wp-image-<id>`) instead of raw HTML.

## [0.2.72] — 2026-07-04

> Released: core `core-v0.2.63`. Demo-mode anti-abuse hardening.

### Changed

- **Public demo mode is now abuse/cost hardened.** Three layers on top of the
  already local-only, heavily-scoped demo token:
  - **No cloud egress, ever.** Demo mode hard-strips every S3/R2/SFTP disk from the
    config (`forceLocalDisks`) — the demo is local-disk-only by construction, so it
    cannot run up an S3/R2 bill no matter how hard it's hammered.
  - **Per-IP mint throttle** (`FLUXFILES_DEMO_IP_MINTS`, default 20/hour): one IP can't
    spin up unlimited sandboxes. Returning visitors (valid cookie) are never throttled;
    a throttled IP just gets no fresh token (the UI reads it as "try again later").
  - **Global disk budget** (`FLUXFILES_DEMO_TOTAL_MB`, default 2000): the opportunistic
    purge now also deletes the oldest sandboxes when total demo bytes exceed the cap,
    bounding VPS disk regardless of visitor count.

## [0.2.71] — 2026-07-04

> Released: core `core-v0.2.62`.

### Added

- **Public demo mode (`FLUXFILES_DEMO=1`).** A self-hosted core can serve a safe
  "try it live" instance that a marketing site embeds by iframe and lets anonymous
  visitors upload for real. `/public/` mints a hardened per-visitor token — its own
  `demo/<id>/` sandbox on local, images only, small size/quota/file caps, owner-only,
  rate-limited, dangerous claims (terminal/import/byob) off — and injects it as
  `window.__FM_BOOT__`, so the token never reaches the embedding page. Old sandboxes
  auto-purge after `FLUXFILES_DEMO_TTL_HOURS`. (`DemoMode`, off by default.)
- **`window.__FM_BOOT__` server-injected boot config.** The UI can boot from a
  server-injected `{ token, disk, path, theme, … }` without a `?token=` URL (no
  address-bar leak) and without a postMessage parent — useful for self-hosted
  single-tenant deploys and the demo above.

## [0.2.70] — 2026-07-04

> Tooling (no core/package release): the license issuance service.

### Added

- **`services/license-server/`** — the vendor license-issuance back-office (separate
  from the stateless core). On a purchase webhook it verifies the signature, maps the
  plan → an Ed25519 license signed with your private key (the same key the core trusts),
  stores the customer record in SQLite, and returns the key. Webhook adapters for
  **Lemon Squeezy** (main store) + **Freemius** (WordPress); a Bearer-authed `/issue`
  for manual keys; `/licenses` + `/revoke` admin endpoints. Idempotent on
  `(gateway, order_id)`. Verified end-to-end against the real `LicenseManager` (6 tests)
  + over HTTP. The SQLite DB + private key are gitignored.

## [0.2.69] — 2026-07-04

> Released: core `core-v0.2.61`. Adapters unchanged.

### Added

- **`POST /api/fm/c2pa/sign`** route — signs a file with a C2PA provenance manifest via
  the (private) c2pa module. Paid + core-standalone like the other module endpoints.

### Changed (private paid modules — completed engines)

- The remaining 501 "not implemented" stubs in the paid modules are now real: **AI
  Vision** calls the operator's provider (remove.bg or a generic HTTP endpoint, BYO-key);
  **Virus scan** adds a VirusTotal path (privacy-preserving SHA-256 hash lookup — the
  file bytes never leave the server; fail-closed on API error); **C2PA** implements
  signing via `c2patool` + a `FLUXFILES_C2PA_MANIFEST` (operator's signing cert/key,
  never in a token). The module code ships in the private packages; the free core is
  unchanged besides the new sign route.

## [0.2.68] — 2026-07-04

> Released: core `core-v0.2.60`. Adapters unchanged.

### Fixed

- **License signing key rotated (unblocks commercial licensing).** The offline signing
  secret no longer matched the `k1` public key embedded in `LicenseManager`, so a minted
  license verified as `free` (paid modules could never unlock). Rotated a fresh Ed25519
  keypair; embedded the new `k1` public key. `scripts/license-gen.php` now mints licenses
  the shipped core verifies end-to-end (edition/modules/expiry/sites all carried). The
  private key stays offline + gitignored.
- **Release (update-manifest) signing key set.** `UpdateClient::RELEASE_PUBLIC_KEYS['r1']`
  was an empty placeholder (the self-hosted update channel couldn't verify a manifest).
  Set to a real key; a signed manifest now round-trips. Private key gitignored.

## [0.2.67] — 2026-07-04

> Released: core `core-v0.2.59`; node `0.1.24`, laravel `0.2.32`, wordpress `0.2.32`.

### Added

- **E-signature embed (free BYO).** The `esign_url` claim adds a "Sign" action on
  PDFs/documents that embeds a self-hosted **DocuSeal** (or any web e-sign tool). Like
  the office embed, `esign_url` may carry a `{url}` placeholder the UI substitutes with
  the selected file's presigned URL (URL-encoded) — the operator's page builds the
  signing request, so the core stays stateless. `http(s)` only (validated in Claims),
  sandboxed iframe, no new server endpoint. Completes the BYO-embed set (terminal / PDF /
  office / e-sign). Forwarded by embed/node/laravel/wordpress.

## [0.2.66] — 2026-07-04

> Released: core `core-v0.2.58`; node `0.1.23`, laravel `0.2.31`, wordpress `0.2.31`.

### Added

- **Office embed (free BYO).** The `office_url` claim adds an "Open in Office" action on
  Office files (.docx/.xlsx/.pptx/.odt/…) that embeds a self-hosted **Collabora / OnlyOffice**.
  Unlike PDF/terminal (a useful root UI), an office suite opens a *specific* document, so
  `office_url` may carry a **`{url}` placeholder** the UI substitutes with the selected
  file's presigned URL (URL-encoded) — the operator's own page does the WOPI/editor wiring,
  so the core stays stateless. `http(s)` only (validated in Claims), sandboxed iframe, no
  new server endpoint (works in every mode). Forwarded by embed/node/laravel/wordpress.

## [0.2.65] — 2026-07-03

> Released: core `core-v0.2.57`; node `0.1.22`, laravel `0.2.30`, wordpress `0.2.30`.

### Added

- **PDF tools (free BYO-embed).** The `pdf_tools_url` claim makes the UI show a "PDF
  tools" button that embeds a self-hosted **Stirling-PDF** (or any web PDF toolkit)
  for merge/split/OCR/convert — instead of FluxFiles building a competitor. Same
  pattern as `terminal_pty_url`: free/core config toggle, `http(s)` only (validated in
  Claims), sandboxed iframe, no new server endpoint (works in every mode incl. the
  proxy adapters). Forwarded by embed/node/laravel/wordpress. Empty (default) → no button.

## [0.2.64] — 2026-07-03

> Released: core `core-v0.2.56`; node `0.1.21`, laravel `0.2.29`, wordpress `0.2.29`.

### Added

- **Webhooks (paid module).** Fires an **HMAC-signed HTTP POST** to a `webhook_url` on
  file write events (upload/delete/move/copy/mkdir/…) — connect FluxFiles to
  Zapier/Make/n8n. Event-driven = stateless: it fires on the request that caused the
  event, **after the response is flushed** (`fastcgi_finish_request`) so a slow
  endpoint never adds to the client's latency; best-effort (a webhook failure never
  breaks the op). Payload `{event, timestamp, user, disk, path, name}` +
  `X-FluxFiles-Signature: sha256=<hmac>`. Claims: `allow_webhooks`, `webhook_url`
  (http/s only, non-http dropped), `webhook_events` (filter, empty = all),
  `webhook_secret` (HMAC key). SSRF-guarded (blocks internal targets) with a
  `FLUXFILES_WEBHOOK_ALLOW_INTERNAL=true` opt-out for a self-hosted n8n. New
  `POST /api/fm/webhooks/test` ping endpoint. Ships in the private `fluxfiles/webhooks`
  package; free core returns `501 module_not_installed`.
- **`.claude/agents/` — project subagents** (spec-writer / coder / reviewer / tester),
  each preloaded with FluxFiles' conventions, for a spec → code → test → review flow.

## [0.2.63] — 2026-07-03

> Released: core `core-v0.2.55`; node `0.1.20`, laravel `0.2.28`, wordpress `0.2.28`.

### Added

- **File Versioning (paid module).** Keeps prior versions of a file so an overwrite
  can be undone — list past versions + **restore** any of them, with per-file
  **retention**. Storage-resident (no DB): before FileManager overwrites a file (the
  editor's `PUT /api/fm/content` save, or an overwrite upload), the core snapshots the
  current bytes into `_fluxfiles/versions/<hash>/` + a `manifest.json` (invisible to
  listing/search/ops). New endpoints `GET /api/fm/versions` +
  `POST /api/fm/versions/restore` (restore is itself reversible — it snapshots the
  current bytes first). Claims: `allow_versioning`, `versioning_max` (default 10, cap
  100), `versioning_max_mb` (default 25, skip huge files). The module ships in the
  private `fluxfiles/versioning` package; free core returns `501 module_not_installed`.
  New `FileManager::setVersionKeeper()` hook (like the optimize hook) wires it only
  when installed + licensed + the claim is set.

## [0.2.62] — 2026-06-28

> Released: core `core-v0.2.54`; node `0.1.19`, laravel `0.2.27`, wordpress `0.2.27`.

### Added

- **Intake / Upload Portals (paid module — the inbound twin of Share).** An operator
  mints a branded public "send us your files" link; anonymous visitors drop files
  **into the operator's storage** at a scoped folder, no account. A portal is a
  narrow **write-scoped token** + a storage-resident record (`_fluxfiles/intakes.json`)
  for the caps + received-counter; public uploads run through the normal upload
  pipeline (quota / dedup / variants / search index). New endpoints:
  `POST /api/fm/intake` (create), `/intake/list`, `/intake/revoke` (operator, gated by
  the `allow_intake` claim), plus public `GET /api/fm/intake/info` + `POST
  /api/fm/intake/upload` (authed by the portal token, no main JWT). Per-file size +
  extension, a total-files cap, optional password, and expiry are enforced. Reference
  landing page at `/public/intake.html`. The module ships in the private
  `fluxfiles/intake` package; free core returns `501 module_not_installed`.

### Fixed

- **Paid-module claim gate was broken.** `Claims::isAllowed()` had no case for the
  module claims (`allow_share`/`allow_ai_vision`/`allow_ocr`/`allow_virus_scan`/
  `allow_backup`/`allow_c2pa`), so `ModuleRegistry`'s layer-3 check **403'd every paid
  module** regardless of the token. Added all module claims (incl. `allow_intake`).
  Also fixed the share create route signing tokens with an undefined `$secret`.

## [0.2.61] — 2026-06-28

> Released: core `core-v0.2.53`. Adapters unchanged.

### Fixed

- **Code editor honoured `owner_only`.** `PUT /api/fm/content` now runs the same
  ownership check as delete/rename/move — with `owner_only` + `allow_code_edit` a user
  could previously overwrite another user's file content. (403 `owner_only`.)
- **Extract no longer clobbers silently + extracted files join the pipeline.**
  `POST /api/fm/extract` now honours `owner_only` and the `upload_collision` policy
  (rename/overwrite/reject) on existing destination files, and registers each
  extracted file like an upload — metadata + folder/search index + content hash +
  image variants (thumbnails) — so extracted images get thumbnails and show up in
  search instead of being on-disk only.
- **Cross-disk copy/move stopped silently overwriting the destination.**
  `crossCopy`/`crossMove` now return `409 name_exists` for an existing destination
  (matching same-disk `copy`) and a clean `404 not_found` for a missing source —
  closing a path where they could overwrite another user's file across disks.

### Docs

- README: clarified the SSH terminal is a **real shell not confined to the SFTP
  `root`** (use a least-privilege SSH account), and why the dangerous-command list is
  a small accident-guard, not a security filter.

## [0.2.60] — 2026-06-28

> Released: core `core-v0.2.52`; adapters react `0.2.8`, vue `0.2.7`, sdk `0.2.6`,
> node `0.1.18`, laravel `0.2.26`, wordpress `0.2.26`.

### Added

- **One options-array token API + `claims` escape hatch.** `fluxfiles_token([...])`
  now takes a single options object; the `claims` map sets ANY JWT claim by its raw
  name (the same passthrough added to node/laravel/wordpress) so nothing is unsettable.
  The legacy positional signature still works.
- **`docs/CONFIG.md` — single configuration reference** for all 65 JWT claims + server
  env vars, with a guard test (`test-config-doc.php`) that fails if a claim is
  undocumented.

### Fixed

- **Watermark: logo over a transparent PNG was darkened.** intervention v3's
  opacity<100 `place()` composites through an opaque scratch image, so a logo placed
  on a transparent (alpha) PNG/WebP came out a dark box (JPEG was fine). Opacity is
  now baked into the logo's alpha and placed at 100% (`ImageCompat::bakeLogoOpacity`).
- **Modal dark-mode header flashed light (react/vue/sdk).** The modal chrome can't
  read the cross-origin iframe theme, so it flashed light before a dark theme settled.
  It now mirrors the embedded UI's anti-flash boot (resolves from
  `localStorage['fluxfiles_theme']` when theme is auto/unset; persists an explicit one).

### Security

- **JWT-in-URL hardening.** The standalone `?token=<JWT>` is now stripped from the URL
  on boot (`history.replaceState`) and `/public/index.html` sends
  `Referrer-Policy: no-referrer` (+ a `<meta referrer>` backup) — a full-privilege JWT
  can no longer leak via history / access logs / `Referer` / the copy buffer. CSRF uses
  the `Origin` header, so it's unaffected.

## [0.2.59] — 2026-06-28

> Released: core `core-v0.2.51`. Adapters unchanged.

### Added

- **Free PTY terminal toggle.** The `terminal_pty_url` claim makes the UI embed a
  self-hosted PTY server (ttyd / gotty / wetty, on the operator's own box) for a true
  interactive terminal; empty (default) → the stateless command-runner. Free/core, no
  custom sidecar — only `http(s)` URLs accepted. Forwarded by embed + laravel
  (standalone) + node SDK.

## [0.2.58] — 2026-06-28

> Released: core `core-v0.2.50` (Packagist + Docker). Adapters unchanged.

### Added

- **Free AVIF delivery in `/api/fm/img`.** `format=auto` (default) content-negotiates
  **AVIF → WebP → original** from the `Accept` header (AVIF when the build supports it);
  `format=avif`/`webp` force it. AVIF and WebP cache as separate files; the response
  sends `Vary: Accept`.
- **On-demand box sizing.** `/api/fm/img` gains `height` + `fit` (`cover` crops /
  `contain` fits, default) + `dpr` (1/2/3) — folded into the cache key, never upsizing.

### Changed

- **Optimization is now FREE / core** (was a paid module). Image → WebP recompression
  at rest + Ghostscript PDF compression + batch + on-upload `auto_optimize` + savings
  in the usage dashboard now ship in the MIT core (`POST /api/fm/optimize`, opt-in via
  the free `allow_optimize` claim). The AVIF-at-rest path and the `optimize_format`
  claim were removed (AVIF is a free delivery feature now); the `packages/optimize/`
  paid module is retired.

## [0.2.57] — 2026-06-27

> Released: core `core-v0.2.49` (Packagist + Docker). Adapters unchanged.

### Added

- **Non-destructive burn-in watermark (keep original · re-edit · remove).** Burning
  a watermark in place now snapshots the true original to `_fluxfiles/originals/`
  on the first burn, so re-editing reads from the clean original (re-positioning no
  longer stacks a second mark) and a new **Remove watermark** action (`POST
  /api/fm/watermark/remove`) restores it byte-for-byte. Files carry
  `meta.watermarked`; the editor shows the Remove button. Matches the model used by
  the major image-watermark tools (never destroy the original). i18n across 16
  locales; +2 tests.

## [0.2.56] — 2026-06-27

> Released: core `core-v0.2.48` (Packagist + Docker). Adapters unchanged.

### Fixed

- **SSH terminal: "directory not found on every command" + slowness.** The client
  tracks cwd relative to the disk root, but the route ran `cd <relative>` from the
  SSH login home (`/root`), not the SFTP root — so opening the terminal inside a
  subfolder broke every command. New `SshTerminal::resolveCwd()` anchors a relative
  cwd under the SFTP root. Also dropped the per-command shell-availability probe (a
  second SSH round-trip on every command) — `run()` now reports `shell_ok` from its
  cwd marker, halving the exec round-trips. (Remaining latency is the per-request
  SSH reconnect, inherent to the stateless model.)
- **Watermark: the two modes can no longer collide on one image.** With a preview-
  only **overlay** token the Crop and Watermark **editor tabs were still shown but
  their canvas used the (withheld) clean `url`** → a broken image; and burning in
  was possible, double-watermarking a file you can't even download. Now those tabs
  are hidden for a preview-only token, and `FileManager::applyWatermark()` rejects
  burn-in when an overlay watermark is active (`409 watermark_overlay_active`,
  enforced for every caller incl. the adapter proxies). Burn in with a normal,
  downloadable token instead.

### Added

- i18n: `error.watermark_overlay_active` across all 16 locales.

## [0.2.55] — 2026-06-27

> Released: Laravel `fluxfiles/laravel` `laravel-v0.2.25`, Node `@fluxfiles/node`
> `node-v0.1.17`. Core + WordPress unchanged.

### Added

- **`allow_terminal` is now forwardable by the standalone-targeting adapters.**
  The SSH terminal is core-standalone (`/api/fm/terminal` isn't proxied), so the
  claim was only mintable via `embed.php`. Now the **Laravel adapter (standalone
  mode)** and **`@fluxfiles/node`** forward it too (Laravel proxy mode + WordPress
  still drop it — the endpoint 404s there), making terminal support consistent
  with the overlay watermark.

### Docs

- **README spells out adapter support for watermark and terminal.** Burn-in
  watermark works on **every** adapter (proxied `POST /api/fm/watermark`); the
  overlay watermark and the SSH terminal are core-standalone and forwarded only by
  `embed.php` / `@fluxfiles/node` / Laravel `standalone` mode (not WordPress or
  Laravel proxy).

## [0.2.54] — 2026-06-27

> Released: Laravel `fluxfiles/laravel` `laravel-v0.2.24`, WordPress `0.2.25`
> (manual zip). Core unchanged.

### Fixed

- **Adapters no longer mint a broken overlay-watermark token.** Since an overlay
  watermark forces the token preview-only (clean URL withheld; only the
  watermarked `img_base` is served), and the **proxy** adapters don't expose
  `/api/fm/img` (nor set a stream secret), such a token produced images with
  *neither* a clean URL *nor* a preview — broken. Now:
  - **WordPress** (proxy-only) no longer forwards the `watermark_enabled` overlay
    claim. Use the **burn-in** watermark (`POST …/api/fm/watermark`), which is
    proxied and writes the mark into the file.
  - **Laravel** forwards the overlay claim only in **`standalone` mode** (where the
    token targets a real core that serves `/img`); in proxy mode it's dropped.
  - `embed.php`, `@fluxfiles/node`, and React/Vue (which target a standalone core)
    are unaffected — overlay works there. Burn-in works on every adapter.

  README's watermark section documents the adapter behaviour.

## [0.2.53] — 2026-06-27

> Released: core `core-v0.2.47` (Packagist + Docker). Adapters unchanged.

### Changed

- **A watermark overlay now implies preview-only — no more contradictory state.**
  Previously `watermark_enabled` (overlay) + `allow_download=true` was a no-op
  combination: the clean original was served everywhere, so the watermark
  protected nothing. The overlay's whole purpose is to withhold the clean image,
  so it now **forces `allow_download=false`**: `list()` serves only the
  watermarked `img_base` (clean `url`/`permanent_url`/`variants` withheld, GET
  presign → 403, no zip). To sell the clean file later, issue a separate token
  without the watermark. (The burn-in editor is unaffected.)

### Fixed

- **UI no longer offers Download / Copy URL / Download ZIP for a preview-only
  (watermark) image.** Those actions read the raw `allow_download` token claim
  (still `true`) while the server enforced the coupling, so they showed but would
  fail. The card thumbnail, detail panel, and lightbox now render the watermarked
  `img_base` (instead of a broken image), a **"Preview" badge** marks protected
  images, and the download/copy/zip actions are hidden when there's no clean URL.
- i18n: `file.preview_only` badge label across all 16 locales.

## [0.2.52] — 2026-06-27

> Released: TinyMCE `@fluxfiles/tinymce` `0.3.2`, CKEditor 4 `@fluxfiles/ckeditor4`
> `0.3.2`, Summernote `@fluxfiles/summernote` `0.1.2` (npm). Core unchanged.

### Fixed

- **Editors no longer insert a clean/broken image for a preview-only file.** When
  a token is preview-only (`allow_download=false`, e.g. a watermark **overlay**),
  `list()` withholds `url`/`permanent_url` and only the short-lived `img_base`
  token URL remains. The TinyMCE / CKEditor 4 / Summernote plugins resolved
  `permanent_url || url || ''` → they'd insert either the **clean original**
  (bypassing the watermark) or, with nothing, an empty `<img src="">`. They now
  detect "no embeddable URL", **skip the insert and warn** ("…burn in the
  watermark (Watermark editor → Apply) or issue a downloadable token…"). To embed
  a *watermarked* image, use the **burn-in editor** (its `permanent_url` is stable
  and watermarked); the overlay is a serve-time preview only. Documented in the
  watermark section.

## [0.2.51] — 2026-06-27

> Released: core `core-v0.2.46` (Packagist + Docker).
> (Adapters unchanged — terminal is core-standalone, like stream/img; adapter
> core floor stays `^0.2.39`.)

### Added

- **SSH terminal for SFTP disks (opt-in).** A built-in terminal (xterm.js) on an
  SFTP disk: `POST /api/fm/terminal` runs one command per request over the
  existing SSH connection (phpseclib `exec`), threading the working directory
  back so `cd`/`pwd` persist. It's a stateless command-runner — great for
  `git pull` / `composer install` / `chmod -R` / `tar`, but not a PTY (no
  vim/top/nano, no mid-command stdin). UI uses the FluxFiles palette (dark slate
  + brand purple). xterm.js is **vendored** in `assets/vendor/xterm/` (no CDN) so
  it works offline / when a CDN is blocked. Security model:
  - `allow_terminal` claim, **default OFF** — must be opted in deliberately.
  - SFTP disks only, requires the `write` permission, audited per command.
  - Server kill-switch `FLUXFILES_TERMINAL_DISABLED=true`; per-command timeout
    `FLUXFILES_TERMINAL_TIMEOUT` (default 30s); output capped at 2 MB.
  - Catastrophic-command guardrail (`rm -rf /`, `mkfs`, fork bomb, `chmod -R 777
    /`, …) → a two-step confirm in the UI. Opt out with
    `FLUXFILES_TERMINAL_CONFIRM=false`. This is an ACCIDENT guard, not a sandbox —
    the real boundary is the SSH user's OS permissions (use a least-privilege user).
  - **Shared hosting without a shell** (`internal-sftp`/`ForceCommand`) is detected
    (an `echo` probe) and shown a clear "this host doesn't allow a terminal (SFTP
    only)" message; the feature degrades instead of hanging.
- i18n: `terminal.*` UI strings + `error.terminal_*` + `audit.action.terminal`
  across all 16 locales.

### Changed

- **CI runs on Node 22** (was 20) — the current stable LTS.

## [0.2.50] — 2026-06-27

> Released: core `core-v0.2.45` (Packagist + Docker).
> (Adapters unchanged — core-only; adapter core floor stays `^0.2.39`.)

### Fixed

- **Error messages now localise for every error code.** 33 `error_code`s thrown by
  the core (chmod/zip/extract/trash/sftp/update/edit/disk… e.g. `chmod_forbidden`,
  `not_sftp`, `zip_slip`, `invalid_mode`, `storage_not_writable`) had no
  `error.<code>` i18n key, so a non-English user saw the raw English fallback. All
  are now translated across the 16 locales (394 keys each), so the embedded UI's
  `t('error.<code>')` shows the message in the user's language.

### Added

- **i18n regression guard:** `test-i18n.php` now scans `api/*.php` for every
  `ApiException(..., 'code')` and fails if any thrown error code lacks an
  `error.<code>` key — so a new server error can never again leak raw English to
  translated UIs.

## [0.2.49] — 2026-06-27

> Released: core `core-v0.2.44` (Packagist + Docker).
> (Adapters unchanged — core-only; adapter core floor stays `^0.2.39`.)

### Fixed

- **Bucket Doctor now diagnoses SFTP disks properly.** SFTP previously fell through
  to the *local* report, so a wrong password / unreachable host / host-key mismatch
  / read-only account was mislabeled "Local storage is not writable" with a local
  remediation. There's now a dedicated SFTP branch with connect+auth → write → read
  checks and SFTP-specific fixes (host/port, password-or-key, host-key fingerprint),
  and the real cause is unwrapped from the Flysystem wrapper (e.g. "Unable to
  authenticate using a password" instead of a generic listing error). Note: FluxFiles
  uses only the SFTP **subsystem** (file transfer) — it never opens a shell, so an
  SFTP-only account with no terminal access works fine.
- **Audit log: no more "unknown" actions, and actions are localised.** Write routes
  for watermark, optimize, extract, chmod and content-edit weren't in the audit
  action map, so they logged as `unknown`. They're mapped now, and the activity
  panel renders a localised label per action (`audit.action.*`, 16 languages, raw
  code as fallback) instead of the raw English token.

### Added

- i18n: `audit.action.*` (23 action labels) across all 16 locales.

## [0.2.48] — 2026-06-27

> Released: core `core-v0.2.43` (Packagist + Docker).
> (Adapters unchanged — core-only UI change; adapter core floor stays `^0.2.39`.)

### Changed

- **Toolbar: filter / sort / view moved to their own row.** Following 0.2.47's
  single-row action cluster, the filter/sort/view "tail" now sits on a separate
  row below the actions (Upload / New folder / Import + bulk), instead of sharing
  the line. The action row still scrolls horizontally when crowded; the tail row
  is right-aligned. Verified across the full SFTP free-feature flow (upload,
  preview, chmod, rename, trash/restore) in a live browser.

## [0.2.47] — 2026-06-27

> Released: core `core-v0.2.42` (Packagist + Docker).
> (Adapters unchanged — core-only fixes; adapter core floor stays `^0.2.39`.)

### Fixed

- **SFTP no longer hangs the UI on large trees.** The storage meter + usage
  dashboard ran a *recursive* `listContents` on every navigate; over SFTP that's
  one round-trip per directory (~9 entries/sec on a real webroot), so a multi-site
  `/var/www` effectively froze the file list with endless skeletons. Usage is now
  reported as unsupported for SFTP (no recursive remote walk) — the storage meter
  hides itself and the dashboard shows a short "not available for this disk" note.
  Plain listing (one directory, non-recursive) was always fast and is unaffected.
  Found while testing against a live SFTP server.
- **SFTP mkdir/upload no longer fails with a false "storage not writable".** The
  index lock used PHP's *local* `mkdir()/fopen()` on the disk's `root` — but an
  SFTP disk's `root` is a path on the REMOTE host, so it hit the app server's own
  (absent/unwritable) `/var/www` and aborted the operation even though the remote
  write actually succeeded. The local lock is now taken only for the `local`
  driver (S3/R2/SFTP skip it, as S3 always has); index data still writes through
  Flysystem (disk-aware), so it lands correctly on the remote.

### Changed

- **Toolbar is now a single scrolling row.** Upload / New folder / Import and the
  bulk actions (rename, delete, move, copy, download…) stay on ONE row that
  scrolls horizontally when crowded, instead of wrapping to a second line in a
  narrow embed. The sort/filter/more dropdowns sit outside the scroll area so
  their pop-up menus are never clipped.

## [0.2.46] — 2026-06-27

> Released: core `core-v0.2.41` (Packagist + Docker), Laravel `fluxfiles/laravel`
> `laravel-v0.2.23`, WordPress `0.2.24` (manual zip).
> (SDK/React/Vue/Node unchanged since 0.2.45; adapter core floor stays `^0.2.39`
> — the session-expiry routes use existing manager methods, no new core API.)

### Security

- **SFTP host-key pinning (anti-MITM).** The SFTP disk connected without verifying
  the server's host key, so it trusted *any* key — a man-in-the-middle could
  impersonate the server. A new `SFTP_HOST_FINGERPRINT` (disk `host_fingerprint`,
  colon-hex; md5 for an RSA host key, sha512 otherwise; comma-separate for key
  rotation) is now passed to the connection provider, which rejects a mismatched
  host. `useAgent` is also forced off (never reach for a server-side ssh-agent).
  Backward-compatible: unset → previous behaviour, but `.env.example` documents it
  as recommended with the `ssh-keyscan | ssh-keygen -l` recipe to obtain it.

### Added

- **chmod dialog: quick presets + symbolic mode + typeable octal.** The SFTP
  file-permissions dialog now has one-click preset chips (`600 640 644 700 750
  755`), shows the symbolic string (`rwxr-xr-x`) next to the octal, and the octal
  is an editable field you can type into — all three stay in sync with the rwx
  checkboxes. No new i18n keys (octal/symbolic are language-neutral).

### Fixed

- **Session expiry now recovers without a full page reload (Laravel & WordPress).**
  When the embedded JWT expired, the adapters had no way to mint a fresh one: the
  Laravel `<x-fluxfiles>` blade and the WordPress shortcode/media-button embedded
  the SDK *without* an `onTokenRefresh` handler, so the iframe's auto-refresh and
  its "Try again" button both dead-ended at `FM_TOKEN_FAILED: no_handler` — only a
  page reload (which re-rendered a new token) worked. Two fixes:
  - **Adapters now auto-wire `onTokenRefresh`** to a session-authenticated re-mint
    endpoint — Laravel `GET {prefix}/token` (web/`auth` session, *not* the JWT
    middleware) and WordPress `GET /fluxfiles/v1/api/fm/token` (cookie + REST
    nonce, a `checkLoggedIn` permission callback since `checkAuth` rejects an
    expired Bearer). A still-logged-in user now refreshes silently; if the *session*
    is also gone the endpoint 401s and the UI falls back to login. Components with
    custom per-tag `overrides` can pass their own `:on-token-refresh` to preserve
    them. (React/Vue already expose `onTokenRefresh` — the dev supplies it.)
  - **The "Try again" button now calls `retryLoad()`** instead of posting
    `FM_TOKEN_REFRESH` directly. The direct post bypassed `_handleTokenExpired`,
    which is the only place that registers the `FM_TOKEN_UPDATED` listener — so even
    a working host handler's new token was never applied. `retryLoad()` resets the
    refresh-loop guard and re-enters the proper refresh cycle.
- **Toolbar buttons no longer clipped in a narrow container.** The action toolbar
  (Upload / New folder / Import / bulk Move/Copy/Download…) was a single non-wrapping
  flex row, so in a narrow embed (iframe/modal) the right-most buttons were cut off
  and unreachable. It now **wraps** to additional rows (buttons keep their size).
  (Can't use `overflow:auto` — it would clip the toolbar's drop-down menus.)
- **Zoomed image is easy to close.** A zoomed lightbox couldn't be dismissed by a
  backdrop click (that pans), so it felt stuck. Added an always-visible **× close
  button**, and **Escape now closes immediately** at any zoom level.

## [0.2.45] — 2026-06-27

> Released: core `core-v0.2.40` (Packagist + Docker), SDK `fluxfiles` `0.2.5`,
> React `@fluxfiles/react` `0.2.7`, Vue `@fluxfiles/vue` `0.2.6`.
> (Laravel/WordPress/Node unchanged since 0.2.44; adapter core floor stays `^0.2.39`.)

### Fixed

- **Consistent validation-error color.** Inline validation messages were split
  across two red tokens (`--ff-danger` vs `--color-text-danger`); the import-URL
  error now uses the same `--color-text-danger` as new-folder/doctor errors.
- **Bucket/disk doctor check labels are localised.** The permission-check rows
  showed the raw id (`reachability`, `write`, …) in every language; they now use a
  `doctor.check.*` label (×16 locales, raw id as fallback). The per-check
  `message`/`fix` stay English on purpose (provider/AWS technical detail).
- **Modal chrome now follows dark mode.** The React/Vue `FluxFilesModal` and the
  browser SDK overlay (Laravel/WordPress) had a hardcoded light-grey window/header,
  so dark mode only darkened the iframe content. The chrome now honours the `theme`
  prop (`dark`/`light`, or `auto` → `prefers-color-scheme`). Verified in Playwright.

### Docs

- **Watermark guide: overlay vs. burn-in.** Documented that the `watermark_*`
  overlay is preview-time only (via `/api/fm/img`; a file's clean `url` and any
  inserted `<img>` in TinyMCE/CKEditor have no watermark — by design), and that the
  free **watermark editor** (`POST /api/fm/watermark`) burns it into the file so it
  appears everywhere. Added a comparison table.

## [0.2.44] — 2026-06-25

> Released: core `core-v0.2.39` (Packagist + Docker), Laravel `laravel-v0.2.22`,
> WordPress `wordpress-v0.2.23`, Node `@fluxfiles/node` `0.1.16`. Adapter core
> floors → `^0.2.39`. (SDK/React/Vue unchanged since 0.2.42.)

### Added

- **Watermark editor — drag-and-drop, burn-in (free).** Beyond the on-the-fly
  operator overlay (claim-driven, preview-time), images now have a **Watermark** tab
  with a visual editor: drag a logo/text to any position, resize the logo by a
  handle, set opacity (+ font size/color for text), then **Apply** (replace) or
  **Save as copy** — the watermark is burned into the file. New `POST /api/fm/watermark`
  (write perm; logo uploaded as base64), `ImageCompat::placeLogoAt()/drawTextAt()`
  (free x/y + scale), `ImageOptimizer::burnWatermark()`, `FileManager::applyWatermark()`
  (extension-immutable, variant regen). Proxied by Laravel + WordPress; `watermark`
  i18n namespace ×16. Engine + UI tested.

- **Paid-module framework — 6 commercial modules scaffolded.** Beyond Optimize,
  the core now registers + gates five more modules (3-layer gate: installed +
  licensed + claim), each shipping as its own proprietary package (gitignored;
  private repo per module):
  - **Share** (`allow_share`, `POST /api/fm/share`) — public links with expiry /
    password / download-cap + view-download counter (engine complete).
  - **AI Vision** (`allow_ai_vision`, `POST /api/fm/ai-vision`) — bg-removal /
    upscale / smart-crop (BYO provider key).
  - **OCR** (`allow_ocr`, `POST /api/fm/ocr`) — text extraction (tesseract / BYO).
  - **Virus scan** (`allow_virus_scan`, on-upload hook) — ClamAV / BYO VirusTotal.
  - **Backup Bridge** (`allow_backup`, `POST /api/fm/backup`) — cross-disk sync.
  - **C2PA** (`allow_c2pa`, `POST /api/fm/c2pa`) — content provenance (c2patool).
  Core changes are MIT (registry + endpoints + `allow_*` claims forwarded by every
  token helper); engines that need a binary/provider key are availability-gated
  (501 until configured). Free core ships none of the packages → all 501 cleanly.
- **License/edition surface (UX).** The usage dashboard now shows an edition +
  license-status banner from `GET /api/fm/license` (edition pill, status, days-left,
  grace/perpetual/renew-soon notes; `license` i18n namespace ×16). And token helpers
  gain an **edition preset** — `fluxfiles_token(..., 'pro')` / `edition: 'pro'`
  (embed, Laravel, WordPress, Node) defaults a tier's claims (e.g. `allow_optimize`,
  `allow_share`); explicit claims still win and the license still gates the code.

## [0.2.43] — 2026-06-24

> Released: core `core-v0.2.38` (Packagist + Docker), Laravel `laravel-v0.2.21`,
> WordPress `wordpress-v0.2.22`, Node `@fluxfiles/node` `0.1.15`. Adapter core
> floors → `^0.2.38`. (SDK/React/Vue unchanged since 0.2.42.)

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

- **`license-gen` vendor tool (`scripts/license-gen.php`).** Mints the Ed25519
  license tokens `LicenseManager` verifies offline — `--genkey` to create a signing
  keypair, then `--edition/--modules/--enforcement/--expires/--sites/...` to sign a
  customer key. Private key via `--key`/`FLUXFILES_LICENSE_PRIVATE_KEY` (never
  committed); excluded from the customer dist (scripts/ is export-ignore). The only
  piece needed to start selling manually (buy → run license-gen → email the key);
  automate later with a payment webhook. Round-trip tested against LicenseManager.
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
