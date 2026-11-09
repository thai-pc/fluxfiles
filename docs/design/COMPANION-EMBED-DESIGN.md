# Companion embed: import from cloud drives via the operator's own Uppy Companion

> Status: **design, not implemented** · 2026-11-08 · free/core (BYO-embed, no module)
>
> **Decision.** Add a free claim `companion_url`. When it is set, the UI shows a "From cloud"
> entry that lazy-loads a **vendored** Uppy Dashboard. The Dashboard talks to the operator's
> self-hosted Companion. Companion streams each picked file to a new ticket-authed FluxFiles
> endpoint, **`POST /api/fm/companion/upload`**, as a plain multipart POST. That endpoint
> runs the **normal `FileManager::upload()` pipeline**, so the token's scope, `max_upload`,
> `allowed_ext`, quota, virus/DLP (fail-closed), legal hold, `owner_only`, `strip_exif`,
> audit and webhooks all apply unchanged. The credential Companion forwards is a
> **short-lived, single-folder, upload-only `t=companion` ticket**. The main JWT is sealed
> inside it with AES-GCM, using the `SsoBootToken` pattern. Neither Uppy nor Companion ever
> sees the main JWT, and it is never put in a URL.
>
> **One premise corrected.** The brief says tus is "refused while scanning is on". It isn't.
> The last tus PATCH runs `FileManager::upload()`, which scans the bytes before writing them
> (`TusServer.php:202`, `rules/virus-dlp.md`). Only `/chunk/*` and presigned PUT/POST answer
> `409 virus_unscannable`. Tus is still rejected below, but for other reasons (§3).

---

## 1. Problem & who pays

