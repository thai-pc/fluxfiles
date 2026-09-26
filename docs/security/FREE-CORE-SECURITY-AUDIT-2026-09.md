# Free/core security audit — 2026-09-26

Scope: the MIT free/core surface only (`packages/core/`, plus the Laravel and
WordPress proxy adapters and the browser SDK). Gitignored paid modules were out
of scope except where a free/core path reaches into them.

Method: six parallel review agents (path scoping / file ops, frontend + SDK,
auth + token layer, storage + credentials + metadata, exec + outbound requests,
adapters). **Every finding below was independently re-verified by hand** — by
running the code, by a proof-of-concept, or by reading both sides of a
contradiction. Findings an agent reported that did not survive that check are
not listed.

Status legend: `[ ]` open · `[x]` fixed (commit noted).

---

## CRITICAL

### [x] C-1 — Backslash path traversal escapes the tenant prefix and the `_fluxfiles/` guard

**Where:** `packages/core/api/FileManager.php` — `scopedPath()`

`scopedPath()` splits on `/` only and strips `..`/`.` segments. Flysystem's
`WhitespacePathNormalizer::normalizePath()` does `str_replace('\\', '/', $path)`
**before** it pops `..` segments. The two therefore disagree about what a path
means: core sees `a\..\..\b` as one opaque segment and passes it through
untouched; Flysystem then reads it as `a/../../b` and walks up out of the
tenant prefix.

Same mismatch defeats `isReservedSystemPath()`, which matches the literal
string `_fluxfiles/` on the un-normalized path — `_fluxfiles\x` does not match,
but Flysystem resolves it into the reserved directory.

Reproduced with a PoC against the real vendored normalizer.

**Impact:** cross-tenant read/write, and writes into `_fluxfiles/` (metadata,
search index, audit log, trash manifest).

**Fixed** (commit pending, 2026-09-26): both sanitizers now `str_replace('\\',
'/')` before segmenting — `FileManager::scopedPath()` and
`Claims::stripDotSegments()` (the primitive behind `scopePath()`,
`isPathInScope()` and `normalizeKey()`). Note `..` is *dropped*, not resolved
against the parent, so a normalized path can only ever stay deeper in the tree.

Verified after the fix against the real vendored Flysystem normalizer:

| input | scoped | Flysystem re-normalizes to |
|---|---|---|
| `a\..\..\etc\x` | `user_1/a/etc/x` | `user_1/a/etc/x` |
| `..\..\secret.txt` | `user_1/secret.txt` | `user_1/secret.txt` |
| `_fluxfiles\index.json` | `user_1/_fluxfiles/index.json` | blocked by `isReservedSystemPath()` |

`isPathInScope('a\..\..\b')` now returns `false` (rejected, not silently
normalized). Regression locked in `tests/unit/test-claims.php`; full unit +
integration suite green.

---

## HIGH

### [x] H-1 — Legal hold evaded by the same backslash vector
The hold check runs on the un-normalized path, so a held file is reachable
under a backslash spelling. Fixing C-1 closes this; keep it listed so the
regression test covers the hold path explicitly.

### [x] H-2 — SSRF guard bypassed by IPv6-mapped spellings
**Where:** `packages/core/api/SsrfGuard.php:66-72`

Only the literal `::ffff:` prefix with a *dotted-quad* tail is unwrapped.
Everything else falls through to `filter_var(..., NO_PRIV_RANGE|NO_RES_RANGE)`,
which does not reject mapped addresses. Verified by direct execution:

| address | judged public | actually |
|---|---|---|
| `::ffff:7f00:1` | **true** | 127.0.0.1 |
| `0:0:0:0:0:ffff:127.0.0.1` | **true** | 127.0.0.1 |
| `::ffff:a9fe:a9fe` | **true** | 169.254.169.254 (cloud metadata) |
| `::ffff:c0a8:0101` | **true** | 192.168.1.1 |
| `2002:7f00:1::` | **true** | 6to4 |
| `64:ff9b::7f00:1` | **true** | NAT64 |
| `::ffff:127.0.0.1`, `::1` | false | (the only spellings caught) |
| `0:0:0:0:0:0:0:1` | **true** | loopback, expanded |

Reaches BYOB S3 endpoints (where the pinned IP is trusted with **no**
post-connect re-check, by design) and URL import.

