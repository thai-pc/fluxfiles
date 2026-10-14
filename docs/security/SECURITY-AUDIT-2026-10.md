# Security audit — 2026-10-14 (second pass)

Scope: free core (`packages/core/api`), the WordPress and Laravel adapters,
the SDK/React/Vue wrappers, the editor plugins, deployment configs, and a
spot-check of the private modules that core calls into. Everything already
fixed in [`FREE-CORE-SECURITY-AUDIT-2026-09.md`](FREE-CORE-SECURITY-AUDIT-2026-09.md)
was excluded. Branch: `security-audit-fixes-2026-10b`.

Each finding links to its fix and its regression test. The regression tests
for the core fixes live in `packages/core/tests/unit/test-security-fixes-2026-10.php`;
run against the pre-fix code, 10 of its 14 tests fail.

## HIGH

### [x] H-1 — WordPress: Contributors and Authors were minted an admin token

`FluxFilesPlugin::roleForCapabilities()` mapped `delete_posts` to the `admin`
preset. In stock WordPress, `delete_posts` means "delete my OWN posts", and both
Contributor and Author hold it. Those roles therefore received read/write/delete
with `owner_only=false`, plus `allow_code_edit`, `allow_chmod` and `show_hidden`
over the whole disk.

Separately, the shortcode, the block editor, the media button and the
media-library integration all mint through `tokenForCurrentUser()` for **any**
logged-in user. They embed the result as a Bearer JWT, and `checkAuth()` then
accepts it without re-checking the capability. The H-6 capability gate (2026-09)
only covered the cookie path. As a result, a Contributor (who cannot upload in
WordPress) got the admin bundle just by opening the block editor, and a
Subscriber got a read token over every user's files from any page that contains
`[fluxfiles]`.

**Fix:**
- `admin` now requires `manage_options` or `delete_others_posts`.
- `tokenForCurrentUser()` refuses to mint unless the user holds
  `FluxFilesApi::requiredCapability()`. This is the same bar the REST routes use,
  and operators can still lower it through the `fluxfiles_required_capability`
  filter.
- An explicit `role`/`perms` override is still treated as a deliberate operator
  decision, as before.

**Tests:** `packages/wordpress/tests/test-wp-smoke.php` (H-6 block, rewritten to
model the real WordPress roles).

### [x] H-2 — WordPress: the storage directory was served raw

Files live under `wp-content/fluxfiles/uploads/`, which the web server serves
directly, and the plugin shipped no rules for it. Three consequences:

- `_fluxfiles/` was downloadable without authentication: `audit.jsonl` (IPs and
  user agents), `index.json`, `trash.json` with every tenant's soft-deleted
  bytes, and `originals/`, which defeats watermark previews. This is the exact
  leak commit `3729efe` closed for `router.php` and nginx.
- Uploaded `.html`/`.svg` rendered as active content in the **wp-admin origin**,
  because `allowed_ext` defaults to `null`. That is stored XSS, and from there an
  attacker can take over an admin account.
- On hosts that map `.php8` or `.pht` to PHP, an uploaded file could execute
  (see M-1).

**Fix:** `FluxFilesPlugin::protectStorage()` writes two `.htaccess` files and
`index.php` placeholders:
- The storage root `.htaccess` denies everything.
- The `uploads/.htaccess`:
  - returns 403 for `_fluxfiles/` at any depth (case-insensitive) and for
    dotfiles;
  - turns the PHP engine off, removes script handlers and denies script-shaped
    names;
  - serves HTML as `application/octet-stream`;
  - sends `nosniff`, `CSP: sandbox`, and `attachment` for HTML/SVG.

It runs on activation and on every `FluxFilesApi` construction, so existing
installs that never re-activate are covered. It never overwrites a file the
operator wrote (marker-versioned). nginx sites get a documented, tested snippet
in `packages/wordpress/README.md`.

**Verified** against a real Apache 2.4. **Test:** `test-wp-smoke.php`
"storage protection".

## MEDIUM

### [x] M-1 — Dangerous-extension list missed handler-mapped PHP spellings

The following are executed as PHP on common Apache/cPanel setups but were
missing from `FileManager::DANGEROUS_EXTENSIONS`: `php8`, `pht`, `phtm`, `phpt`,
`pgif`, `shtml`/`shtm`/`stm` (SSI), and `asa`/`ashx`/`asmx`. Whole-name
server-config files were also not blocked: `.user.ini` (`auto_prepend_file`
turns any upload into code) and `web.config`. Trailing dots and spaces, which
Windows strips, are now normalised before the check.

### [x] M-2 — Zip extract (and module writes) bypassed the SFTP `.git/` block

`d7459a4` blocks writes into `.git/` on SFTP disks, but only where
`assertNotSystem($path, $disk)` is given a disk. Two paths were missed:
- `extractZip()` checked each entry with `isSystemKey()`, which has no `.git`
  arm. A zip could therefore plant `.git/config` (with `upload_collision=overwrite`)
  or an entire `.git` repository.
- `validateUserPath()`/`validateScopedPath()`, which modules and chunk upload
  use, never passed a disk.

**Fix:** a disk-aware check on every extract entry target. Both validators now
take an optional `$disk`. `writeScopedFile()`/`writeScopedStream()` re-validate
with the disk, so every module write is covered.

### [x] M-3 — Bucket-origin XSS via the stored Content-Type