Embedders get one request more than any other from end users: "let me pick the file
straight from my Google Drive / Dropbox / OneDrive / Box instead of downloading it first",
plus "take a photo with my webcam". Hosted pickers sell exactly this:
- Filestack's paid tiers start around $69/mo ([costbench](https://costbench.com/software/document-management/filestack/), [capterra](https://www.capterra.com/p/152728/Filestack/)).
- Transloadit bundles a *hosted* Companion with every plan ([Transloadit docs](https://transloadit.com/docs/sdks/uppy/), [plans](https://transloadit.com/plans)).

The OSS answer is **Uppy + Companion**. Both are MIT, and Companion is self-hostable
([uppy.io/docs/companion](https://uppy.io/docs/companion/)). That makes this the textbook
BYO-embed case (`docs/COMMERCIAL-STRATEGY.md` §6a, same as `terminal_pty_url`, `pdf_tools_url`,
`office_url` and `esign_url`). It ships **free/core** as a claim, adds no `ModuleRegistry`
entry and sells no SKU. It widens the funnel: FluxFiles becomes the hub where remote files
land under the operator's rules.

- **Persona:** the self-hosting operator who embeds FluxFiles in a CMS/SaaS. They already
  run, or are willing to run, a Companion container with their own OAuth apps.
- **What FluxFiles holds:** no provider credentials, no OAuth apps, no provider API calls.
  Companion does all of that on the operator's infrastructure.

## 2. How Uppy and Companion work (researched, Uppy 6 / Companion 7)

- **Client ↔ Companion.** Provider plugins (`@uppy/google-drive`, `dropbox`, `onedrive`,
  `box`, `url`) take `companionUrl` and `companionAllowedHosts` (default: the origin of
  `companionUrl`; strings are regexes, so they must be escaped).
  - OAuth runs in a popup against Companion, using the **operator's own provider OAuth apps**.
  - Since Companion 7 the encrypted provider token reaches the client **over Companion's
    WebSocket**; `postMessage` is only a fallback.
  - Progress, errors and success also come back over that WebSocket.
  - An Uppy 6 client does not work against Companion ≤ 6. Companion 7 needs Node ≥ 22 and
    Express 5 ([companion docs](https://uppy.io/docs/companion/), [Uppy 6 overview](https://blog.openreplay.com/uppy-6-resumable-uploads/), [core 6.0.0 release](https://github.com/transloadit/uppy/releases/tag/@uppy%2Fcore@6.0.0)).
- **Companion → destination.** For a remote file, `@uppy/xhr-upload` sends Companion a body
  of `{protocol:'multipart', endpoint, size, fieldname, metadata, httpMethod, useFormData,
  headers}` (`#getCompanionClientArgs`). Companion downloads from the provider and
  **streams** to `endpoint` at the same time (`streamingUpload` defaults to true; nothing is
  stored). Protocols: `multipart` | `tus` | `s3-multipart`.
  - **Client-supplied `headers` are forwarded to the destination.** Operator
    `uploadHeaders` win on a name clash, and `forwarded`/`x-forwarded-*` are stripped.
    Headers are kept in memory only; Companion's Redis state holds progress and result
    payloads, never request headers.
  - On a multipart success **or** an HTTP error, the destination's `status` and
    `responseText` are relayed to the client. A tus success relays only the `url`.
    (Source: `packages/@uppy/companion/src/server/Uploader.ts`.)
- **Companion's own guards.**
  - `uploadUrls` / `COMPANION_UPLOAD_URLS` is the destination allow-list (exact origin,
    path-boundary match). It is **mandatory under `NODE_ENV=production`**.
  - `corsOrigins` / `COMPANION_CLIENT_ORIGINS` allow-lists the UI origin for both the CORS
    and OAuth-token paths.
  - `allowLocalUrls=false` (default) is its SSRF stance for the Url plugin.
  - `maxFileSize` is checked against the declared size *and* by counting bytes while
    streaming. For multipart, `limitToAnnouncedSize` fails a body longer or shorter than
    announced.
- **Webcam** (`@uppy/webcam`) is browser-only (`getUserMedia`). It needs no Companion; it
  produces a local Blob.
- **Google Drive caveat.** The classic plugin needs the *restricted* `drive.readonly` scope.
  A public production app then needs Google verification, which can include a CASA Tier 2
  assessment. The **Drive Picker** variant uses `drive.file` and needs no verification, but
  it runs Google's scripts in the page and needs a browser-side API key, client ID and app ID
  ([google-drive docs](https://uppy.io/docs/google-drive/)). See §15 Q1.
- **Licence:** Uppy and Companion are MIT. Locales ship for all 15 non-English FluxFiles
  languages: ar_SA, de_DE, es_ES, fr_FR, hi_IN, it_IT, ja_JP, ko_KR, nl_NL, pt_BR, ru_RU,
  th_TH, tr_TR, vi_VN, zh_CN ([locales](https://uppy.io/docs/locales/)).

## 3. Landing options: decision

| | (A) Companion → FluxFiles **tus** | (B) Companion → FluxFiles **multipart POST** with a scoped ticket ✅ | (C) Reuse **`/import-url`** |
|---|---|---|---|
| Scanned / pipeline | yes (last PATCH runs `upload()`) | yes (`upload()`) | yes |
| Proxied by Laravel/WP | **no**: tus is core-only by design | yes: same shape as `/upload` and `intake/upload` | yes |
| Credential at Companion | main JWT on *every* PATCH/HEAD, or the ticket accepted on 4 tus methods | one ticket header, one route | n/a |
| Result back to the UI | tus success relays only `url`; `X-FluxFiles-Result` is lost | full `{data,error}` envelope via `responseText`, so `error_code` stays i18n-able | full |
| Size integrity | Companion's tus path only *warns* on a size mismatch | `limitToAnnouncedSize` + PHP's own multipart parsing | — |
| Multi-node | needs a shared `storage/tus/` dir or sticky sessions | stateless | stateless |
| Remote providers | ✅ | ✅ | ❌ FluxFiles would have to call Drive/Dropbox APIs with **provider OAuth tokens**, which breaks "never FluxFiles' credentials". Companion has no "give me a fetchable URL" API. |
| Big files | resumable, beyond `upload_max_filesize` | bounded by PHP's `upload_max_filesize`/`post_max_size` (the same ceiling the proxies have today) | `max_import_mb` |

**Choice: (B).** It is the only option that works the same on core, Laravel and WordPress.
It keeps one ticket on one route. It needs no staging state. It returns FluxFiles' own error
codes to the UI. (A) stays a possible v2 for *core-standalone* large files (§15 Q3). (C) is
not viable for providers. For plain URLs, FluxFiles' own `/import-url` (`SsrfGuard`,
`allow_url_import`) already exists and stays the recommended path (§5, `companion_sources`).

## 4. Architecture fit and flow (stateless, nothing new under `_fluxfiles/`)

```
browser (FluxFiles UI, origin U)            FluxFiles API                 Companion (operator, origin C)
 1. click "From cloud" ─ POST /api/fm/companion/ticket {disk,path}
    Authorization: Bearer <main JWT>  ───►  write perm, disk, scope, companion_url on
                                       ◄─── {ticket (t=companion, ≤900 s), upload_url, max_bytes, …}
 2. lazy-import assets/vendor/uppy/*  (same origin, no CDN)
 3. Uppy Dashboard ── OAuth popup / list / thumbnails / WebSocket ───────────────►  C (own OAuth apps)
 4. Uppy → C: POST /<provider>/get/<id> {endpoint: upload_url,
            headers: {X-FluxFiles-Ticket: <ticket>}, fieldname:'file', protocol:'multipart'}
                                                                  C fetches from provider ──┐
 5.                                         POST /api/fm/companion/upload  ◄── C streams multipart
                                            verify ticket → unseal main JWT →
                                            JwtMiddleware::handle → Claims → upload()
                                            (scan, quota, hold, owner, exif, audit, webhook)
                                       ───► {data: record} ──► C relays status+responseText over WS
 6. UI: upload-success → refresh listing; upload-error → t('error.'+error_code)
```

- **Token:** the existing main JWT carries the new claims (§5). It mints a narrow
  `t=companion` ticket, following the rule that a portal IS a narrow scoped token.
- **Storage:** nothing new. Imported files are ordinary uploads (sidecars, variants, audit
  under `_fluxfiles/` exactly as `/upload`). No DB, no queue, no server-side ticket table.
- **FluxFiles makes no outbound request.** `companion_url` is only ever used by the
  *browser*, so this feature needs no `SsrfGuard` call. The inbound fetch-and-push is
  Companion's job, under Companion's `uploadUrls`/`allowLocalUrls`.

### The ticket (`CompanionTicket`, modelled on `SsoBootToken`)

- HS256 JWT with `FLUXFILES_SECRET`. Outer claims: `t:'companion'`, `jti`, `iat`, and
  `exp = min(now + 900, main.exp)`.
- `enc` = AES-256-GCM over the JSON `{jwt, disk, path, ip}`. The key is
  `hash_hkdf('sha256', secret, 32, 'fluxfiles-companion-ticket-enc')`, a distinct key per
  the existing HKDF-label convention.
  - `jwt` is the caller's main access JWT, verbatim.
  - `path` is the request-relative folder; `import_path` wins when set.
  - `ip` is the client IP at mint time, used for audit.
  - Everything sits inside `enc`, so the readable payload leaks no tenant layout to Companion.
- Verify:
  1. Signature and expiry, else `403 companion_ticket_invalid`.
  2. `t === 'companion'`, else invalid.
  3. Unseal, else invalid.
  4. `JwtMiddleware::handle(ctx.jwt)`, so the main JWT's own expiry and type checks re-run.
  5. `claims->companionUrl !== ''`, else `403 companion_disabled`. A claim revoked
     mid-session stops new imports.
- **Cross-use is already blocked.**
  - A ticket at any normal route is refused by `JwtMiddleware::assertAccessToken`
    (`403 token_not_access`), because it carries `t`.
  - A main JWT at `/companion/upload` has no `enc` and fails verify.
- **Not single-use** (no server-side store). It can be replayed within its TTL, the same
  posture as `StreamToken`/`SsoBootToken`. A leaked ticket gives ≤ 15 min of uploads into
  one folder under every limit of the main JWT, which is strictly less than the main JWT.
- Header-size guard: refuse to mint a ticket over 7,680 bytes (a large BYOB JWT) with
  `413 companion_ticket_too_large`. That leaves room under the 8 KB header-line default of
  nginx and Node.

## 5. JWT claims (all free; add to `docs/reference/CONFIG.md` §2.2 or a new §2.x "Cloud import")

| Claim | Type | Default | Validation / notes |
|---|---|---|---|
| `companion_url` | string (http/s) | `""` | Base URL of the operator's Companion. `trim`, then must match `^https?://`. `parse_url` needs a host, and the URL must have **no userinfo, query or fragment**. Length ≤ 2048 bytes; a trailing `/` is trimmed. Anything else is dropped to `""`. If the env var `FLUXFILES_COMPANION_ORIGINS` is set (comma-separated exact origins), a claim whose origin is not listed is also dropped. Empty → no UI entry, and `/companion/*` answers `403 companion_disabled`. The UI re-checks the scheme for visibility (same as its siblings), but **Uppy is configured from the server-validated value the ticket response returns**, so the env allow-list is authoritative. Free BYO-embed. |
| `companion_sources` | string[] | `["google_drive","dropbox","onedrive","box"]` | Which tabs the Dashboard shows. Lowercased and filtered to `google_drive`, `dropbox`, `onedrive`, `box`, `url`, `webcam`; unknown values dropped, duplicates removed. Absent → default. Present but empty after filtering → no entry. **UX only, not a security boundary**: whoever holds a ticket can use any provider *Companion* is configured for. Restrict providers in Companion's own config. `url` is off by default because `/import-url` is the stricter native path. `webcam` is off by default because it needs the embedder's iframe `allow="camera"` (§9). |
| `companion_rate_limit` | int | `0` | Uploads/min per user on `/companion/upload`. `0` = inherit `FLUXFILES_COMPANION_RATE_LIMIT` (default **30**). Clamp `max(0, …)`. |

New env vars, also documented in CONFIG.md:
- `FLUXFILES_COMPANION_ORIGINS`: optional origin allow-list for `companion_url`.
- `FLUXFILES_COMPANION_RATE_LIMIT`: default 30.
- `FLUXFILES_COMPANION_UPLOAD_URL`: optional absolute override of the destination URL that
  Companion must reach, for split-horizon networks where Companion sees FluxFiles under an
  internal hostname.

Deliberately **no** new size claim. `max_upload` governs, plus PHP's ini limits and
Companion's `maxFileSize` (§15 Q4).

`embed.php` forwards the three claims as named options (next to `esign_url`,
`embed.php:576`). The node/python/laravel/wordpress token builders need nothing: the
`claims` escape hatch already covers them.

## 6. Endpoints

| Method + path | Auth | Request | Response |
|---|---|---|---|
| `POST /api/fm/companion/ticket` | main JWT (`Authorization: Bearer`) | JSON `{disk, path}` | `{data:{ticket, expires_at, refresh_after:300, upload_url\|null, companion_url, sources[], disk, path, max_bytes, allowed_ext\|null}, error:null}` |
| `POST /api/fm/companion/upload` | **ticket** in header `X-FluxFiles-Ticket` (never a query param; a `?ticket=` is ignored) | `multipart/form-data`, field `file`. Every other form field is **ignored**; disk and path come only from the ticket. | the `/upload` envelope `{data: record, error:null}`, or `{data:null, error, error_code}` |

**Ticket route.** It runs inside the authed block in `routeRequest`.
- Checks: `companion_disabled`, then `requirePerm('write')`, the disk in the token, the path
  scope and `show_hidden`. When `import_path` is set, it is forced as the folder.
- `max_bytes` = min(`max_upload`, ini `upload_max_filesize`, ini `post_max_size`). Uppy's
  `restrictions` use it so oversize picks fail before transfer. That is UX; the server still
  enforces.
- `upload_url` is `FLUXFILES_COMPANION_UPLOAD_URL`, or the adapter's own route URL, or
  `null`. On `null` the UI derives `new URL(joinUrl('/api/fm/companion/upload'), location)`.
- Rate limit: the normal write bucket. Audit action: `companion_ticket`, with detail
  `{disk, path}`.

**Upload route.** `index.php` takes a small branch *before* `JwtMiddleware::extractToken()`:

```php
$companionCtx = null;
if ($method === 'POST' && $uri === '/api/fm/companion/upload') {
    $companionCtx = \FluxFiles\CompanionTicket::verify((string) ($_SERVER['HTTP_X_FLUXFILES_TICKET'] ?? ''), $secret);
    $token = $companionCtx['jwt'];
} else {
    $token = JwtMiddleware::extractToken();
}
$claims = JwtMiddleware::handle($token, $secret);
```

Everything after that is shared: DiskManager/BYOB, `FileManagerFactory::wire` (virus/DLP
fail-closed), the audit log, webhooks and the refused-write catch. The only differences:
- **Rate bucket:** a `companion` bucket (`companion_rate_limit`) *replaces* the write bucket
  for this route, the way tus PATCH has its own. With write = 10/min, a 20-file Drive batch
  would otherwise stall.
- **Handler:** `CompanionUpload::handle($claims, $fm, $ctx, $_FILES['file'])`:
  1. `no_file` if absent.
  2. Apply the **import-url content policy**: magic-byte MIME deny-list, SVG off unless
     `FLUXFILES_IMPORT_ALLOW_SVG`. Factor it out of `UrlImporter::finishImport` into a
     shared `RemoteContentGuard`. FluxFiles cannot tell a Drive file from a Companion
     `url` fetch, so all Companion bytes are treated as remote.
  3. Call `$fm->upload($ctx['disk'], $ctx['path'], $file, false)`, so `upload_collision`
     governs overwrite.
- **Audit:** action `companion_import`, with detail `{via:'companion', ticket_ip}`. The
  request IP is Companion's, so the signed mint-time IP is the useful one. Disk and key
  come from the **result record**, never from form fields.
- **Webhook:** event `companion_import`. `AuditPolicy::ACTIONS` matches by *substring*, so
  `'/companion/upload'` and `'/companion/ticket'` **must sit above `'/upload'`**, or the
  route would resolve to `upload`.
- **CSRF:** Companion sends no `Origin` header, so it passes the Origin check. A browser
  webcam POST is same-origin. The ticket is a custom header that is never auto-attached, so
  the route is not a CSRF vector.

## 7. Storage layout

None new. Files land exactly like `/upload`: the object, `_fluxfiles/meta/{key}.json`,
variants, posters, the index, and audit lines in the existing log. Nothing persists for
tickets.

## 8. UI: entry point, loading, vendoring, CSP, i18n

- **Entry.** A toolbar button "From cloud" next to "Import from URL"
  (`public/index.html:375`), with `x-show="canCompanion"`. `canCompanion` requires the
  payload `companion_url` to pass the http(s) regex, the `write` perm, and a non-empty
  sources list after filtering. Mirror it in the mobile upload menu.
- **On click,** `openCompanion()` does four things:
  1. POSTs `/companion/ticket` with `{disk: currentDisk, path: currentPath}`.
  2. Shows the destination ("Saving to /photos").
  3. `await import(FM_ASSETS_BASE + '/vendor/uppy/uppy.min.mjs')` (a dynamic import, which
     works from the classic `fm.js`), plus the CSS link and the locale pack.
  4. Mounts `Dashboard` inline in an `ff-modal` (the terminal/PDF modal chrome).
- **Uppy config** (one instance per open, destroyed on close):
  - Each provider plugin gets `companionUrl` from the ticket response and
    `companionAllowedHosts` = the **regex-escaped exact origin**.
  - Each provider gets `storage` = an in-memory or `sessionStorage` store namespaced by a
    hash of `sub`. The default `localStorage` would let a second FluxFiles user in the same
    browser and origin reuse the first user's Drive session.
  - `XHRUpload` with `endpoint = upload_url`, `method:'POST'`, `formData:true`,
    `fieldName:'file'`, `allowedMetaFields: []`, and `limit: 3`.
  - `headers: () => ({ 'X-FluxFiles-Ticket': currentTicket })`. The UI re-mints the ticket
    every `refresh_after` seconds while the dialog is open, so long batches keep working.
  - `restrictions: {maxFileSize: max_bytes, allowedFileTypes: allowed_ext?.map(e => '.' + e)}`.
  - Hide the local-device source: Dashboard `disableLocalFiles` (confirm the option name at
    implementation). Local uploads stay on FluxFiles' own tus/chunk/plain path.
  - The Webcam plugin only when `webcam` is listed. It uses the same XHRUpload and ticket,
    so **Uppy is never given the main JWT, for any file**. That is the reason for a single
    endpoint.
- **Results.** On `upload-success`, debounce a `loadFiles()` and toast "N files imported".
  On `upload-error`, parse `responseText.error_code` and show `t('error.' + code)`. Closing
  with transfers in flight asks for confirmation, then calls `uppy.cancelAll()`.
- **Vendoring.** Pin the official prebuilt bundle (Uppy 6.x ESM `uppy.min.mjs` plus
  `uppy.min.css`; the docs' CDN example references v6.0.7) into
  `packages/core/assets/vendor/uppy/`, with `LICENSE` (MIT) and `locales/<id>.min.js` for
  the 15 locales. Add a row to `THIRD-PARTY-NOTICES.md`.
  - **No CDN at runtime.** Reasons: supply chain, air-gapped installs, and WordPress.org
    forbids remotely loaded JS.
  - Nothing loads until the dialog opens, so the boot payload is unchanged. Measure the
    bundle size and record it in the PR.
  - The locale map: FluxFiles `en` → built-in; `de` → `de_DE`, `pt` → `pt_BR`,
    `zh` → `zh_CN`, `ar` → `ar_SA`, and so on. Confirm how the ESM bundle consumes locale
    packs at implementation; the UMD packs attach to `Uppy.locales`.
- **CSP.** Today `/public/index.html` sends only `frame-ancestors`, so nothing blocks the
  feature. Document for operators who add a CSP:
  - `connect-src` needs the Companion `https:` and `wss:` origins.
  - `img-src` needs the Companion origin (thumbnails are proxied), and `blob:` for webcam.
  - `media-src blob:` for the webcam preview.
  - `script-src`/`style-src 'self'` already suffice because the assets are vendored.
  - OAuth uses a popup (`window.open`), so no `frame-src` is needed. The embed iframes carry
    no `sandbox` attribute, so popups are allowed. A host page with
    `Cross-Origin-Opener-Policy: same-origin` only affects the `postMessage` fallback; the
    WebSocket path is unaffected.
- **i18n (×16 locales, `lang/*.json`):**
  - `companion.button`, `companion.title`, `companion.destination` (`{path}`),
    `companion.loading`, `companion.load_failed`, `companion.cancel_confirm`,
    `companion.done` (`{count}`).
  - `error.companion_disabled`, `error.companion_ticket_invalid`,
    `error.companion_ticket_too_large`, and `error.companion_unreachable` (UI-side, when
    Uppy can't reach Companion).
  - Audit labels `companion_ticket` and `companion_import`, next to `url_import`
    (`en.json:453`).
  - Uppy's own strings come from its locale packs.
  - Rebuild `fm.min.js`/`fm.min.css` (`scripts/build-ui.sh`).

## 9. Adapters

| Adapter | Must forward | Notes |
|---|---|---|
| **Laravel** | `POST companion/ticket` in the auth group. `POST companion/upload` **outside** the auth/CSRF group, registered in `FluxFilesServiceProvider` beside `intake/upload`. | The controller calls core's `CompanionTicket`/`CompanionUpload` with its own `FileManager` (already wired for virus/DLP like its `/upload`). Its `upload_url` = `route(...)`. Add both routes to the proxy-parity smoke. Bump the adapter's core floor to the first core release whose code calls these classes (`check-adapter-core-floor.sh` enforces it). |
| **WordPress** | `register_rest_route(.../companion/ticket)` with the normal permission callback. `.../companion/upload` with `$public` (`'__return_true'`), mirroring `intake/upload` (`FluxFilesApi.php:466`); the ticket is verified inside. | `upload_url` = `rest_url()`. Reading a custom `X-FluxFiles-Ticket` header sidesteps the Apache/CGI `Authorization` stripping WP hosts are known for. The smoke covers both routes; the build already ships `assets/vendor/`. |
| **SDK / React / Vue / Laravel JS / WP JS** (`IFRAME_ALLOW`) | n/a | Webcam inside an iframe needs `allow="camera; microphone"`. Add an **opt-in** option (e.g. `allowCamera`, default false) that appends `camera; microphone` to `IFRAME_ALLOW`, in all 5 copies of the constant. Default embeds keep the current permission surface. The standalone `/public` UI works without it. |
| CKEditor/TinyMCE/Summernote, node/python SDKs, MCP | none | They inherit the iframe/SDK behaviour; tokens use the `claims` map. |

## 10. Security

**Guarantee mapping.** All rows except "main JWT never in a URL" and "SSRF" are inherited
because the route ends in `FileManager::upload()` with the main JWT's Claims.

| Guarantee | How |
|---|---|
| disk / prefix / path scope | disk and path come only from the sealed ticket, which was scope-checked at mint; `upload()` re-scopes |
| perms | `write` checked at mint; Claims re-derived from the main JWT at upload |
| `owner_only` | `upload()` sets the owner = `sub` and refuses a collision onto another user's file |
| `max_upload`, `allowed_ext`, dangerous names | `upload()`, plus Uppy `restrictions` (UX), plus Companion `maxFileSize` |
| quota / `max_files` | `upload()` |
| virus/DLP **fail-closed** | `upload()` scans before writing; module absent, unlicensed or engine down means the write is refused. Works while scanning is on, unlike `/chunk` and presign |
| legal hold | `upload()` refuses an overwrite of a held key (`403 legal_hold_active`, audited `legal_hold_blocked`) |
| `strip_exif`, variants, posters, AI tag | `upload()` |
| audit + refused-write audit + webhooks | the shared `index.php` tail; action `companion_import` |
| rate limits | the `companion` bucket per user; ticket mint on the write bucket |
| main JWT never in a URL | the ticket travels in a header and a POST body only, and the main JWT is AES-GCM-sealed inside it |
| SSRF | FluxFiles fetches nothing. Companion's `uploadUrls` (mandatory in prod) bounds where it pushes; `allowLocalUrls=false` bounds what its Url plugin pulls |
| content | the import-url MIME deny-list and SVG gate apply to every Companion-landed file |

**Review checklist (to tick in the PR):**
- [ ] `CompanionTicket::verify` reads only the header, never `$_GET`/`$_POST`.
- [ ] A ticket on `/api/fm/list` gets 403 `token_not_access`. A main JWT on
      `/companion/upload` gets 403 `companion_ticket_invalid`.
- [ ] Ticket `exp` ≤ main `exp`. An expired main JWT inside a live ticket is refused.
- [ ] Form fields `disk`, `path` and `force_upload` on `/companion/upload` are ignored (test
      with a hostile value).
- [ ] Turning the `companion_url` claim off makes an outstanding ticket fail with 403
      `companion_disabled`.
- [ ] `AuditPolicy::ACTIONS` order: the companion entries sit above `/upload`. The CI guard
      sees no `unknown`.
- [ ] Audit and webhook disk/key come from the result record.
- [ ] `companion_url` with `javascript:`, `data:`, userinfo, query, fragment, or over 2048
      bytes is dropped. The env origin allow-list is enforced. `companionAllowedHosts` is
      regex-escaped.
- [ ] The provider token store is per-user (`sessionStorage`/memory), not shared
      `localStorage`.
- [ ] The vendored bundle's hash is recorded in THIRD-PARTY-NOTICES. No runtime CDN
      reference anywhere (grep `transloadit.com` in `assets/`).
- [ ] Ticket size guard (7,680 B) is in place, with a test using a fat BYOB JWT.
- [ ] Operator doc: `COMPANION_UPLOAD_URLS` = exactly the FluxFiles upload URL;
      `COMPANION_CLIENT_ORIGINS` = the UI origin (never `true`);
      `COMPANION_ALLOW_LOCAL_URLS=false`; no debug logging of request bodies or headers (it
      would log the ticket).

**Residual risks (accepted, documented):**
- The operator's Companion sees the ticket and the success `responseText`, which may
  include the file's URL. It is the operator's own service.
- Tickets can be replayed within their TTL.
- `companion_sources` is not enforced server-side.
- The audit IP of the request is Companion's; the mint-time IP is in `detail.ticket_ip`.
- Each in-flight import holds a PHP worker for the transfer's duration. Rate limit plus
  Uppy `limit: 3`; the operator sizes FPM.

**New error codes (i18n ×16):** `companion_disabled` (403), `companion_ticket_invalid`
(403), `companion_ticket_too_large` (413), and `companion_unreachable` (UI only). Everything
else reuses existing pipeline codes (`no_file`, `upload_too_large`, `ext_denied`,
`quota_exceeded`, `virus_detected`, `pii_detected`, `legal_hold_active`, `content_denied`,
rate-limit 429, …).

## 11. Package layout (all free/core, no private package)

**Core, new:**
- `api/CompanionTicket.php`
- `api/CompanionUpload.php` (`ticket()` + `handle()`, shared with the adapters)
- `api/RemoteContentGuard.php` (extracted from `UrlImporter`)
- `assets/vendor/uppy/**`
- `.claude/rules/companion.md` (paths: the three new classes)

**Core, edited:**
- `api/Claims.php`: `companionUrl`, `companionSources`, `companionRateLimit`, plus
  `sanitizeCompanionUrl`/`sanitizeCompanionSources`.
- `api/index.php`: the pre-auth ticket branch, the ticket route, and the rate bucket.
- `api/AuditPolicy.php`
- `api/UrlImporter.php`: delegate to the guard.
- `embed.php`
- `assets/fm.js`, `fm.css`, `public/index.html`, and the rebuilt `*.min.*`.
- `lang/*.json` ×16
- `THIRD-PARTY-NOTICES.md`
- The core CORS `Access-Control-Allow-Headers` list (add `X-FluxFiles-Ticket` for
  cross-origin SDK clients).

**Adapters:**
- `packages/laravel/{routes/fluxfiles.php, src/FluxFilesServiceProvider.php, src/Http/Controllers/FluxFilesController.php, resources/fluxfiles.js}`
- `packages/wordpress/{includes/FluxFilesApi.php, assets/fluxfiles.js}`
- `packages/{sdk/fluxfiles.js, react/src/iframe.ts, vue/src/iframe.ts}` (camera opt-in; bump
  the react/vue/sdk `package.json`).

## 12. Test plan

**Unit** (`packages/core/tests/unit/`):
- `test-companion-ticket.php`:
  - mint/verify round trip;
  - wrong `t`, tampered signature, wrong secret, expired, undecryptable `enc` all fail;
  - TTL clamp and `exp` ≤ main `exp`;
  - the readable payload holds no disk, path or JWT;
  - the ticket is refused by `JwtMiddleware::handle`;
  - a main JWT is refused by `verify`;
  - the size guard trips.
- `test-tenant-config.php` additions:
  - `companion_url` accept and drop matrix (scheme, userinfo, query, fragment, length,
    trailing slash);
  - the `FLUXFILES_COMPANION_ORIGINS` filter;
  - `companion_sources` default, filter, dedupe and empty;
  - `companion_rate_limit` clamp;
  - `embed.php` forwarding.
- `test-remote-content-guard.php`: the deny-list and SVG gate. Existing UrlImporter tests
  stay green.
- Existing guards must pass: `test-config-doc.php` (CONFIG.md rows), the audit-action CI
  guard (no `unknown`), and locale key parity ×16.

**Integration** (`tests/integration/test-companion-upload.php`, in-process, a
`FileManager` with a stub scanner):
- The file lands at the ticket's disk/path; hostile form `disk`/`path` are ignored.
- `import_path` is forced.
- `max_upload`, `allowed_ext`, `max_storage`/`max_files` are enforced.
- `owner_only` collision gives 403.
- An overwrite of a held key gives `legal_hold_active`.
- Scanner engine down gives the write refused (fail-closed). Infected gives
  `virus_detected` plus a `virus_blocked` audit entry.
- DLP hit gives `pii_detected`.
- `strip_exif` applies.
- The content deny-list applies.
- Claim revoked after mint gives `companion_disabled`.
- Expired main JWT inside a live ticket is refused.
- Audit action `companion_import` with `ticket_ip`; the webhook event is captured.

**Self-booting e2e** (`tests/e2e/test-companion-http.php`, picked up by the `selfboot-e2e`
job; **bind an ephemeral port** like `test-write-policy-http.php`):
- Boots core under `php -S`, plus a **fake Companion**
  (`tests/e2e/fixtures/fake-companion.php` on a second `php -S`). The fake implements
  `POST /fake/get/{id}` with Uppy's body shape, does the multipart POST to `endpoint` with
  the forwarded `headers`, and returns `{status, responseText}`. It **logs every request
  line and header it receives and sends**.
- Asserts:
  - the import lands under prefix/path;
  - the `{data:record}` envelope comes back;
  - error codes come back for oversize and denied extension;
  - the **main JWT string never appears in the fake's log or in any URL**;
  - a `?ticket=` query is ignored (403);
  - a ticket on `/api/fm/list` gives 403 `token_not_access`;
  - an expired ticket gives 403;
  - the companion bucket returns 429 after N;
  - the CSRF check passes with no `Origin`.

**Optional real-Companion CI job** (env-gated `test-companion-live.php`):
- A `transloadit/companion` container using the **Url provider**, which needs no OAuth.
  `COMPANION_ALLOW_LOCAL_URLS=true` in CI only, with `COMPANION_UPLOAD_URLS` set to the core
  URL. It fetches a fixture file served locally and asserts it lands, proving the real
  wire format against the current Companion.

**Browser** (`tests/browser/companion.spec.ts`):
- The entry is hidden without the claim, without `write`, or with empty sources.
- The entry is visible with the claim.
- `vendor/uppy` is **not** fetched at boot; it is fetched on open.
- The ticket POST carries `Authorization`, and no request URL contains the JWT.
- The Dashboard mounts in the active locale.
- `upload-error` maps `error_code` to the translated text (Companion stubbed via
  `page.route`).

**Adapters:** the Laravel and WordPress smokes cover both routes (the public one has no
auth middleware, and the ticket is verified), plus the proxy-parity list and the
`IFRAME_ALLOW` opt-in (vitest in sdk/react/vue). The core-floor guard runs automatically.

## 13. Docs to update

- `docs/reference/CONFIG.md`: the three claims (§16) and the three env vars.
- `.claude/api-map.md`: both routes. Add `t=companion` to the "Token types" list, and note
  that both routes are proxied.
- `.claude/CLAUDE.md` + `AGENTS.md`: add `companion_url` to the BYO-embed list.
- `.claude/rules/companion.md`
- `THIRD-PARTY-NOTICES.md`
- `CHANGELOG.md` (with a `> Released:` line).
- A short operator guide, `docs/guides/CLOUD-IMPORT.md`: the Companion env block below,
  per-provider OAuth redirect URIs (`https://<companion>/<provider>/redirect`), the CSP
  snippet, and the Google verification caveat.

```env
# Companion (operator-hosted, ≥ 7, Node ≥ 22)
NODE_ENV=production
COMPANION_SECRET=<random>                     # signs Companion's own tokens (not FLUXFILES_SECRET)
COMPANION_DOMAIN=companion.acme.com
COMPANION_PROTOCOL=https
COMPANION_DATADIR=/var/lib/companion
COMPANION_CLIENT_ORIGINS=https://files.acme.com          # origin serving the FluxFiles UI (core or the WP/Laravel host)
COMPANION_UPLOAD_URLS=https://files.acme.com/api/fm/companion/upload
COMPANION_ALLOW_LOCAL_URLS=false
COMPANION_MAX_FILE_SIZE=104857600                        # keep ≤ the tenants' max_upload / PHP limits
COMPANION_GOOGLE_KEY=… COMPANION_GOOGLE_SECRET=…         # and DROPBOX / ONEDRIVE / BOX
# multiple Companion instances → COMPANION_REDIS_URL (Companion's state, not FluxFiles')
```

## 14. What it does NOT do

- FluxFiles does **not** host, bundle or proxy Companion. It never stores or sees provider
  OAuth tokens or keys, and it never calls Google, Dropbox, Microsoft or Box.
- It does **not** sync, mirror or watch remote drives. Each import is a one-shot copy. There
  is no "link to Drive file", no two-way sync, and no export *to* the clouds.
- It does **not** resume a Companion→FluxFiles transfer, and it does not exceed PHP's
  upload limits. Large-file tus is a possible v2 (§15 Q3).
- It does **not** replace FluxFiles' own local upload (tus/chunk/plain) or `/import-url`.
  The Companion `url` tab is optional and off by default.
- It does **not** use `s3-multipart` (Companion → bucket directly). Those bytes would bypass
  the scan, quota and audit, which is the same unscannable side door `/chunk` refuses.
- It enforces **no** per-provider allow-list server-side (`companion_sources` is UX), and it
  adds no single-use ticket enforcement (stateless).
- It is **not** a paid module, and it adds **no** DB, queue or background job.
- It does **not** grant camera access by default in embeds; that is the adapter opt-in.

## 15. Open questions / trade-offs

1. **Google Drive: classic plugin or Picker?** v1 = the classic plugin; OAuth goes through
   Companion and the operator handles Google verification. "Internal" Workspace apps need
   no verification. The Picker avoids verification but loads Google scripts in the page and
   needs browser-side `client_id`/`api_key`/`app_id`, which would be three more public
   claims plus CSP additions. Ship the Picker in v1.1 if operators hit the verification wall?
2. **Content deny-list on cloud picks.** Treating every Companion file as remote blocks a
   user's own `.html`/`.js` from Drive, which `/upload` would accept. That is safer, since
   FluxFiles can't tell a Drive file from a Companion `url` fetch, but stricter than local
   upload. Keep it, or relax it to `allowed_ext` only?
3. **Large files.** (B) inherits PHP's `upload_max_filesize`/`post_max_size`. Alternatives:
   - a raw-body `PUT` variant (`useFormData:false`) streamed from `php://input`, which
     avoids `upload_max_filesize` but WP REST buffers the body in memory;
   - core-only tus with the ticket accepted on `/tus`.
   Defer until someone asks?
4. **Size cap claim.** v1 uses `max_upload` only and ignores `max_import_mb`, whose default
   of 50 MB would surprise Drive users. A dedicated `companion_max_mb` would be clearer, but
   it is one more claim.
5. **Audit/webhook naming.** A new `companion_import` action (like `url_import`) means
   webhook consumers filtering on `upload` miss these. Alternatively emit `upload` with
   `detail.via`. Pick one before release; it is a public event name.
6. **Webcam coupling.** Webcam doesn't need Companion. Should it get its own claim
   (`allow_webcam`) so operators without Companion can offer it, using the same
   ticket/endpoint?
7. **Shared-secret hardening.** Optionally require a static `X-Companion-Key` (set via
   Companion `uploadHeaders`, checked against a FluxFiles env var) so that only the
   operator's Companion, and not any ticket holder, can call `/companion/upload`. That
   would force webcam onto the main upload path. Not in v1.
8. **Ticket TTL.** 900 s max with refresh every 300 s while the dialog is open. Shorter is
   safer, but a picked file whose transfer *starts* after expiry fails. Companion sends the
   header when the transfer starts, not at pick time.

## 16. Effort estimate (one engineer familiar with the codebase)

| Slice | Days |
|---|---|
| Core backend: `CompanionTicket`, `CompanionUpload`, `RemoteContentGuard` extraction, Claims, the `index.php` branch, audit, rate | 2–2.5 |
| UI: entry, modal, lazy loader, Uppy config, ticket refresh, error mapping, vendoring + notices, CSS, i18n ×16, minify | 2.5–3 |
| Adapters: Laravel and WP routes + smokes, `IFRAME_ALLOW` opt-in ×5, floor bump | 1.5–2 |
| Tests: unit, integration, fake-Companion e2e, Playwright, optional real-Companion job | 2–3 |
| Docs: CONFIG, api-map, rules, operator guide, CHANGELOG | 0.5–1 |
| **Total** | **≈ 8.5–11.5 days** |

---

### Claims to add to `docs/reference/CONFIG.md`

| Claim | Type | Default | Notes |
|---|---|---|---|
| `companion_url` | string (http/s) | `""` | Base URL of the operator's self-hosted Uppy Companion. Enables the "From cloud" import dialog (Google Drive/Dropbox/OneDrive/Box, optional URL/webcam). http(s) only; userinfo/query/fragment or > 2048 bytes dropped; must match `FLUXFILES_COMPANION_ORIGINS` when that env var is set. Empty → no entry, `/api/fm/companion/*` → 403 `companion_disabled`. Free BYO-embed. |
| `companion_sources` | string[] | `["google_drive","dropbox","onedrive","box"]` | Dashboard tabs; subset of `google_drive`,`dropbox`,`onedrive`,`box`,`url`,`webcam` (unknown dropped; empty → no entry). UX only: restrict providers in Companion's own config. `webcam` needs the embed's camera opt-in. |
| `companion_rate_limit` | int (/min) | `0` | Imports per minute per user on `/api/fm/companion/upload`. `0` = inherit `FLUXFILES_COMPANION_RATE_LIMIT` (default 30). |

Env vars: `FLUXFILES_COMPANION_ORIGINS` (optional origin allow-list for `companion_url`),
`FLUXFILES_COMPANION_RATE_LIMIT` (default 30), `FLUXFILES_COMPANION_UPLOAD_URL` (optional
absolute destination override for split-horizon networks).