**Fixed** (commit pending, 2026-09-26): the IPv6 branch of `isPublicIp()` now
judges the packed 16-byte value from `inet_pton`, never the spelling. `::` and
`::1` are compared as bytes; `::ffff:0:0/96` (mapped), `::a.b.c.d` (compat),
`2002::/16` (6to4, v4 in bytes 2-5) and `64:ff9b::/96` (NAT64, v4 in the last
4) all unwrap to their embedded IPv4 and recurse into the existing v4 rules.
The `NO_PRIV_RANGE|NO_RES_RANGE` filter stays as the final catch-all for
genuine v6.

`test-ssrf-guard.php` grew from 44 to 59 assertions: all 13 spellings in the
table above are now rejected, and a positive test proves public wrappers
(`::ffff:8.8.8.8`, `2002:808:808::`, `64:ff9b::808:808`) still pass.

### [x] H-3 — `FLUXFILES_SSRF_ALLOW_HOSTS` globally disables the rebinding backstop
**Where:** `SsrfGuard.php:293-301`, wired at `packages/core/api/index.php:38-44`

`assertConnectedIpSafe()` returns early whenever `$allowTestHosts` is non-empty
— a **global** bypass, not a per-host one. The property's docblock
(`SsrfGuard.php:26-34`) claims "There is no env / config / request path that
populates this … It is empty in production", which is factually wrong: it is
populated from the documented operator env var `FLUXFILES_SSRF_ALLOW_HOSTS`
(`docs/reference/CONFIG.md`, `.env.example`).

One allowlisted private SFTP host therefore switches off the post-connect check
for every outbound fetch by every tenant, re-opening plain DNS rebinding.

**Fixed** (commit pending, 2026-09-26): `assertConnectedIpSafe($ch, ?array
$allowedIps = null)` now takes an **allowance, not a bypass** — the set the
pre-connect layer already vetted (or waived) for *this* fetch. Any other
address is still judged, so rebinding to a different private IP is caught even
with an allowlist configured. `UrlImporter` passes the per-hop `$safeIps`;
`BucketDoctor` passes its pinned IP only when the URL's host is genuinely
allowlisted (new `SsrfGuard::isAllowlistedHost()`), so a private pinned IP on a
non-allowlisted host is still rejected.

The env allowlist moved to its own `SsrfGuard::$allowHosts` property (populated
from `FLUXFILES_SSRF_ALLOW_HOSTS` in `index.php`), leaving `$allowTestHosts`
for in-process fixtures; both docblocks corrected. Comparison goes through
`canonicalizeIp()` so `::ffff:127.0.0.1` and `127.0.0.1` match — curl reports
whichever family the socket used. `docs/reference/CONFIG.md` now states that
the allowlist waives only the pre-connect requirement.

Tests: `test-ssrf-guard.php` 59/59, `test-url-import-fetch.php` 9/9,
`test-bucket-doctor-ssrf.php` 4/4.

### [x] H-4 — git-deploy hook neutering bypassable via other config-driven exec hooks
**Where:** `packages/core/api/GitDeploy.php:76`

Only `core.hooksPath` is neutered. `git pull`/`fetch` also execute commands
taken from the repo's **own `.git/config`** via `core.fsmonitor` and
`core.sshCommand`. Reproduced locally (git 2.45.2):

```
git -c core.hooksPath=/dev/null status              → payload ran
git -c core.hooksPath=/dev/null -c core.fsmonitor=false status → blocked
```

`.git/config` is an ordinary file under the deploy path, extensionless (so
`assertExt`/`assertSafeFilename` do not stop it), writable by any `write`-scoped
token over that path. Escalates file-write-in-repo to RCE as the SSH user —
the outcome `docs/security/GIT-DEPLOY-SECURITY-REVIEW.md` §4.3 claims is closed
by default.

**Fixed** (commit pending, 2026-09-26): `GitDeploy::buildCommand()` prefixes
every git invocation with `-c core.fsmonitor=false -c core.sshCommand=ssh
-c protocol.ext.allow=never -c protocol.file.allow=never`, **unconditionally**
— the `git_deploy_hooks` claim opts into *hooks*, not into arbitrary
config-driven exec, so turning it on no longer re-opens this.
`docs/security/GIT-DEPLOY-SECURITY-REVIEW.md` §4.3 carries an amendment noting
that hook neutering alone did not close F2. `test-git-deploy.php` 10/10 (a new
test loops both `git_deploy_hooks` states).

