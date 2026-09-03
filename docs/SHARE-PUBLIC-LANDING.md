# Spec — Share module: the public landing page

Status: implemented and shipped. Source plan: `.claude/work/plan-share-public-landing.md`
(its decisions are settled input). Mirrors the shipped Intake precedent. See
`packages/core/api/ShareGrant.php`, `PublicLinks.php`, `packages/core/public/share.html`,
and the Share routes in `.claude/api-map.md` for the current implementation.

## 0. Deviations from the plan (flagged once, not re-litigated)

I agree with every settled decision (core shell + paid enforcement, brand-neutral HTML,
302-after-enforcement on S3/R2, static English page, accepted read-modify-write races).
Three refinements *within* that scope, called out so they aren't mistaken for quiet redesign:

1. **The password never rides a query string.** `POST /share/unlock` exchanges the password
   for a short-TTL **grant token** (`ShareGrant`, exactly the `StreamToken`/`ImageToken`
   pattern), and the bytes GET carries the grant. Without this, a password-protected link
   puts token *and* password in the same access-log line, which defeats the password.
2. **A password-protected share reveals nothing before unlock** — not even the filename or
   size. The plan said "no password needed to see the card"; that is right for Intake (a
   label is the whole point) and wrong for Share (the filename is often the secret).
   `/share/info` returns `{has_password:true, expires, brand}` only; the card comes back
   from `/share/unlock`.
3. **Inline PDF preview only on uncapped shares.** An `<iframe>` of the real PDF bytes *is*
   a download and would silently eat `max_downloads`. Images preview through the existing
   `/api/fm/img` (a bounded transform, never the original bytes, never counted). PDFs
   preview only when `max_downloads == 0`; capped PDF shares show the card + a button.

---

## 1. Problem & who pays

An operator can mint a share (`POST /api/fm/share`) and receives `{token, jti, expires}` —
and there is no URL a recipient can open. `resolveShare()` has exactly one caller in the
repo: its own test. Share is the hero SKU that anchors Pro and it is currently unusable.