Presigned S3/R2 GETs signed `ResponseContentDisposition` (inline for raster
images, chosen by extension) but not `ResponseContentType`. The stored type is
client-controlled: it is not part of a presigned PUT's signature, and Flysystem
sniffs it from the bytes on a string write. A `photo.png` PUT with
`Content-Type: text/html` was therefore served inline as HTML from the bucket
origin, which is often a subdomain of the customer's site.

**Fix:** `FileManager::presignContentType()` (extension-derived) is signed into
every presigned GET in `presign()`, `presignGetUrl()` and
`DiskManager::presignGetUrl()` (the share route).

**Residual:** public-bucket permanent URLs still serve the stored type. Use
private buckets, or front them with a response-header policy.

### [x] M-4 — The SSO boot token carried the real JWT in the clear

`SsoBootToken` nested `real_jwt` in a signed-but-not-encrypted JWT. A JWT
payload is only base64, so anyone who saw the `#boot=` fragment could decode the
full-TTL access token without calling the exchange endpoint, which made the 60s
window meaningless. Ways to see the fragment include proxy or APM logging of the
callback's `302 Location`, a browser extension, or a screen share.

**Fix:** the JWT is sealed with AES-256-GCM under an HKDF key with its own info
string. Legacy plaintext-shape tokens are refused.

## LOW-MEDIUM

### [x] L-1 — Zip bomb: the inflated bytes were not counted

The extract caps trusted the uncompressed size the archive **declares**. Tested:
an entry patched to declare 10 bytes inflated to 5 MB through libzip. The fix
copies each entry with a byte counter capped at its declared size and aborts
with `zip_too_large` before anything is written to storage.

### [x] L-2 — Junk tokens grew the shared rate-limit file without bound

Before rate-limiting, the public share/intake routes keyed their bucket on the
unverified `jti`, or on a hash of the raw token. Every junk token therefore
added a new key to the single `rate_limit.json`, which every request in the
deployment locks and rewrites.

**Fix:** the signature is verified first, which costs one HMAC. A verified token
buckets by its `jti`; anything else buckets by client IP.

### [x] L-3 — The legal hold did not block in-place content mutation

This was a documented v1 gap (RETENTION-LEGAL-HOLD-DESIGN §6). An overwrite of
an **existing** held file is now refused on every path:
- overwrite and force upload;
- chunk and presign PUT onto an existing key;
- `/content` PUT and `/chmod`;
- in-place watermark burn and watermark removal;
- extract with `upload_collision=overwrite`;
- the optimize source;
- metadata save, delete and import;
- every module write.

A new file inside a held folder is still allowed.

## LOW

### [x] L-4 — nginx: the `\.php$` regex applied to uploaded files

`location /storage/uploads/` was a plain prefix, so the server-level FastCGI
regex was still consulted for `/storage/uploads/x.php`. It is now `^~` with the
`_fluxfiles`/dotfile/script denies nested inside it (verified with a real
nginx). `DEPLOYMENT.md` mirrors this. The Apache section gained the
`storage/`-except-uploads deny, a dotfile deny, and an uploads `.htaccess`
(engine off, `nosniff`, `CSP sandbox`).

### [x] L-5 — Demo mode trusted `X-Forwarded-For`/`CF-Connecting-IP` from anyone

A random header per request let a client mint unlimited sandboxes. That evicted
real visitors through the budget purge and grew `demo_ip.json`. The headers are
now honoured only when `REMOTE_ADDR` is in the new `FLUXFILES_DEMO_TRUSTED_PROXIES`
setting (IPs/CIDRs, IPv4 and IPv6). `X-Forwarded-For` is walked from the right.

> **Deploy note:** set this on the public demo host (Cloudflare ranges),
> otherwise all visitors share the CDN edge IP's mint budget.

### [x] L-6 — Metadata import could reassign or wipe owners (DB backend)

`owner` is what `assertOwner()` trusts. An import from a token without
`owner_only` could null it, which handed the file to every `owner_only` user in
that prefix. An import now never changes an existing owner. It only fills
ownerless rows, and new rows keep the imported owner.

### [x] L-7 — `show_hidden=false` was cosmetic

Dotfiles were hidden only in `list()`. They were still readable by name through
`/content`, `/meta`, `/presign`, `/zip` and `/chmod`. That is now enforced on
those routes, and a folder zip skips hidden children. Hidden status is judged
relative to the tenant prefix, so a dotted prefix never hides a tenant's whole
tree.

## Verified clean (this pass)

- The UI, `share.html`/`intake.html` and the SDK/React/Vue wrappers have no HTML
  sinks. Every `postMessage` listener checks `e.source` and the origin.
- The CKEditor 4, TinyMCE and Summernote plugins attribute-encode
  `url`/`alt`/`title`.
- Typed tokens (`intake`/`share`/`sso_boot`) are refused as Bearer tokens, and
  the intake public route checks the `intake` flag.
- `CredentialEncryptor` (AES-256-GCM + HKDF) and the license check (Ed25519) are
  sound. The DB layer uses prepared statements throughout; its only interpolated
  identifiers are code constants.
- Signing keys under `docs/` are gitignored and absent from git history.
- The Laravel and WordPress proxies already carry the 2026-10 quota/usage disk
  checks.

## Known pre-existing failure (unrelated)

`packages/c2pa/tests/test-c2pa.php` fails one case ("C2PA signing failed") on
machines without a working `c2patool` signing setup. It fails identically with
these changes stashed.