### [x] H-5 — `restore()` skips extension immutability
**Where:** `FileManager.php:896-948`

Trash restore resolves its target and calls `assertNotSystem()`, but not
`assertRelocationExt()` / `assertSafeFilename()` / `assertExt()` — unlike
`rename()` (`:1179`), which has an explicit ext-change guard. Gated on the
`delete` permission rather than `write`. A restore can therefore land a `.php`
file on a local disk served by the web server.

**Fixed** (commit pending, 2026-09-26): `restore()` now also asserts the
`write` permission (putting bytes back on the disk is a write, not just an
undo) and runs the same relocation guards as rename/move/copy —
`assertRelocationExt()` for files (ext immutability + `allowedExt` +
`assertSafeFilename`), and `assertSafeFilename()` on the folder name for
directories, which have no extension. The source extension is taken from the
manifest's `original_key`, and both it and the caller-supplied `path` are
re-checked, so a tampered BYOB manifest cannot pick the extension either.
Three regression tests in `tests/integration/test-trash.php` (23/23).

### [ ] H-6 — WordPress Subscribers receive full read+write+delete tokens
**Where:** `packages/wordpress/includes/FluxFilesApi.php:475`,
`FluxFilesPlugin.php:69,280`, `FluxFilesShortcode.php:24`

16 REST routes gate on `is_user_logged_in()` alone. Only `/attach` checks a
capability (`current_user_can('upload_files')`, `:490`). The activation default
grants `['read','write','delete']` with an empty `prefix`, so any Subscriber —
the default role on an open-registration site — gets a full-disk token.

**Fix:** gate on a capability (`upload_files` at minimum) and default the
minted role to the `viewer` preset unless the operator opts up.

### [x] H-7 — `/api/fm/img` serves the clean original to a preview-only token
**Where:** `packages/core/api/index.php:1936` and `:1987`

When `Accept` contains neither `image/avif` nor `image/webp` (`:1899-1905`),
`$format` becomes `''` and the handler returns
`ff_serve_bytes($fs->read($path), $origMime)` — the untouched original.
`curl`'s default `Accept: */*` satisfies that.

Only `$wmEnabled` is checked at both exit points. `allow_download=false`
*without* a watermark is reachable: the implication in `Claims.php:755` is
one-directional (watermark ⇒ `allowDownload=false`, never the reverse), and
`FileManager::imgBaseUrl()` (`:2433`) emits `img_base` based on `webpEnabled` +
stream secret + is-image, never consulting `allowDownload`.

So a preview-only token gets `img_base` from `list()`, and one curl returns the
full-resolution original — bypassing the gate `presign` (`:2187`),
`getContent` (`:2029`) and `streamZip` (`:2518`) all enforce.