- **Persona A (agency / freelancer / WP implementer)** — "send the client the deliverable
  from my own storage, with an expiry and a password, without paying per seat." WeTransfer's
  current individual plans are $6.99–$25/mo, single-user, with 3-day link expiry below the
  top tier ([WeTransfer pricing 2026](https://goodsign.io/blog/wetransfer-pricing)).
- **Persona B (SaaS operator)** — resells a tenant-scoped share page to their end users.

**Free vs paid: unchanged.** The landing shell + public route plumbing ship in the MIT core
(free); every enforcement decision (password, expiry, cap, revocation, the storage-resident
record) stays in the gitignored `packages/share/`, reached through `ModuleRegistry`. Free
core → `501 module_not_installed` and the page says the link isn't available, exactly as
`intake.html` does today.

**On the BYO-embed rule:** good free OSS does exist for standalone send-a-file
(Erugo, PlikShare, Nextcloud link shares). It is not a drop-in here — those are separate
apps with their own storage and account model; embedding one would mean *copying* the file
out of the operator's disk into a second system. Share is the consumption half of a token
FluxFiles already mints against the operator's existing storage. Build, don't embed.

## 2. Architecture fit

Nothing new is stateful. The whole feature is: a token the recipient holds, a JSON record
that already exists in the operator's storage, and a page that reads one and writes the other.

| Concern | Where it lives |
|---|---|
| Who may open this link, for which file, until when | the **share token** (`prefix` = the exact object, `store` = the tenant prefix, `exp`) |
| Password hash, download cap, counters, label | `_fluxfiles/shares.json` under `store`, in the operator's own storage |
| Proof that the password was entered | a **grant token** (HMAC, jti-bound, 10-minute TTL) — held by the browser, never stored |
| Presigned-URL lifetime, preview policy, link base | baked into the record at create time from **operator claims** |

**Defect 1 fix — the token becomes self-contained.** `createShare` adds
`'store' => $claims->pathPrefix` to the JWT payload (identical to
`IntakeModule.php:84`), and `resolveShare` **drops its `string $prefix` parameter** and
reads `store` from the signed payload. A public request has no main JWT, so a caller-supplied
prefix is unsatisfiable by construction; removing the parameter removes the bug class. Shares
minted before this change stop resolving — acceptable, nothing consumes them today.

**Defect 2 fix — bytes are never a redirect to a static local URL.** The bytes route
dispatches on the *disk driver*, not on the file's `url`:

| Driver | Behaviour |
|---|---|
| `s3` (covers S3 **and** R2 — both use `driver: 's3'`) | enforce → increment → `302` to `DiskManager::presignGetUrl($disk, $path, $url_ttl, $disposition)` |
| `local` (`private: true`) | stream through the app (`RangeStreamer`, incl. the `X-Accel-Redirect` fast path) |
| `local` (public, statically served) | **also stream through the app.** Never emit the static `url`. |
| `sftp` | stream through the app via `readStream` |

> **Documented limitation (do not oversell).** On S3/R2 `max_downloads` counts **grants, not
> downloads**: a handed-out presigned URL stays fetchable until it expires. `share_url_ttl`
> defaults to **60s** to bound this. Operators who need an exact cap must use a local/SFTP
> disk (or accept the window). This sentence belongs in the operator docs verbatim.
>
> **Residual on a public local disk:** the share never discloses the storage path (info
> omits it, bytes never redirect to it), but the object's static URL still exists for anyone
> who already knows it. Mitigation: `FLUXFILES_LOCAL_PRIVATE=true`, or don't serve the disk
> root statically. Same posture as gated media.

## 3. JWT claims (operator token, read at create time)

All three are read in `createShare` and **baked into the record**, so a public request needs
no claims. Add to `docs/CONFIG.md` §2.13.

| Claim | Type | Default | Validation |
|---|---|---|---|
| `share_url_ttl` | int (seconds) | `60` | clamp `[10, 300]`; `0`/absent → default. TTL of the presigned S3/R2 URL the bytes route redirects to. Bounds the "grants ≠ downloads" gap. |
| `share_base_url` | string (http/s) | `''` | http(s) only, validated in `Claims` exactly like `terminal_pty_url`; anything else dropped to `''`. Base the create response builds the recipient URL from; empty → the request origin + `/public/share.html`. The seam for the paid Share+ custom-domain item. |
| `share_preview` | bool | `true` | `false` → the info/unlock response never emits `preview_url`; the landing shows a download-only card. |

Not claims (deliberately): `label` and `max_downloads` are per-share **body** fields;
brute-force limits are server-wide **env** vars (precedent: `FLUXFILES_RATE_LIMIT_*`).

New env vars (`docs/CONFIG.md` §3):

| Env | Default | Notes |
|---|---|---|
| `FLUXFILES_SHARE_RATE_LIMIT` | `60` | Requests/min per `jti` across `share/info` + `share/file`. |
| `FLUXFILES_SHARE_UNLOCK_LIMIT` | `5` | Password attempts/min per `jti` + client IP. |
| `FLUXFILES_SHARE_UNLOCK_TOTAL` | `30` | Password attempts/min per `jti`, no IP component — closes the gap an attacker rotating `REMOTE_ADDR` would otherwise walk through the per-IP bucket above. See §6. |

## 4. Endpoints

Envelope everywhere: `{data, error, error_code?, error_params?}`. Public routes are
registered **before the auth block**, next to Intake's (`index.php:211`), and gated on
installed + licensed only — **no claim check**, because a public request carries no main JWT.
Module absent → `501 module_not_installed`; unlicensed → `402 license_required` /
`license_expired`.

### Public (token-authed, pre-auth)

**`GET /api/fm/share/info?token=<share-jwt>`**
Counts one **view**. Never returns the storage path or the disk name.

```jsonc
{"data": {
  "label": "Q3 report",              // operator-set, sanitized; "" if unset
  "expires": 1750600000,
  "has_password": false,
  "downloads": 2, "max_downloads": 5, "remaining": 3,   // remaining null = unlimited
  "file":  {"name":"q3.pdf","size":184320,"mime":"application/pdf","kind":"pdf",
            "preview_url": null},
  "files": [ { /* same object */ } ],  // forward-compatible: clients read `files`
  "brand": null                        // reserved for Share+ (paid); core renders nothing
}}
```
When `has_password` is true the response is only
`{"has_password":true,"expires":…,"brand":null}` — no name, size, mime, counters, preview.

**`POST /api/fm/share/unlock` `{token, password}`**
Verifies the password through the module (no counter change, so brute force can't inflate
`views`). Returns the full info payload **plus** `{"grant":"<jwt>","grant_expires":…}`.
Same-origin `Origin` CSRF check applies (it runs at `index.php:67`, before this block) —
share.html is served from the FluxFiles origin, so it passes; a custom `share_base_url`
host must be listed in `FLUXFILES_ALLOWED_ORIGINS`.

**`GET /api/fm/share/file?token=<share-jwt>[&g=<grant>][&dl=1]`**
Order is fixed: gate → rate limit → module `resolveShare(..., isDownload: true, grant: $g)`
(password/expiry/cap enforced, `downloads` incremented) → **then** bytes or `302`. A
password-protected share without a valid `g` → `401 share_password`; a stale grant →
`403 share_grant_invalid` (the landing re-prompts).
`dl=1` → `attachment`. Otherwise `inline` only for the same safe MIME set as
`handleMediaStream` (`^(video/|audio/|image/(?!svg))|^application/pdf$`); everything else is
forced to `attachment`. Emits raw bytes / a redirect, not JSON.

Response headers: `Content-Disposition` (RFC-5987 filename from `basename`, never the key),
`X-Content-Type-Options: nosniff`, `Content-Security-Policy: sandbox`,
`Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`. MIME by **extension**
(`ExtensionMimeTypeDetector`), never sniffed.

Image preview: when the file is an image (not SVG) and `preview` is enabled on the record,
core mints an `ImageToken` (`sub = 'share:'.$jti`, TTL = grant TTL, `mw` = 1600) and returns
`preview_url = /api/fm/img?token=…&width=1200`. The `/img` route is already public and
self-contained; it yields a bounded transform, never the original bytes, and **never**
touches the download counter. The token is minted **only after** password verification (i.e.
in the unlock response for protected shares) — otherwise a protected image previews without
the password.

**Client-side download mechanism (`share.html`, not a route — documented here because it's
the least obvious piece of the landing page).** The bytes route is stateful (every hit
increments `downloads`, and on S3/R2 that hit's response body IS a cross-origin `302`), so it
can only ever be triggered **once per click**: a separate "check" request would burn a second
download for one click, and a plain `fetch()` that follows the redirect would need S3 to
allow the FluxFiles origin in its CORS policy, which isn't guaranteed. The click handler
therefore navigates a **hidden `<iframe>`** to the bytes URL instead of using `fetch`:

- Our own error responses are same-origin JSON with no attachment header, so they land in the
  iframe's `contentDocument`, readable by the parent page — those get parsed and shown inline
  (`#dlerr`), with two codes special-cased: `share_grant_invalid` re-locks the card (the
  recipient re-enters the password) and `share_exhausted` permanently disables/relabels the
  Download button, since neither can succeed on retry.
- A real download — local/SFTP bytes, or the followed S3/R2 redirect landing on a different
  origin — either doesn't render into the frame or throws on `contentDocument` access; both
  are treated as **success** (the browser's own download UI has taken over; there's nothing
  left to report).
- A 6-second timeout also counts as success if nothing readable showed up by then. This means
  a genuinely slow-but-eventually-successful redirect can get silently treated as done before
  it actually resolves — the real download still proceeds via the browser, this only affects
  whether the button re-enables promptly; accepted trade-off, not a bug to "fix" with a longer
  timeout (which would just make network errors sit unreported for longer instead).

### Operator (main JWT, 3-layer gate incl. `allow_share`)

- **`POST /api/fm/share`** (exists) — body gains `label?`; response gains
  `url` (built from `share_base_url` or the request origin) and `has_password`. The **token
  is returned once and never stored**; a listed share cannot be re-linked, only revoked
  (same posture as an API key). State this in the operator docs.
- **`GET /api/fm/share/list?disk=local`** — NEW route over the existing `listShares`.
  Records minus `password_hash`; under `owner_only`, filtered to `owner === userId`.
- **`POST /api/fm/share/revoke` `{disk, jti}`** — NEW route over the existing `revokeShare`.
  Under `owner_only`, refuses a jti owned by someone else (`403 perm_denied`). Revocation is
  the only kill switch for a link that is now genuinely public — shipping the landing
  without it is not acceptable.

## 5. Storage layout

`<store>/_fluxfiles/shares.json`, one map per tenant prefix (unchanged location; new fields
are additive):

```jsonc
{
  "9f2c…": {
    "jti": "9f2c…", "disk": "local",
    "path": "users/42/reports/q3.pdf",   // full storage key, never returned publicly
    "store": "users/42/",                // NEW — where this file lives; mirrors the token
    "owner": "u42",                      // NEW — for owner_only list/revoke filtering
    "label": "Q3 report",                // NEW — sanitized like IntakeModule::cleanLabel
    "created": 1750000000, "expires": 1750600000,
    "max_downloads": 5, "downloads": 2, "views": 9,
    "url_ttl": 60,                       // NEW — from share_url_ttl at create time
    "preview": true,                     // NEW — from share_preview at create time
    "password_hash": "$2y$…"             // never leaves the server
  }
}
```

Read-modify-write, no lock server: a concurrent hit can lose a view or slip one download past
the cap. Accepted, identical to Intake. Do not "fix" with a queue or DB.

**Revocation is a tombstone, not an `unset`.** Every landing view rewrites the whole tenant
map, so a view that interleaved with a revoke would write the pre-revoke map back and
*resurrect* the link — losing a view count is cosmetic, silently undoing the only kill switch
is not. `revokeShare` therefore writes `{jti, revoked:true, expires}` in place; `resolveToken`
treats it as `share_revoked` (checked before expiry), `listShares` skips it, `putRecord`
refuses to write over it, and `save()` prunes tombstones past `expires` (by then the token is
unusable anyway).

## 6. Security

- **No SSRF surface.** Nothing here makes an outbound request. (`share_base_url` is only
  string-concatenated into a response, never fetched — validate http(s) anyway so it can't
  become a `javascript:` link in someone's UI.)
- **Path scoping.** `path` is read from the *signed* record, never from the query string.
  The local branch re-runs the `handleMediaStream` containment check (`realpath` inside the
  disk root, reject `..`/NUL) before any read.
- **Info leakage.** The info/unlock **response body** carries `name`, `size`, `mime` only —
  no field is ever `path`, `disk`, `store`, `owner` or `password_hash`. It does *not* follow
  that the object key is secret: the share token must be self-contained (that is the fix for
  defect 1), so it is a signed but **readable** JWT carrying `prefix`/`store`, and the
  preview `ImageToken` likewise carries `disk` + `path`. A recipient can therefore decode the
  key of the one file they are already authorised to read; what stays hidden is the rest of
  the tenant's layout. Do not promise operators more than that. Protected shares reveal
  nothing pre-unlock.
- **The share token is not an access token.** It carries `t = share` (the `StreamToken`/
  `ImageToken` pattern) and `JwtMiddleware::handle` refuses **any** typed token on the main
  API with `403 token_not_access`. Without that, the recipient could replay the share token
  as `Authorization: Bearer` on `/api/fm/content`, `/zip` or `/presign` and read the file
  with no password, no cap and — because revoking only deletes the storage record — no
  working kill switch until the JWT expired. The intake portal token (`t = intake`) is
  refused the same way. Belt and braces, the minted share payload also sets
  `allow_download`/`allow_zip`/`allow_code_edit` to false.
- **Password brute force.** Two buckets on unlock, because the per-IP one alone is
  escapable: `FLUXFILES_SHARE_UNLOCK_LIMIT` (5/min per jti+IP — `REMOTE_ADDR` is free for an
  attacker to rotate) **and** `FLUXFILES_SHARE_UNLOCK_TOTAL` (30/min per jti, no
  attacker-controlled component; roomy enough that a shared-office NAT isn't locked out).
  `FLUXFILES_SHARE_RATE_LIMIT` (60/min per jti) covers info+file. All via the existing
  `RateLimiterFileStorage` JSON file; failures reuse the existing `rate_limited` (429) code —
  no new i18n key. `password_verify` is already constant-time. A failed unlock bumps
  `unlock_fails` on the record (never `views`), so an attacked share is visible in
  `share/list`.
- **Grant token.** `ShareGrant` = tiny HS256 JWT over `FLUXFILES_SECRET`,
  `{t:'share_grant', jti, exp}`, TTL 600s (hard cap 3600). Distinct `t` from `stream`/`img`,
  so none of the three is usable on another's endpoint; bound to one `jti`, so it can't be
  moved between shares. Verified **inside the module**, so enforcement stays paid — core only
  carries an opaque string.
- **`owner_only`** applies to `share/list` + `share/revoke` (see §4). It cannot apply to the
  public routes; owner identity is fixed at create time.
- **Active content.** `nosniff` + `CSP: sandbox` + attachment-forcing for anything outside
  the media/PDF allowlist, so a shared `.html`/`.svg` can't run in the FluxFiles origin.
- **Referrer.** `share.html` carries `<meta name="referrer" content="no-referrer">` and the
  bytes route sends `Referrer-Policy: no-referrer`, so the token can't leak to a preview
  sub-resource or an outbound link.
- **Size/rate caps.** No new size cap (the file is whatever the operator shared);
  `share_url_ttl` caps redirect reuse; the `/img` preview inherits `/img`'s existing width
  rounding + clamp, so a public visitor can't spawn unbounded variants.

### Error codes — each needs `error.<code>` in **all 16** `packages/core/lang/*.json`

There are currently **zero** `share_*` keys (Intake has four). Four codes below already exist
in module code with no translation; four are new.

| Code | HTTP | When | Status |
|---|---|---|---|
| `share_invalid` | 403 | bad signature / not a share token / missing scope | exists in code |
| `share_expired` | 410 | JWT `ExpiredException` — caught separately so the recipient is told to ask for a new link | NEW |
| `share_revoked` | 404 | no record for this jti (revoked or store wiped) | exists in code |
| `share_password` | 401 | password required or wrong | exists in code |
| `share_grant_invalid` | 403 | grant missing/expired/for another jti | NEW |
| `share_exhausted` | 410 | download cap reached | exists in code |
| `share_gone` | 404 | record valid but the underlying object no longer exists | NEW |
| `share_unavailable` | 502 | presign failed / read error | NEW |

Reused, already translated: `module_not_installed` (501), `license_required` /
`license_expired` (402), `rate_limited` (429), `perm_denied` (403), `server_error` (500).

## 7. Package layout

**Free / MIT core** (`packages/core/`)

| File | Change |
|---|---|
| `api/ShareGrant.php` | **NEW** — mint/verify, `t=share_grant`, modelled on `StreamToken.php` |
| `api/index.php` | **NEW** public block before auth (`/share/info`, `/share/unlock`, `/share/file` → `handleSharePublic()`); **NEW** `handleSharePublic()` next to `handleIntakePublic()`; 2 operator routes in `routeRequest` |
| `api/DiskManager.php` | `presignGetUrl()` gains an optional `?string $disposition` → `ResponseContentDisposition` on the presigned command (so an S3 download lands with the right filename) |
| `api/Claims.php` | 3 new claims + http(s) validation for `share_base_url` |
| `public/share.html` | **NEW** — single file, no build step, static English, dark-mode boot script + `<meta name="referrer" content="no-referrer">`, styled from `intake.html`. States: card, password prompt, expired, revoked, cap-reached, module-absent. **Renders only what `brand` supplies — nothing custom of its own.** |
| `lang/*.json` ×16 | 8 `error.share_*` keys |
| `docs/CONFIG.md`, `.claude/api-map.md`, `CHANGELOG.md` | 3 claims + 2 envs; 5 routes; entry |

No `router.php` or `docker/nginx.conf` change needed — `/public/share.html` is a static file
under the existing root, exactly like `intake.html`. Claims reach the adapters for free via
the `claims` escape hatch; add them to the explicit forwarding lists only where
`terminal_pty_url` already is.

**Private paid module** (`packages/share/`, gitignored)

| File | Change |
|---|---|
| `src/ShareModule.php` | `store` in the token payload + record; **`shareInfo()`** (new, mirrors `portalInfo`); `resolveShare()` signature → `(DiskManager, string $secret, string $token, ?string $password = null, bool $isDownload = false, ?string $grant = null)` — **`$prefix` removed**, returns `{disk,path,name,mime,size,jti,remaining,preview}`; `label`/`url_ttl`/`preview`/`owner` recorded; `share_expired` split out; owner filtering in `listShares`/`revokeShare` |
| `tests/test-share.php` | see §8 |

## 8. Test plan

- **Unit, core** — `tests/unit/test-share-grant.php`: mint/verify round-trip; TTL clamp;
  wrong-type rejection both ways (a `stream`/`img` token is not a grant and vice versa);
  jti binding (a grant for share A rejected on share B); tampered signature.
- **Integration, core** — `tests/integration/test-share-public.php`: with the module absent,
  all three public routes return `501 module_not_installed` in the standard envelope; the
  disposition/MIME matrix (svg + html forced to attachment, mp4/pdf inline); the
  driver-dispatch matrix (`s3` → 302, public local → streamed **not** redirected, private
  local → streamed, sftp → streamed) against a stub `DiskManager`.
- **Module, private** — extend `packages/share/tests/test-share.php`. The existing tests all
  pass `prefix = ''`, which is exactly why defect 1 was invisible: **every new case uses
  `pathPrefix = 'users/42/'`.** Cover create → `shares.json` lands at
  `users/42/_fluxfiles/shares.json`; `shareInfo`/`resolveShare` with **no prefix argument**;
  grant accepted / wrong-jti grant rejected; cap enforced on the second download; expired
  token → `share_expired` not `share_invalid`; `owner_only` filtering of list/revoke.
- **E2E, core** — `tests/e2e/test-share-http.php`, self-booting `php -S` in the style of
  `test-img-http.php` (backs up/restores `.env`, needs `curl`), skipped when
  `packages/share/` is absent: operator mints → `GET info` → `GET file` without grant → 401 →
  `POST unlock` → `GET file` → 200 + `Content-Disposition` → over-cap → 410 → `revoke` → 404.
- **Browser** — `tests/browser/share-landing.spec.ts`: card renders from a live info
  response; wrong password shows the error and doesn't reveal the name; download click;
  expired/revoked terminal states; dark-mode boot doesn't flash. Also asserts the
  module-absent path directly (no mock): the real `501 module_not_installed` renders as a
  terminal state, with exactly one unauthenticated request to `share/info`.
- **Guards** — add `'share/info','share/unlock','share/file','share/list','share/revoke'` to
  `$intentionallyUnproxied` in `packages/laravel/tests/test-laravel-smoke.php:298` (paid
  modules are core-standalone by current policy). **WordPress has no route-parity guard**, so
  there is nothing to whitelist there; the only WP-side fact is that `allow_share` is already
  forwarded (`FluxFilesPlugin.php:334`) and stays that way — no new WP work.
- Existing `tests/unit/test-i18n.php` (16-locale key parity) and
  `tests/unit/test-config-doc.php` (claims ↔ `docs/CONFIG.md`) will fail until §3 and the
  error table are done. That's the intended forcing function.

## 9. Open questions / trade-offs

1. **Preview vs cap (decided above, flag if you disagree).** Images preview free through
   `/img`; PDFs preview only when uncapped; video/other never preview. The alternative —
   "any bytes is a download" — is simpler and more honest but makes a capped image share feel
   broken. A capped share is therefore card-only for PDFs.
2. **Uncapped PDF previews inflate `downloads` by one per visit** (an iframe load really is a
   fetch of the bytes). Analytics noise on uncapped shares only. Acceptable; the alternative
   is a second counter.
3. **`share_base_url` is free core.** It's the mechanism a custom domain would use, but it's
   just a string; the paid Share+ item is the *branded* landing (`brand` payload), which core
   deliberately cannot populate. Confirm that split is where you want the line.
4. **Old tokens break.** Adding `store` invalidates any share minted before this change.
   Nothing consumes them today, so no migration is specified. Confirm.
5. **`resolveShare` loses a parameter.** A breaking change to a private-package method with
   one caller (its own test). The alternative (keep `$prefix` optional) preserves the exact
   bug this spec exists to fix. Recommend the break.
6. **Not designed here, by instruction:** collections/multi-file shares (the `files` array is
   the forward-compatible seam), branding payload, watermarked previews, analytics UI, an
   operator create-UI in `assets/fm.js`, adapter proxying.

---

## Claims to add to `docs/CONFIG.md`

**§2.13 Paid-module gates** (next to `allow_share`):

| Claim | Type | Default | Notes |
|---|---|---|---|
| `share_url_ttl` | int (s) | `60` | Lifetime of the presigned S3/R2 URL a share download redirects to. Clamped `[10, 300]`. On S3/R2 `max_downloads` counts **grants, not downloads** — this value bounds the window. |
| `share_base_url` | string (http/s) | — | Public base the create response builds the recipient link from (e.g. `https://files.acme.com/public/share.html`). Non-http(s) dropped. Empty = the request origin. |
| `share_preview` | bool | `true` | Allow the landing page to render an inline preview (images via `/api/fm/img`; PDFs only on uncapped shares). `false` = download-only. |

**§3 Environment variables:**

| Env | Default | Notes |
|---|---|---|
| `FLUXFILES_SHARE_RATE_LIMIT` | `60` | Public share requests/min per share id (`share/info` + `share/file`). |
| `FLUXFILES_SHARE_UNLOCK_LIMIT` | `5` | Password attempts/min per share id + client IP. |
| `FLUXFILES_SHARE_UNLOCK_TOTAL` | `30` | Password attempts/min per share id, no IP component. |

Sources: [WeTransfer pricing 2026](https://goodsign.io/blog/wetransfer-pricing) ·
[WeTransfer password-protected transfers](https://wetransfer.com/resources/password-protected-file-transfer) ·
[Erugo (MIT, self-hosted shares w/ password + expiry + download cap)](https://erugo.app/) ·
[PlikShare](https://plikshare.com/)