**Fixed** (commit pending, 2026-09-26): `ImageToken` carries the claim as
`dl`, stamped only when it is false — an older token without it decodes as
allowed, so nothing in flight changes. `FileManager::imgBaseUrl()` passes
`$this->claims->allowDownload`; `PublicLinks`' share-preview token passes
`false` outright, which its own docblock already promised ("a bounded
transform, never the original bytes").

Both fall-through points in `index.php` now test `$noOriginal = $wmEnabled ||
!$scope['allowDownload']`: the negotiation path forces WebP, and an
untransformable source returns 415 instead of the untouched bytes. The same
two points existed in the Laravel and WordPress `/img` ports and are patched
identically — they mint through core's `FileManager`, so they receive `dl=0`
tokens whether or not the adapter forwards `watermark_enabled`.

Tests: `test-image-transform.php` (mint/verify + backward compatibility) and
two HTTP tests in `tests/e2e/test-img-http.php` (18/18) driving the real
bypass — a bare wildcard `Accept`, and an animated GIF for the
undecodable path.

---

## MEDIUM-HIGH

### [ ] M-1 — CodeMirror loaded from a CDN with no SRI into the JWT-bearing origin
**Where:** `packages/core/assets/fm.js:4345-4374`

~25 `<script>` tags built from
`https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16`, with no
`integrity` and no `crossorigin`. They execute in the origin that holds the
main JWT in memory.

The repo already knows better in both directions: Alpine carries an
`integrity="sha384-…"` hash (`public/index.html:21-22`), and xterm is
deliberately vendored at `assets/vendor/xterm/` with the comment "from
FluxFiles' OWN vendored copy … no third-party CDN" (`fm.js:3889`).

**Fix:** vendor CodeMirror the way xterm already is, or add SRI hashes.

---

## MEDIUM

### [ ] M-2 — Audit-log evasion via one HTTP header
**Where:** `packages/core/api/StorageMetadataHandler.php:572-578`,
`AuditLogStorage.php:24-45`

`audit()` calls `json_encode()` without `JSON_INVALID_UTF8_SUBSTITUTE`.
`AuditLogStorage` pipes `$_SERVER['HTTP_USER_AGENT']` in unfiltered. Reproduced:

```php
json_encode(["ts"=>1,"action"=>"delete","context"=>["ua"=>"A".chr(0xFF)."B"]]) // → false
```

`false . "\n"` writes a blank line, so `User-Agent: A\xFFB` makes any
destructive action unloggable while the operation still succeeds.

**Fix:** pass `JSON_INVALID_UTF8_SUBSTITUTE`, and treat a `false` encode as a
hard error rather than writing the result.

### [ ] M-3 — `/api/fm/audit` missing permission gate and prefix scoping in both proxies
**Where:** `packages/laravel/src/Http/Controllers/FluxFilesController.php:1287-1303`
(and the WordPress counterpart)

Core's route (`index.php:1185-1203`) checks `hasPerm('audit')` and passes
`$claims` so `AuditLogStorage::list()` can scope by prefix. The Laravel proxy
has **neither** — no perm check, and no `$claims` argument. Prefix scoping in
`AuditLogStorage.php:70-76` only applies when a non-empty prefix is passed, so
in proxy mode every tenant reads the whole per-disk audit log.

### [ ] M-4 — `/img` and `/stream` bypass the rate limiter and quota
**Where:** `index.php:206` / `:214`

Both dispatch and `exit` before the `try` block at `:256`, so neither reaches
`RateLimiterFactory` nor `QuotaManager`. The handler docblock justifies this
with "the number of cacheable variants per file is mathematically bounded",
which predates the `height`/`fit`/`format` axes now folded into
`transformCacheKey()` — the real per-file ceiling is ~7k cache writes, into a
`_variants/` the tenant can neither see nor purge.

### [ ] M-5 — No framing headers; `postMessage` falls back to `targetOrigin: '*'`
**Where:** `packages/core/assets/fm.js:549`

`_parentOrigin` is assigned only in the `FM_CONFIG` branch (`:317`); the
`__FM_BOOT__` demo branch returns at `:416` without setting it, so the send at
`:549` degrades to `'*'`. No `X-Frame-Options` or `frame-ancestors` anywhere in
`api/`, `public/`, `router.php` or `docker/`.

### [x] M-6 — git-deploy lock permanently wedgeable
**Where:** `GitDeploy.php:97-111`

The PID is read from a file inside the repo (attacker-writable, same
reachability as H-4) and passed to `kill -0` with no numeric validation.
Verified: `kill -0 -1` returns rc=0 (it means "every process you may signal"),
so writing `-1` makes the lock read as held forever and short-circuits the
`-mmin` staleness reclaim. Every later deploy returns `409
git_deploy_in_progress` with no server-side recovery.

**Fixed** (commit pending, 2026-09-26): the lock script clears the PID unless
it is digits-only (`case "$P" in (*[!0-9]*|"") P="";; esac`) before `kill -0`,
so a hostile `-1` falls through to the existing `-mmin` staleness reclaim.
Verified in a real shell (`-1`, `abc`, `12x`, empty → cleared; `1234` kept).
`test-git-deploy.php` asserts the guard is present *and* ordered before
`kill -0`; `test-git-deploy-lock.php` 6/6.

### [ ] M-7 — SSH multiplex runtime state lives under the document root
**Where:** `packages/core/api/SshMultiplexer.php:124-128`, `:206-217`

`runtimeDir()` defaults to `packages/core/storage/ssh-sockets` when
`FLUXFILES_STORAGE_PATH` is unset — the shipped default (commented out in
`.env.example`). That directory is inside the served root in both shipped
configs: `docker/nginx.conf` has `root /app/packages/core` and denies only
`/storage/uploads/_fluxfiles/`, `/_fluxfiles/`, dotfiles and
`\.env|composer\.|vendor/`; `router.php` special-cases `/storage/uploads/` and
otherwise `return false`, letting the built-in server serve the file verbatim.

`index.json` (disk names, SSH hostnames, socket paths) is disclosed.
The `keys/<hex>.pem` BYOB private key is written there too — unguessable name
and unlinked in `execCold()`'s `finally`, so theft needs a name leak or a crash
between write and unlink (**suspected**, not demonstrated), but a private key
under a public root is the wrong default either way.

**Fix:** deny `/storage/` except `/storage/uploads/` in both configs, and
default `runtimeDir()` outside the document root (as `OidcDiscovery::cacheDir()`
already does).

---

## LOW

### [ ] L-1 — `/img` variant cache collision between same-named files
**Where:** `packages/core/api/ImageOptimizer.php:321-338` vs `:368-374`

`transformCacheKey()` uses `PATHINFO_FILENAME` (extension stripped). The
upload-time variant path uses `PATHINFO_BASENAME` with the comment: "Include
the FULL filename (with extension) so `a.jpg` and `a.png` get distinct variants
… Must match `FileManager::variantKey()`." The two contradict, and the comment
settles which is intended.

Narrower than it first looks: the cache key also embeds `$ver`, which is
`lastModified($path)` (`index.php:1947`), so `a.jpg` and `a.png` collide only
when they share an mtime second.

Duplicated verbatim at `FluxFilesController.php:2085,2112` and
`FluxFilesApi.php:2387,2417`.

---

## Verified clean (checked, no action)

These were examined and found correct — recorded so a later pass does not
re-litigate them.

- **JWT algorithm/signature.** No `none`, no alg confusion, no
  decode-without-verify anywhere in core. php-jwt v7 does a constant-time alg
  comparison and enforces the 256-bit HMAC floor in both sign and verify. All
  call sites outside tests verify.
- **exp/nbf/iat.** The hole just fixed in the Node SDK's `verifyToken` does
  **not** exist on the PHP side: php-jwt v7 validates `iat`/`nbf`/`exp` are
  numeric, enforces `nbf`, and uses `>=` for expiry so `exp == now` is expired.
  Every minted token type clamps to a `MAX_TTL`.
- **Token-type confusion.** Every consuming path checks its own `t` marker
  (`StreamToken`, `ImageToken`, `SsoBootToken`, `SsoStateToken`, `ShareGrant`).
  `JwtMiddleware::assertAccessToken()` refuses any typed token as a Bearer.
  Cross-endpoint replay is covered by existing tests.
- **CSRF.** The `Origin` check covers POST/PUT/DELETE before routing, fails
  closed, and does not treat a missing `Origin` as allow-all. FluxFiles has no
  cookie/ambient auth, so this is defence-in-depth.
- **`PdfOptimizer`.** Array-argv `proc_open` (no shell), `-dSAFER`, whitelisted
  `-dPDFSETTINGS`, server-created `tempnam()` paths, timeout with
  `proc_terminate`, exit code read before `proc_close`, output re-validated.
- **`UpdateClient`.** Ed25519 manifest verified before download, sha256
  `hash_equals` before extract, entry-name rejection plus a post-extract
  `realpath` containment walk.
- **`UrlImporter`.** Fresh `assertSafeUrl()` per hop, `FOLLOWLOCATION=false`,
  redirect cap enforced, streaming size cap, magic-byte MIME. H-2/H-3 are
  guard-layer bugs, not importer bugs.
- **`SshTerminal` / `SshMultiplexer` argv.** Array-argv exec, `$cwd` correctly
  `escapeshellarg`'d outside quotes, no secrets in argv or env, password and
  passphrase auth correctly refused for multiplexing.
- **git-deploy target selection.** Path, branch and hooks-enabled come only
  from claims; the request body supplies nothing but `disk`.
- **Claim gates and kill-switches.** All enforced server-side, mutually
  independent, none implicitly bundled; the Laravel and WordPress proxies
  mirror core's gate order.
- **SsrfGuard numeric obfuscation.** Decimal, hex, octal and short-form IPv4
  spellings are all blocked, as are `localhost`/`.local`/`.internal`, CGNAT,
  `0.0.0.0`, RFC1918 and IPv6 ULA/link-local. `CURLOPT_PROXY => ''` correctly
  prevents re-resolution around the pin.
