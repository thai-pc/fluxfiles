# FluxFiles — Python Server-Side Token SDK Design (`fluxfiles-token`)

> **Status: Implemented and shipped** (`python-v0.1.0`, `CHANGELOG.md`
> `[0.3.0x]`, released alongside `core-v0.2.83`/`node-v0.1.28`). This is the
> third token-minting SDK (after the PHP core's `embed.php` and
> `@fluxfiles/node`), so it needed no new business decision — it's the same
> "free MIT tooling" category as Node's package (§1 of `docs/CONFIG.md`'s
> minting API is already documented as "PHP / Node / Laravel / WordPress";
> Python is a fourth mint-side surface, not a fifth paid module). No plan doc
> exists for this because none was needed: the scope is fixed by
> definition — byte-compatible parity with the two existing implementations,
> nothing more. `packages/python/` ships `fluxfiles-token` (PyPI) with the
> full typed API surface, shared `docs/testdata/token-vectors.json` /
> `byob-vectors.json` fixtures, and the `pypi-publish.yml` CI workflow
> described below.
>
> **§§1, 2, 3, 5 below reflect final decisions made 2026-09-08** on package
> name, Python floor, API surface, and role/edition-on-BYOB scope. **§6 was
> updated the same day** with a sixth decision — full shared PHP/Node/Python
> test fixtures — which also touches the *existing* PHP and Node suites, not
> just Python's. See §9 for the full decision record.

## 0. Problem & who pays

**Who this is for:** a Django/Flask/FastAPI backend that wants to hand a
browser a FluxFiles access token without shelling out to PHP or running a
Node sidecar just to sign a JWT. Identical persona to `@fluxfiles/node`'s
README: "you still run a FluxFiles core service; this SDK only removes the
requirement that *your app* be PHP/Node to mint the tokens that talk to it."

**Free/core, not a paid module.** `@fluxfiles/node` is `MIT`, zero-cost, and
ships from the public monorepo (`packages/node/`, published to npm as
`@fluxfiles/node`). The Python port must be the same: MIT-licensed, free,
published from a public `packages/python/` directory (not gitignored like the
paid modules under `packages/share/`, `packages/ai/`, etc.). It touches no
`ModuleRegistry` gate, no license check, no new JWT claim, no server code —
it is purely a client-side JWT construction library that happens to also
implement the AES-256-GCM/HKDF BYOB encryption so a Python caller can mint a
BYOB token too.

## 1. Package shape

| | |
|---|---|
| PyPI project name | **`fluxfiles-token`** — decided, final. |
| Import package name | `fluxfiles_token` (PyPI hyphens → Python underscores, standard convention) |
| Directory | `packages/python/` (new, mirrors `packages/node/` at the top level) |
| Python floor | **3.10+ — decided, final.** |
| Runtime deps | `PyJWT>=2.8,<3` (JWT encode/decode, HS256), `cryptography>=42` (AES-256-GCM + HKDF-SHA256) |
| Dev deps | `pytest>=8`, `mypy>=1.10`, `ruff` (lint, matches the "zero-build, but typed" spirit of Node's `tsup`+`tsc --noEmit`) |
| License | MIT (matches core + Node) |

**Python floor: 3.10+, committed.** As of today (2026-09), Python 3.9 reached
end-of-life on 2025-10-31 and Python 3.10 reaches EOL 2026-10-31 (i.e. this
same month, at design time) — so 3.10 is the oldest version worth declaring
support for at all, and is where the floor is set. `str | X` union syntax
(PEP 604, needs 3.10+) is used throughout the typed API in §2. This package
ships already knowing 3.10 itself is going EOL shortly; that's an accepted
trade-off for a floor that still covers the realistic install base of
current Django 5.x/FastAPI users at ship time, not a hedge to revisit later.

**PyPI name: `fluxfiles-token`, confirmed final.** Verified available (no
existing PyPI project under either `fluxfiles` or `fluxfiles-token` at time
of writing); `fluxfiles-token` is used everywhere in this document, the
`pyproject.toml` sketch below, and should be used as the actual published
name — not a placeholder.

**Directory layout** (mirrors `packages/node/`'s `src/`+`tests/` shape, but
uses the Python **src-layout** convention — `src/<import_name>/` — which
Node's flat `src/*.ts` doesn't need since npm has no equivalent
accidentally-importing-the-cwd-package footgun that `src/`-layout exists to
prevent in Python):

```
packages/python/
├── pyproject.toml              # hatchling build backend, project metadata, deps
├── README.md                   # mirrors packages/node/README.md's shape/sections
├── LICENSE                     # MIT, copied from repo root like packages/node/LICENSE
├── src/
│   └── fluxfiles_token/
│       ├── __init__.py         # public exports: create_token, create_byob_token,
│       │                       #   verify_token, decode_token, types, exceptions
│       ├── py.typed            # empty marker file — tells mypy/pyright this pkg ships types
│       ├── token.py             # create_token(), create_byob_token(), presets
│       ├── crypto.py            # derive_byob_key, encrypt_byob, decrypt_byob
│       ├── verify.py            # verify_token, decode_token (thin PyJWT wrappers)
│       ├── types.py             # TypedDicts: ByobS3DiskConfig, ByobSftpDiskConfig,
│       │                       #   ByobDiskConfig, FluxClaims
│       └── exceptions.py        # FluxFilesTokenError, FluxFilesByobError
└── tests/
    ├── test_token.py            # unit tests — claim shape, presets, escape hatch
    ├── test_crypto.py           # AES-GCM/HKDF byte-layout vectors
    └── test_php_compat.py       # cross-language: mint here → decode/decrypt in PHP, and back
```

## 2. API shape

**Decided: full typed parity with Node**, not the smaller base-plus-dict
surface an earlier draft of this doc proposed. `create_token()` and
`create_byob_token()` type-hint essentially all of
`packages/node/src/token.ts`'s `BaseTokenOptions` fields (~80 options),
1:1 by name, translated from Node's camelCase to Python's idiomatic
snake_case (`maxUploadMb` → `max_upload_mb`, `ownerOnly` → `owner_only`,
`webpMaxWidth` → `webp_max_width`, …). The `claims: dict[str, Any] | None`
escape hatch stays, scoped down to its real purpose now: **claims not yet
ported to a typed kwarg** (i.e. a brand-new claim added to `docs/CONFIG.md`
after this SDK's last release), not a substitute for typing the existing
~80.

Python's own keyword-argument call already *is* "one options object" — no
need to force callers to build a separate `dict`/dataclass first. Below is
the full signature, grouped exactly like `token.ts`'s `applyTenantOverrides()`
groups its forwarding logic, for direct side-by-side comparison during
implementation.

```python
def create_token(
    *,
    # --- identity / access / quota (CreateTokenOptions base) ---
    user_id: str,
    secret: str | None = None,                 # falls back to FLUXFILES_SECRET env var
    perms: list[str] | None = None,            # None = not provided (see §5 sentinel note)
    disks: list[str] | None = None,            # default ["local"]
    prefix: str | None = None,                  # default ""
    max_upload_mb: int | None = None,           # default 10
    allowed_ext: list[str] | None = None,       # None = all non-dangerous types
    ttl: int | None = None,                      # default 3600 (seconds)
    owner_only: bool | None = None,             # None = not provided (see §5)
    max_storage_mb: int | None = None,           # default 0 = unlimited
    max_files: int | None = None,                # default 0 = unlimited

    # --- presets (DX sugar, never themselves become claims) ---
    edition: str | None = None,                  # "pro" | "agency" | "studio" | "enterprise"
    role: str | None = None,                     # "viewer" | "editor" | "admin" | "superadmin"

    # --- per-tenant AI / rate-limit / variants ---
    ai_auto_tag: bool | None = None,
    rate_read: int | None = None,
    rate_write: int | None = None,
    variants: dict[str, int] | None = None,      # {"thumb": 150, "medium": 768, "large": 1920}

    # --- URL import ---
    allow_url_import: bool | None = None,
    max_import_mb: int | None = None,
    import_url_allowlist: list[str] | None = None,
    import_path: str | None = None,
    import_rate_limit: int | None = None,
    import_concurrency: int | None = None,

    # --- media preview / streaming ---
    media_preview: bool | None = None,
    preview_url_ttl: int | None = None,
    max_preview_mb: int | None = None,
    stream_token_ttl: int | None = None,

    # --- on-demand WebP/AVIF + responsive images ---
    webp_enabled: bool | None = None,
    webp_max_width: int | None = None,
    webp_default_quality: int | None = None,
    srcset_widths: list[int] | None = None,
    srcset_sizes: str | None = None,

    # --- download gate / SFTP chmod / code-edit / terminal / BYO-embed URLs ---
    allow_download: bool | None = None,
    allow_chmod: bool | None = None,
    allow_code_edit: bool | None = None,
    allow_terminal: bool | None = None,
    terminal_pty_url: str | None = None,
    pdf_tools_url: str | None = None,
    office_url: str | None = None,
    esign_url: str | None = None,

    # --- optimization (free/core) ---
    allow_optimize: bool | None = None,
    auto_optimize: bool | None = None,
    optimize_quality: int | None = None,
    optimize_keep_original: bool | None = None,
    optimize_max_mb: int | None = None,
    pdf_level: str | None = None,                # "screen"|"ebook"|"printer"|"prepress"|"default"

    # --- upload behavior / listing ---
    upload_collision: str | None = None,          # "rename"|"overwrite"|"reject"
    show_hidden: bool | None = None,
    dedupe_uploads: bool | None = None,

    # --- zip/extract ---
    allow_zip: bool | None = None,
    allow_extract: bool | None = None,
    zip_max_mb: int | None = None,
    zip_max_files: int | None = None,

    # --- paid modules (all default off; inert unless installed + licensed) ---
    allow_share: bool | None = None,
    share_url_ttl: int | None = None,
    share_base_url: str | None = None,
    share_preview: bool | None = None,
    share_analytics: bool | None = None,
    allow_intake: bool | None = None,
    intake_base_url: str | None = None,
    allow_versioning: bool | None = None,
    versioning_max: int | None = None,
    versioning_max_mb: int | None = None,
    allow_webhooks: bool | None = None,
    webhook_url: str | None = None,
    webhook_events: list[str] | None = None,
    webhook_secret: str | None = None,
    allow_ai_vision: bool | None = None,
    allow_ocr: bool | None = None,
    allow_virus_scan: bool | None = None,
    allow_backup: bool | None = None,
    allow_c2pa: bool | None = None,

    # --- watermark overlay ---
    watermark_enabled: bool | None = None,
    watermark_type: str | None = None,            # "text" | "logo"
    watermark_text: str | None = None,
    watermark_logo_path: str | None = None,
    watermark_position: str | None = None,
    watermark_opacity: float | None = None,
    watermark_font_size: int | None = None,

    # --- usage dashboard ---
    usage_cache_ttl: int | None = None,
    usage_warning_threshold: int | None = None,
    usage_critical_threshold: int | None = None,
    usage_top_folders_count: int | None = None,
    usage_folder_depth: int | None = None,

    # --- escape hatch: anything not yet a typed kwarg above ---
    claims: dict[str, Any] | None = None,
) -> str: ...


def create_byob_token(
    *,
    user_id: str,
    byob_disks: dict[str, ByobDiskConfig],        # name -> {"driver": "s3"|"sftp", ...}
    secret: str | None = None,
    perms: list[str] | None = None,               # default ["read", "write"]
    prefix: str | None = None,
    ttl: int | None = None,                        # default 1800 — shorter than create_token
    max_upload_mb: int | None = None,
    allowed_ext: list[str] | None = None,
    owner_only: bool | None = None,

    # --- presets: INCLUDED on BYOB tokens (decided — see §5) ---
    edition: str | None = None,
    role: str | None = None,

    # ... every other group above (ai_auto_tag, rate_read/write, import,
    # media, webp/srcset, download/chmod/code_edit/terminal/embed-URLs,
    # optimize, upload_collision/show_hidden/dedupe, zip/extract, all
    # paid-module claims, watermark, usage-dashboard) — identical typed
    # kwargs as create_token(), omitted here for brevity but present 1:1.

    claims: dict[str, Any] | None = None,
) -> str: ...


def verify_token(token: str, secret: str | None = None) -> FluxClaims: ...
def decode_token(token: str) -> FluxClaims: ...   # NO signature check — inspection only
```

**Example — plain token:**

```python
token = create_token(
    user_id="user-42",
    perms=["read", "write"],
    disks=["local", "s3"],
    prefix="users/42",
    max_upload_mb=25,
    allowed_ext=["png", "jpg", "pdf"],
    allow_share=True,
    share_url_ttl=120,
    webp_enabled=True,
    watermark_enabled=True,
    watermark_text="© Acme 2026",
)
```

Note every option above is a real typed kwarg now (`allow_share`,
`share_url_ttl`, `webp_enabled`, `watermark_enabled`, `watermark_text`) —
the equivalent example in an earlier draft of this doc routed all of these
through `claims={...}`; that pattern is now reserved for genuinely
not-yet-typed claims only.

**Example — BYOB token with a role preset (new capability per §5):**

```python
token = create_byob_token(
    user_id="user-42",
    role="editor",
    byob_disks={
        "my-s3": {
            "driver": "s3",
            "key": os.environ["USER_AWS_KEY"],
            "secret": os.environ["USER_AWS_SECRET"],
            "bucket": "user-personal-bucket",
            "region": "us-east-1",
        },
    },
)
# decodes with perms=["read","write"], owner_only=True, allow_extract=True,
# allow_chmod=False (the "editor" bundle — see §5) AND byob_disks set.
```

`ByobDiskConfig` is a `TypedDict` union (`ByobS3DiskConfig | ByobSftpDiskConfig`)
with the identical fields to `packages/node/src/types.ts`'s
`ByobS3DiskConfig`/`ByobSftpDiskConfig` — `driver`, `key`/`secret`/`bucket`/
`region`/`endpoint`/`visibility`/`public_url` for S3; `driver`/`host`/
`username`/`password`/`private_key`/`private_key_passphrase`/`port`/`root`
for SFTP. A lightweight `_validate_byob_disk(name, config)` pre-check (raising
`FluxFilesByobError`, a subclass of `FluxFilesTokenError`) mirrors Node's
`validateByobDisk`: driver must be `s3` or `sftp` (never `local`); SFTP needs
`host`+`username`+(`password` or `private_key`); S3 needs `key`+`secret`+
`bucket`. This is a client-side friendliness check only — the real,
security-relevant validation (including the SSRF check on a custom S3
`endpoint`) happens server-side in `CredentialEncryptor::validate()` on
**every decode**, not just at mint time.

**Errors.** A single `FluxFilesTokenError(Exception)` base class (idiomatic
Python, unlike Node's plain `Error`), with `FluxFilesByobError` for BYOB
validation/crypto failures. `verify_token()` lets PyJWT's own exceptions
(`jwt.ExpiredSignatureError`, `jwt.InvalidSignatureError`, …) propagate
rather than re-wrapping them — Python users of a JWT-adjacent library expect
PyJWT's exception hierarchy.

## 3. Claims parity

**No new claims. No renamed claims.** Every claim this SDK can set is one of
the ~100 rows already in `docs/CONFIG.md` §2, using the exact snake_case name
the PHP core's `Claims::fromJwtPayload` decodes. With the full-typed-parity
decision in §2, there are now two ways a claim reaches the payload, and both
use the identical name:

1. **A typed kwarg** (the ~80 options in §2's signature) — each kwarg name
   already **is** the claim name for most options (`allow_share`,
   `webp_enabled`, `watermark_text`, …), except the handful that carry an
   explicit unit suffix matching Node's own naming (`max_upload_mb` → claim
   `max_upload`, `max_storage_mb` → claim `max_storage`) — kept identical to
   Node's convention rather than inventing a different one for Python.
2. **The `claims` escape hatch** — the raw claim name, unchanged, for
   anything not yet promoted to a typed kwarg.

`claims` values are merged into the payload **last**, exactly matching the
merge order in `embed.php`'s `_fluxfiles_build_token()`,
`token.ts`'s `applyTenantOverrides()`, and (per §5) Laravel/WordPress's
`applyTenantOverrides()` — edition preset → role preset → typed kwargs →
escape-hatch `claims` (explicit always wins). The server
(`Claims::fromJwtPayload`) sanitizes/clamps every claim on decode regardless
of which SDK minted it, so the Python SDK does **not** need to duplicate any
of that clamping logic (e.g. `share_url_ttl`'s `[10,300]` clamp, or
`zip_max_mb`'s bounds) — it only needs to put the right JSON type (`bool`,
`int`, `str`, `list[str]`, or `None`) at the right key.

**One correctness requirement:** JSON types must match what `Claims.php`
expects. `bool` values must be Python `True`/`False` (PyJWT/`json` serialize
these as JSON `true`/`false`, matching PHP's `(bool)` casts and JS's `!!`);
`allowed_ext`/`disks`/`perms`/`webhook_events`/`import_url_allowlist` must be
`list[str]`, never a `tuple` or `set` (`json.dumps` would encode a `set` and
fail entirely — PyJWT's default JSON encoder does not special-case `set`).

## 4. Crypto byte-compatibility

This is the highest-risk part of the port — a wrong byte matches nothing,
silently, because AES-GCM authentication failure just looks like "decrypt
failed" with no further diagnostic. The exact scheme, read from
`packages/core/api/CredentialEncryptor.php` and
`packages/node/src/crypto.ts`:

### 4.1 Key derivation (HKDF-SHA256)

```
IKM   = secret (the same FLUXFILES_SECRET string used for HS256 signing,
        UTF-8 encoded — there is no separate BYOB-only secret)
salt  = 32 zero bytes                     (b"\x00" * 32)
info  = b"fluxfiles-byob-enc"              (UTF-8 literal, exact string)
hash  = SHA-256
L     = 32                                 (AES-256 key length)
key   = HKDF-Expand(HKDF-Extract(salt, IKM), info, L)
```

**The one real gotcha, already hit and documented in Node's own source
(`crypto.ts:36-38`):** PHP's `hash_hkdf('sha256', $secret, 32, $info)` omits
the `$salt` parameter, and PHP's implementation follows RFC 5869's rule that
an *omitted/empty* salt is treated internally as "`HashLen` zero bytes" (32
zero bytes for SHA-256) — **not** as a literal empty string passed into the
HMAC. Node's `hkdfSync` does **not** perform that automatic substitution, so
Node has to pass an explicit `Buffer.alloc(32, 0)` to match. Python's
`cryptography.hazmat.primitives.kdf.hkdf.HKDF` class *does* document
"if salt is not provided, it defaults to a bytes object of zeros equal to the
digest_size of algorithm" — i.e. its `None` default is *already* RFC-5869
correct and would happen to match. **Do not rely on that default anyway.**
Pass `salt=b"\x00" * 32` explicitly, exactly like Node does, so the code is
self-documenting and immune to any future change in `cryptography`'s default
behavior. This single line is the crux of cross-language compatibility —
the recommended `test_php_compat.py` (§6) and the shared `docs/testdata/
byob-vectors.json` known-answer vectors (§6.1) round-trip a PHP-encrypted
blob through Python decrypt and a Python-encrypted blob through PHP decrypt
as release-blocking tests, exactly like Node's `php-compat.test.ts` already
does.

### 4.2 AES-256-GCM encrypt/decrypt

```
key    = 32 bytes, from §4.1
nonce  = 12 random bytes, freshly generated PER CALL (os.urandom(12) —
         never reuse a nonce with the same key; both PHP random_bytes(12)
         and Node randomBytes(12) already do this per-call)
aad    = none / empty (PHP passes '' as the openssl_encrypt AAD arg; Node's
         createCipheriv call passes no aad; Python: associated_data=None)
tag    = 16 bytes, GCM's standard 128-bit authentication tag
```

Plaintext is the disk config, JSON-encoded, **UTF-8, forward slashes NOT
escaped**:

- PHP: `json_encode($config, JSON_UNESCAPED_SLASHES)` — the flag is required
  because PHP's default *does* escape `/` as `\/`.
  Python: `json.dumps(config, ensure_ascii=False)` — **no flag needed**;
  Python's stdlib `json` module has never escaped forward slashes, so this
  requirement is automatically satisfied. (`ensure_ascii=False` isn't a
  correctness requirement — a decrypting party always re-parses JSON, and
  `\uXXXX`-escaped and literal-UTF-8 forms decode to the identical string —
  but keeping it off matches Node's `JSON.stringify` output style and avoids
  needlessly bloating the ciphertext.)

**Wire/byte layout of the final blob** (before base64):

```
┌─────────────┬───────────────────────────┬──────────────┐
│  nonce (12) │  ciphertext (N bytes)     │  tag (16)    │
└─────────────┴───────────────────────────┴──────────────┘
```

Identical in PHP (`$nonce . $ciphertext . $tag`, via `openssl_encrypt`'s
`OPENSSL_RAW_DATA` mode with a separate `$tag` out-param) and Node
(`Buffer.concat([nonce, ct, tag])`, via `cipher.getAuthTag()`).

**Python implementation detail that makes this easy:**
`cryptography.hazmat.primitives.ciphers.aead.AESGCM.encrypt(nonce, data, aad)`
returns `ciphertext || tag` **already concatenated** (the tag is always the
trailing 16 bytes of its return value) — so the Python blob assembly is
simply `nonce + aesgcm.encrypt(nonce, plaintext, None)`, no manual
tag-splicing needed. Symmetrically, `AESGCM.decrypt(nonce, data, aad)` expects
its `data` argument to already be `ciphertext || tag` concatenated — so
decrypt is `aesgcm.decrypt(nonce, raw[12:], None)` where `raw[:12]` is the
nonce. This happens to line up perfectly with the PHP/Node wire format with
zero re-slicing beyond splitting off the 12-byte nonce prefix.

### 4.3 Outer encoding

- **The blob itself** (nonce+ciphertext+tag) is **standard base64**
  (`+`/`/`, `=`-padded) — `base64.b64encode()`, **not**
  `base64.urlsafe_b64encode()`. PHP's `base64_encode()`/`base64_decode()`
  and Node's `Buffer.toString('base64')` are both standard-alphabet; this
  BYOB blob sits as a plain **string value** inside the JWT payload (under
  `byob_disks.<name>`), not inside the JWT's own base64url-encoded
  header/payload/signature segments — those two encodings must not be
  conflated.
- **The JWT itself** (header/payload/signature) uses **base64url, no
  padding** — standard JWS compact serialization. This is handled entirely
  by PyJWT (`jwt.encode`/`jwt.decode`) and needs no custom code; PyJWT and
  `firebase/php-jwt` v7 both implement the same RFC 7519/7515 wire format, so
  there is no cross-language byte-matching requirement here beyond correct
  claim **values** (§3) — signature verification is always done by decoding
  your *own* freshly-minted token, never by comparing serialized bytes
  across languages.

### 4.4 Minor mint-time details to replicate exactly

- `sub` must be cast to `str(user_id)` — PHP does `(string) $userId`; a
  Python caller passing an `int` user id must not leak an `int` into the
  claim (`Claims::fromJwtPayload` reads `sub` loosely, but every other SDK
  stringifies it, and downstream code — audit logs, `owner_only` matching —
  assumes string identity).
- `jti` = 12 random bytes, hex-encoded → 24 hex chars: `secrets.token_hex(12)`
  (matches PHP's `bin2hex(random_bytes(12))` and Node's
  `randomBytes(12).toString('hex')`).
- Secret length guard: reject (raise `FluxFilesTokenError`) if
  `len(secret.encode("utf-8")) < 32` — HS256 key-length requirement, matches
  Node's `resolveSecret()` and the CLAUDE.md note that HS256 keys must be
  ≥32 bytes for `firebase/php-jwt` v7.

## 5. Role & edition presets

Both are ported, verbatim, from the **already-fixed** state documented in
`docs/ACL-ROLE-PRESETS-DESIGN.md`'s "Status" section — not from that
document's original (buggy) draft tables. Two historical bugs must not be
reintroduced:

1. **`perms` and `owner_only` need early resolution**, not a post-hoc
   "if absent" guard — because both already have an unconditional default
   baked into the base payload construction, a guard that only fires when
   the key is *fully absent* can never fire for them.
2. **`viewer`/`editor` must set `allow_extract`/`allow_chmod` explicitly**
   (`False`/`False` for viewer, `True`/`False` for editor) — `Claims.php`
   defaults both to `True` when the claim is *absent*, so silently omitting
   them (under an "absent = false" assumption) accidentally grants
   chmod/extract to a token that's supposed to be read-only or
   contributor-only. This was a real, shipped bug (`d880b98`/`fb7c8a2`,
   `CHANGELOG.md` `[0.3.00]`) in every other SDK before being fixed.

**A genuine Python-idiomatic improvement over the JS/PHP mechanism:** the bug
class above exists because JS/PHP represent "not provided" and "explicitly
false" identically (`if (opts.ownerOnly) payload.owner_only = true` can't
tell a caller's explicit `ownerOnly: false` apart from an omitted key). Every
boolean/list option in §2's signature defaults to `None` (never `False`/an
empty list) specifically so the SDK can distinguish "unset" from "explicitly
false", resolved once per option as:

```python
# owner_only precedence, resolved once, no separate "guard loop" needed:
resolved_owner_only = (
    owner_only if owner_only is not None
    else role_preset.get("owner_only")   # None if the preset doesn't set it
    if role_preset is not None
    else False                            # global default
)
```

This closes the exact bug class at the type-system level rather than by
convention/test coverage — worth calling out to the maintainers as a reason
the Python SDK's implementation should NOT literally transliterate the
JS `if (truthy) set` pattern, even though its *behavior* must match.

**Exact preset tables** (identical values to `packages/core/embed.php`'s
`fluxfiles_role_preset()` / `packages/node/src/token.ts`'s `ROLE_PRESETS`,
post-fix):

```python
ROLE_PRESETS: dict[str, dict[str, Any]] = {
    "viewer": {
        "perms": ["read"], "owner_only": True,
        "allow_extract": False, "allow_chmod": False,
    },
    "editor": {
        "perms": ["read", "write"], "owner_only": True,
        "allow_extract": True, "allow_chmod": False,
    },
    "admin": {
        "perms": ["read", "write", "delete", "audit"], "owner_only": False,
        "allow_extract": True, "allow_chmod": True,
        "allow_code_edit": True, "show_hidden": True,
    },
    "superadmin": {  # identical bundle to admin — see ACL doc §2's note
        "perms": ["read", "write", "delete", "audit"], "owner_only": False,
        "allow_extract": True, "allow_chmod": True,
        "allow_code_edit": True, "show_hidden": True,
    },
}

EDITION_PRESETS: dict[str, dict[str, bool]] = {
    "pro":    {"allow_optimize": True, "allow_share": True, "allow_intake": True},
    "agency": {"allow_optimize": True, "allow_share": True, "allow_intake": True},
    "studio": {
        "allow_optimize": True, "allow_share": True, "allow_intake": True,
        "allow_versioning": True, "allow_webhooks": True,
        "allow_ai_vision": True, "allow_ocr": True,
    },
    "enterprise": {
        "allow_optimize": True, "allow_share": True, "allow_intake": True,
        "allow_versioning": True, "allow_webhooks": True,
        "allow_ai_vision": True, "allow_ocr": True,
        "allow_virus_scan": True, "allow_c2pa": True, "allow_backup": True,
        "allow_audit_export": True, "allow_dlp_scan": True,
        "allow_legal_hold": True,
    },
}
```

**Merge order for `create_token()`** (matches `_fluxfiles_build_token()`/
Node's `applyTenantOverrides()` exactly): base payload (with `perms`/
`owner_only` already resolved per the tri-state rule above) → edition preset
(only fills keys not already present) → role preset, excluding `perms`/
`owner_only` (only fills keys not already present) → every other typed kwarg
from §2 (explicit — always overwrites a preset default) → `claims` escape
hatch (always wins, merged last).

### 5.1 Role/edition on BYOB tokens — decided: INCLUDED (Laravel/WordPress precedent)

**Decision:** `create_byob_token()` supports `role`/`edition`, unlike core's
`fluxfiles_byob_token()`/Node's `createByobToken()`, which both exclude
them. This follows **Laravel's/WordPress's** actual behavior instead — both
adapters' `tokenWithByob()`-equivalent functions call the exact same
`applyTenantOverrides()` used by their plain-token path, so a BYOB token
gets the identical edition/role treatment as a regular one. Re-verified
against `packages/laravel/src/FluxFilesManager.php` (lines 480–527,
`applyTenantOverrides()` at 129–451) and `packages/wordpress/includes/FluxFilesPlugin.php`
(the parallel `applyTenantOverrides()`/BYOB builder) for this design.

**Exact merge order for `create_byob_token()`**, following the Laravel/
WordPress reference precisely:

1. **Encrypt each `byob_disks` entry** (`_validate_byob_disk()` then
   `encrypt_byob()`, §2/§4) — independent of any preset, computed first.
2. **Compute `disks`** — BYOB tokens set `disks` to the BYOB disk *names*
   (Node's behavior) — Laravel/WordPress instead **merge** server-configured
   default disks with the BYOB disk names (`array_merge($serverDisks,
   array_keys($byobDisks))`). Because this Python SDK has no server-side
   config store to merge against (it mints standalone, like Node — there is
   no `config('fluxfiles.defaults')` equivalent), `disks` is simply the BYOB
   disk names, matching Node's simpler behavior; only the role/edition
   *preset-merge mechanics* below are taken from Laravel/WordPress, not
   their disks-merging behavior (which is a Laravel/WordPress-specific
   consequence of those adapters carrying a server-side config file that a
   standalone SDK doesn't have).
3. **Resolve `perms`/`owner_only` early** — identical tri-state rule as
   `create_token()`, using `opts.perms ?? role_preset.perms ?? ["read",
   "write"]` (BYOB's own base default, matching Node's
   `createByobToken()` default of `['read', 'write']` rather than plain
   tokens' `['read']` — this is unchanged by the role/edition decision).
4. **Build the base payload**, including the freshly-encrypted `byob_disks`
   claim from step 1, `disks` from step 2, and `perms`/`owner_only` from
   step 3.
5. **Apply the edition preset** — fills any key not already present.
6. **Apply the role preset**, excluding `perms`/`owner_only` (already
   resolved in step 3) — fills any key not already present. *(Steps 5–6 are
   literally the shared `applyTenantOverrides()` call Laravel/WordPress make
   from both their plain-token and BYOB-token builders — same function, same
   order, no BYOB-specific branch.)*
7. **Apply every other typed kwarg from §2** — explicit values always
   overwrite whatever a preset set in steps 5–6.
8. **Apply the `claims` escape hatch last** — always wins over everything.

Net effect: `create_byob_token(role="editor", byob_disks={...})` produces a
token with `perms=["read","write"]`, `owner_only=True`, `allow_extract=True`,
`allow_chmod=False` (the editor bundle) **and** the encrypted `byob_disks`
claim — both apply together, exactly as they would if a Laravel/WordPress
operator minted the equivalent BYOB+role token today.

**Claims a role preset never touches, still true for BYOB tokens too:**
`prefix`/`disks`/`user_id`/quota claims, and — critically — no role preset
ever sets any paid-module `allow_<x>` claim or `allow_terminal` (those come
only from `edition` or explicit kwargs/`claims`), per
`docs/ACL-ROLE-PRESETS-DESIGN.md` §2's closing notes. This is unchanged by
including role/edition on BYOB — the presets' *content* doesn't change
depending on which builder calls them, only whether BYOB *reaches* them at
all (now: yes).

## 6. Testing plan

Mirrors `packages/node/tests/`'s two-tier shape, **plus** a new shared
cross-language fixture layer decided 2026-09-08 (§9 decision #6). The fixture
decision changes what "mirrors" means here: role/edition-preset vectors and
BYOB crypto known-answer vectors are no longer hand-copied into each
language's test file — all three languages load the same JSON.

### 6.1 Shared fixture format (`docs/testdata/`)

Two new files, both plain JSON (no language-specific syntax), loadable by
PHP's `json_decode()`, Node's `JSON.parse()`, and Python's `json.load()`
with zero preprocessing:

**`docs/testdata/token-vectors.json`** — plain-token and preset claim
vectors. Scope decision: this file covers **role/edition presets and the
generic `claims` escape hatch precedence** (the actual cross-language-risk
logic — the same preset tables and merge order ported three times) — it
does **not** attempt to cover every one of §2's ~80 typed kwargs, because
those kwargs have a different *name* in each language (`maxUploadMb` /
`max_upload_mb` / PHP's `max_upload`) and testing "does typed kwarg X forward
to claim Y" is inherently a per-language, per-SDK concern (already covered
by each language's own unit tests — e.g. Python's §6.2 "full-typed-surface
coverage" test). To stay name-agnostic across languages, every vector
expresses its input **only** via fields every SDK's one-options entry point
already spells identically — `user_id`, `role`, `edition`, `ttl_seconds` —
plus a `claims` map for anything else, using **raw claim names** (the one
input mechanism guaranteed identical in PHP/Node/Python: the escape hatch
itself). Each language's test harness mints the vector via its own
`create_token`-equivalent, passing `claims` straight through untouched:

```json
{
  "secret": "shared-fixture-secret-key-32-bytes-min!!",
  "plain_tokens": [
    {
      "name": "exact_claim_shape",
      "input": {
        "user_id": "user-1",
        "ttl_seconds": 600,
        "claims": {
          "perms": ["read", "write"],
          "disks": ["local", "s3"],
          "prefix": "users/1",
          "max_upload": 25,
          "allowed_ext": ["png", "jpg"],
          "max_storage": 100,
          "max_files": 50
        }
      },
      "expect": {
        "sub": "user-1",
        "perms": ["read", "write"],
        "disks": ["local", "s3"],
        "prefix": "users/1",
        "max_upload": 25,
        "allowed_ext": ["png", "jpg"],
        "max_storage": 100,
        "max_files": 50,
        "ttl_seconds": 600
      }
    },
    {
      "name": "defaults_when_bare",
      "input": { "user_id": "u" },
      "expect": {
        "perms": ["read"], "disks": ["local"], "prefix": "",
        "allowed_ext": null, "owner_only_present": false
      }
    }
  ],
  "role_presets": [
    {
      "name": "viewer_perms_early_resolution",
      "input": { "user_id": "u", "role": "viewer" },
      "expect": {
        "perms": ["read"], "owner_only": true,
        "allow_extract": false, "allow_chmod": false
      }
    },
    {
      "name": "editor_perms_early_resolution",
      "input": { "user_id": "u", "role": "editor" },
      "expect": {
        "perms": ["read", "write"], "owner_only": true,
        "allow_extract": true, "allow_chmod": false
      }
    },
    {
      "name": "admin_full_bundle",
      "input": { "user_id": "u", "role": "admin" },
      "expect": {
        "perms": ["read", "write", "delete", "audit"], "owner_only": false,
        "allow_extract": true, "allow_chmod": true,
        "allow_code_edit": true, "show_hidden": true
      }
    },
    {
      "name": "superadmin_identical_to_admin",
      "input": { "user_id": "u", "role": "superadmin" },
      "expect": {
        "perms": ["read", "write", "delete", "audit"], "owner_only": false,
        "allow_extract": true, "allow_chmod": true,
        "allow_code_edit": true, "show_hidden": true
      }
    },
    {
      "name": "explicit_perms_overrides_role",
      "input": { "user_id": "u", "role": "viewer", "claims": { "perms": ["read", "write", "delete"] } },
      "expect": { "perms": ["read", "write", "delete"] }
    },
    {
      "name": "explicit_owner_only_false_overrides_editor",
      "input": { "user_id": "u", "role": "editor", "claims": { "owner_only": false } },
      "expect": { "owner_only_present": false }
    },
    {
      "name": "role_never_touches_scoping_claims",
      "input": {
        "user_id": "scoped-user", "role": "admin",
        "claims": { "disks": ["local"], "prefix": "users/42", "max_upload": 5, "max_storage": 100, "max_files": 10 }
      },
      "expect": {
        "sub": "scoped-user", "disks": ["local"], "prefix": "users/42",
        "max_upload": 5, "max_storage": 100, "max_files": 10
      }
    }
  ],
  "edition_presets": [
    {
      "name": "enterprise_grants_every_module_claim",
      "input": { "user_id": "u", "edition": "enterprise" },
      "expect_true": [
        "allow_optimize", "allow_share", "allow_intake", "allow_versioning",
        "allow_webhooks", "allow_ai_vision", "allow_ocr", "allow_virus_scan",
        "allow_c2pa", "allow_backup", "allow_audit_export",
        "allow_dlp_scan", "allow_legal_hold"
      ]
    },
    {
      "name": "studio_excludes_enterprise_only_claims",
      "input": { "user_id": "u", "edition": "studio" },
      "expect_true": [
        "allow_optimize", "allow_share", "allow_intake",
        "allow_versioning", "allow_webhooks", "allow_ai_vision", "allow_ocr"
      ],
      "expect_absent": [
        "allow_virus_scan", "allow_c2pa", "allow_backup",
        "allow_audit_export", "allow_dlp_scan", "allow_legal_hold"
      ]
    },
    {
      "name": "edition_and_role_compose",
      "input": { "user_id": "u", "edition": "pro", "role": "admin" },
      "expect_true": ["allow_optimize", "allow_share", "allow_intake"],
      "expect": {
        "perms": ["read", "write", "delete", "audit"],
        "allow_chmod": true, "owner_only": false
      }
    }
  ]
}
```

`expect` is a partial-match assertion (only listed keys are checked — a
harness must not fail on an *extra* claim it doesn't recognize, since a
newer core may add claims a fixture predates). `owner_only_present: false`
means "assert the claim is entirely absent from the decoded payload", not
merely falsy — this is the exact distinction the historical bug (§5, item 2)
hinged on, so the fixture format has to be able to state it precisely,
distinct from a plain `false` value which some SDKs represent by omission
and Claims.php then defaults elsewhere. `expect_true`/`expect_absent` are
sugar for the common "these N claims must all be `true`" / "must not be a
key at all" edition-preset assertions.

**`docs/testdata/byob-vectors.json`** — HKDF/AES-GCM known-answer vectors.
Encryption is randomized (fresh nonce per call), so there is nothing to
pin for *encrypt*; what's shareable and deterministic is (a) the HKDF key
derivation in isolation, and (b) **decrypting** a fixed, pre-generated blob:

```json
{
  "hkdf_key_vectors": [
    {
      "name": "basic_secret",
      "secret": "hkdf-known-answer-secret-32-bytes!!",
      "expected_key_hex": "<32-byte hex — generated once, see note below>"
    }
  ],
  "decrypt_vectors": [
    {
      "name": "s3_with_endpoint",
      "secret": "byob-decrypt-vector-secret-32-bytes!!",
      "blob_base64": "<precomputed nonce(12)||ciphertext||tag(16), std base64 — generated once, see note below>",
      "expected_config": {
        "driver": "s3",
        "region": "eu-west-1",
        "bucket": "my-bucket-123",
        "key": "AKIAIOSFODNN7EXAMPLE",
        "secret": "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY",
        "endpoint": "https://s3.custom.endpoint.com"
      }
    },
    {
      "name": "sftp_basic",
      "secret": "byob-decrypt-vector-secret-32-bytes!!",
      "blob_base64": "<precomputed — see note below>",
      "expected_config": {
        "driver": "sftp", "host": "sftp.example.org",
        "username": "deploy", "password": "sekret", "root": "/var/www"
      }
    }
  ]
}
```

**Generation note (one-time, by the implementing coder, not regenerated per
test run):** the `expected_key_hex` and `blob_base64` placeholders above are
*not* hand-derived in this design doc — HKDF/AES-GCM output can't be safely
hand-computed, and a wrong hand-computed value would be worse than no value
(a KAT that's silently wrong from day one defeats its own purpose). The
coder implementing this generates them **once**, from the PHP reference
implementation (the source of truth all three languages must match), e.g.:

```
php -r '
require "packages/core/vendor/autoload.php";
$secret = "hkdf-known-answer-secret-32-bytes!!";
echo bin2hex(hash_hkdf("sha256", $secret, 32, "fluxfiles-byob-enc")), "\n";
'
```

for the HKDF vector, and a similar one-liner calling
`FluxFiles\CredentialEncryptor::encrypt($config, $secret)` once for each
`decrypt_vectors` entry, pasting the resulting base64 string into the JSON
file and **committing it as a fixed value forever after** (never
regenerated — a new nonce each run would defeat the point of a pinned
vector; only add new vectors, never rewrite existing `blob_base64` values in
place).

### 6.2 Python test plan

`tests/test_token.py` (pure Python, no PHP dependency, runs everywhere):
- **Loads `docs/testdata/token-vectors.json`** for every `plain_tokens`,
  `role_presets`, and `edition_presets` entry — mints via
  `create_token(user_id=v["input"]["user_id"], secret=fixture_secret,
  role=v["input"].get("role"), edition=v["input"].get("edition"),
  claims=v["input"].get("claims"))`, decodes, and asserts each `expect`/
  `expect_true`/`expect_absent`/`*_present` field. This replaces what would
  otherwise be Python's own hand-copied version of the role/edition vectors
  already in Node's `token.test.ts` and PHP's `test-role-preset.php`.
- **Full-typed-surface coverage** (kept **inline**, not in the shared
  fixture, per §6.1's scoping note): a parametrized test asserting each of
  the ~80 typed kwargs in §2 lands in its correspondingly-named claim with
  the right JSON type — table-driven (kwarg name, value, expected claim
  key/value) rather than ~80 hand-written asserts.
- `claims` escape hatch precedence beyond what the shared fixture covers
  (e.g. `edition`+explicit `claims` collision) — kept inline since it's
  exercising Python's own merge implementation, not a value the other two
  languages need to agree on beyond what §6.1 already checks.
- **BYOB + role/edition combinations — now loaded from the shared fixture
  too, per §9's decision #6**, since core and Node's `createByobToken()`
  equivalents gain the same role/edition merge, closing the asymmetry an
  earlier draft of this doc flagged. `token-vectors.json` grows a
  `byob_role_presets` group (same shape as `role_presets`, plus a
  `byob_disks` input and an `expect_byob_disks_present: true` field); Python
  mints via `create_byob_token(...)` against it exactly like the plain-token
  vectors. Covers:
  - `create_byob_token(role="editor", byob_disks={...})` decodes with both
    the editor bundle **and** a non-empty `byob_disks` claim.
  - `create_byob_token(edition="enterprise", byob_disks={...})` decodes with
    the full enterprise `allow_*` bundle alongside `byob_disks`.
  - `create_byob_token(role="viewer", byob_disks={...})` with no explicit
    `perms` decodes to `perms == ["read"]` (not BYOB's own bare-default of
    `["read", "write"]`).
  - An explicit `perms=["read", "write", "delete"]` passed alongside
    `role="viewer"` on a BYOB token overrides the role's `perms` — kept
    **inline** (this one asserts explicit-kwarg-wins-over-preset, an
    implementation detail of Python's own merge order, not a cross-language
    value).
- BYOB: `create_byob_token()` rejects a `driver: "local"` config, a S3 config
  missing `bucket`/`key`/`secret`, an SFTP config missing `host`/`username`/
  (`password` or `private_key`).
- `verify_token()` round-trips a token minted by `create_token()`, and raises
  on a tampered signature / expired token. `decode_token()` returns claims
  without needing the right secret.

`tests/test_crypto.py` (pure Python):
- **Loads `docs/testdata/byob-vectors.json`**: `hkdf_key_vectors` verify
  `derive_byob_key(secret) == bytes.fromhex(expected_key_hex)`;
  `decrypt_vectors` verify `decrypt_byob(blob_base64, secret) ==
  expected_config`. These are the release-blocking known-answer checks — a
  wrong salt/info/byte-order fails immediately against PHP-derived ground
  truth, with no PHP install required at Python test time (unlike
  `test_php_compat.py` below, which shells out live).
- `encrypt_byob`/`decrypt_byob` round-trip (fresh nonce each run — not a
  fixture vector, kept inline, matches Node's own inline round-trip test).
- Blob layout / tampered-blob tests, as before (inline — these test Python's
  own slicing code, not a value shared across languages).

`tests/test_php_compat.py` (cross-language, gated, live PHP shell-out —
unchanged in purpose from the earlier draft of this doc, but now also usable
as a live cross-check against the same fixtures rather than its own
one-off vectors):
- A Python-minted plain token decodes correctly via `php -r` calling
  `\FluxFiles\JwtCompat::decode()`.
- A Python-encrypted BYOB blob decrypts via
  `\FluxFiles\CredentialEncryptor::decrypt()`, and vice versa (the reverse
  direction is what actually catches an HKDF salt mistake, per §4.1).
- A Python-minted token with `role="admin"` decodes in PHP with exactly the
  admin bundle (`Claims::fromJwtPayload` applied) — catches drift between
  the fixture/preset table and the server's actual claim
  defaults/sanitization, not just agreement between the three SDKs.
- Release-blocking: do not tag `python-v*` if this suite is skipped in the
  release CI environment.

### 6.3 Migrating the existing PHP and Node suites (in scope now, not Python-only)

This decision changes already-green tests, not just the new Python
addition. Concretely:

- **`packages/node/tests/token.test.ts`**:
  - The `'emits the exact PHP claim shape'` test (lines 8–32) and the
    `'defaults allowed_ext to null and omits owner_only when false'` /
    `'sets owner_only only when requested'` tests (34–45) — migrate to load
    `plain_tokens` vectors from `token-vectors.json` instead of their
    current inline `createToken({...})` literals.
  - The `'enterprise edition preset grants every module claim...'` and
    `'studio edition preset must NOT leak allow_dlp_scan...'` tests
    (47–61) — migrate to `edition_presets` vectors.
  - The entire `describe('role preset (docs/ACL-ROLE-PRESETS-DESIGN.md)', ...)`
    block (lines 317–393: perms-early-resolution, viewer/editor/admin/
    superadmin bundles, explicit-override-wins, edition+role composition,
    role-never-touches-scoping, superadmin-empty-prefix) — migrate to
    `role_presets` vectors wholesale; this block is the closest existing
    analog to what §6.1's fixture now owns.
  - **Not migrated** (stay inline, matching §6.1's scoping note): the
    per-claim-group forwarding tests (`aiAutoTag`/rate-limit/variants
    around line 75, media-preview at 102, usage-dashboard at 119,
    watermark/download/chmod at 138, terminal at 174, Share/Intake claims
    at 181/206, WebP/srcset at 227, URL-import at 246, the secret-length
    rejection at 267) and the `describe('createByobToken', ...)`/
    `describe('verifyToken', ...)`/`describe('BYOB encrypt/decrypt
    round-trip (Node ↔ Node)', ...)` blocks (272–412) — these test Node's
    own typed-kwarg forwarding and Node-only round-trip behavior, not a
    cross-language-shared value.
- **`packages/node/tests/php-compat.test.ts`**:
  - `'a Node-minted token decodes natively in the PHP core'` (lines 40–66)
    — its literal `createToken({...})` input and the asserted claim values
    should be replaced by (or cross-checked against) `token-vectors.json`'s
    `plain_tokens` entries, so the exact same input/expected-claim pair
    Python and PHP's own suite use is what Node proves decodes correctly in
    live PHP too.
  - `'a Node-encrypted BYOB blob decrypts in PHP CredentialEncryptor'` and
    `'a PHP-encrypted BYOB blob decrypts in Node...'` (68–85) — these stay
    **live round-trip** tests (they specifically exercise fresh encryption
    each run, unlike the vector file's fixed `decrypt_vectors`), but should
    additionally assert against a `byob-vectors.json` `decrypt_vectors`
    entry's `expected_config` shape for the disk-config literal used, so
    the same disk config appears in one place conceptually even though the
    live-encrypt behavior itself isn't vector-driven.
- **`packages/core/tests/unit/test-role-preset.php`** — this file is
  *entirely* role/edition vectors today (every `test(...)` block from line
  74 through 258). Migrate it wholesale: loop over
  `token-vectors.json`'s `role_presets`/`edition_presets`/`plain_tokens`
  arrays, mint via `fluxfiles_token(['user' => ..., 'role' => ...,
  'claims' => ...])`, decode via the file's existing `decode()` helper, and
  assert each vector's `expect`/`expect_true`/`expect_absent` — replacing
  the current one-test-per-hardcoded-case structure with a loop over the
  shared JSON. Keep the file's colorized pass/fail harness (`test()`,
  `assertEqual()`) — only the vector *source* changes, not the runner.
- **`packages/core/tests/unit/test-byob.php`**:
  - The `'decrypt returns original config'` test (lines 86–98) uses a fixed
    example config (AKIAIOSFODNN7EXAMPLE / eu-west-1 / custom endpoint) that
    is the natural basis for `byob-vectors.json`'s `s3_with_endpoint`
    `decrypt_vectors` entry — **this is also the test used to generate that
    vector's `blob_base64`** in the one-time generation step (§6.1), so
    after migration this test should additionally load the vector and
    assert `CredentialEncryptor::decrypt($vector['blob_base64'], $vector['secret'])
    === $vector['expected_config']` as a second, fixed-input assertion
    alongside its existing fresh-encrypt round-trip (which stays, since it
    tests nonce-uniqueness/non-determinism — a property a fixed vector can't
    exercise).
  - The BYOB SFTP round-trip test (`'BYOB SFTP: encrypt → decrypt
    round-trips the SFTP config'`, lines 485–489) is the natural basis for
    `byob-vectors.json`'s `sftp_basic` vector, same treatment.
  - **New addition** (didn't exist before in any language): an HKDF
    known-answer test reading `hkdf_key_vectors` and calling
    `CredentialEncryptor`'s key derivation directly — today no test in any
    of the three languages isolates HKDF output from the full
    encrypt/decrypt round-trip. Since `deriveKey()` is currently `private`
    in `CredentialEncryptor.php`, this needs either a small reflection-based
    test helper or a package-visible test-only accessor; **flagged as an
    implementation detail for the coder to resolve, not decided here.**
  - Not migrated: the SSRF-guard tests, `DiskManager` registration tests,
    `fluxfiles_byob_token`/`fluxfiles_mixed_token` TTL/disks-merging tests,
    and the end-to-end flow test (lines 158–437 excluding the two vector
    candidates above) — these test PHP-only server-side validation/wiring,
    not a value shared across languages.

### 6.4 Verification requirement (risk callout)

**This migration touches already-green PHP and Node tests, not just new
Python tests.** Before this is considered done, the coder/tester
implementing it must:

1. Run the **full existing** PHP suite (`for f in packages/core/tests/unit/*.php
   packages/core/tests/integration/*.php; do php "$f"; done`, at minimum
   `test-role-preset.php` and `test-byob.php` specifically) and the **full
   existing** Node suite (`cd packages/node && npx vitest run`, at minimum
   `token.test.ts` and `php-compat.test.ts`) **after** the migration, not
   just the new Python suite — a fixture-loading refactor that silently
   drops an assertion (e.g. by only checking `expect_true` keys and
   forgetting `expect_absent`) would go undetected by a green Python suite
   alone.
2. Confirm the migrated PHP/Node tests still fail correctly when the
   underlying preset tables are deliberately broken (e.g. temporarily
   comment out `allow_chmod: false` in the `viewer` preset locally and
   confirm the migrated test catches it) — a mechanical refactor from
   inline literals to fixture-loading is exactly the kind of change that
   can accidentally turn an assertion into a no-op (e.g. iterating over
   `expect.items()` but only asserting keys that happen to already be
   present, silently skipping a key the fixture intended to check).
3. Diff the assertion **count** before/after per migrated file (e.g. via
   each test runner's own summary line — PHP's `test-role-preset.php`
   already prints "N passed") to catch a fixture load that silently
   iterates zero vectors (e.g. a wrong file path resolved to an empty array
   instead of erroring).

**Not needed (matching Node's own scope):** no browser/e2e tests (no UI
surface at all — see §8), no `tests/unit/test-config-doc.php` changes (this
SDK invents no claims), no changes to `Claims.php`.

## 7. Packaging / publishing

**Tag convention:** `python-v X.Y.Z` (e.g. `python-v0.1.0`), added to the
existing per-package tag family (`core-v*`, `react-v*`, `node-v*`, …) per
CLAUDE.md's release rules. Starts fresh at `0.1.0` (not synced to Node's
current `0.1.27` — these are independent per-package counters, exactly like
`react`/`vue`/`node` today).

**CI workflow, now shipped — `.github/workflows/pypi-publish.yml`** (at design
time there was **zero** Python CI in this repo; `.github/workflows/test.yml`'s
14 jobs were all PHP/JS — this section is kept as the original build plan,
both pieces below now exist as designed: `test.yml`'s `python-token` job and
`pypi-publish.yml`):

1. A **test job** (either a new job in `test.yml` or its own workflow) that
   runs on every push/PR touching `packages/python/**`: `pip install -e
   packages/python[dev]`, `mypy packages/python/src`, `ruff check
   packages/python`, `pytest packages/python/tests` — with the PHP-compat
   tier (§6) run **with** a built `packages/core/vendor/` available (the job
   needs a `composer install -d packages/core` step first, same as the
   existing PHP unit-test jobs already do), so it isn't silently skipped in
   the CI environment.
2. A **publish job**, triggered on `push: tags: ["python-v*"]`, mirroring
   `npm-publish.yml`'s shape:
   - Check the tag matches `pyproject.toml`'s `[project] version` (same
     "tag must match declared package version" guard `npm-publish.yml`
     already enforces for JS packages — read the version via
     `python -c "import tomllib,sys; print(tomllib.load(open('pyproject.toml','rb'))['project']['version'])"`
     or `hatch version` if using the `hatchling` backend, and fail the job
     on mismatch).
   - Build with `python -m build` (produces an sdist + wheel).
   - Publish via **PyPI Trusted Publishing** (OIDC — `pypa/gh-action-pypi-publish@release/v1`
     with `permissions: id-token: write`), **not** a long-lived
     `PYPI_API_TOKEN` secret. This is the modern, recommended PyPI publish
     method (no secret to rotate/leak) and is a strictly better posture than
     `npm-publish.yml`'s current `NPM_TOKEN` secret approach — worth noting
     as a precedent the JS workflow could eventually adopt too (npm has since
     added OIDC trusted publishing as well), but that's out of scope for this
     doc.
   - Idempotency: `gh-action-pypi-publish` has a `skip-existing: true` input
     that swallows a "this version is already on PyPI" conflict specifically
     — mirror `npm-publish.yml`'s care about **only** swallowing that one
     failure class (its own comment: "a bare `|| echo` once hid an expired
     NPM_TOKEN 404 and went green") by NOT blanket-ignoring the publish
     step's exit code; `skip-existing` is scoped exactly to the "already
     published" case.

No `CHANGELOG.md` restructuring needed — the Python package's entries go
into the existing root `CHANGELOG.md` with a `> Released: python-vX.Y.Z` line,
exactly like every other per-package release today.

**`packages/python/pyproject.toml` shape** (illustrative, not final):
```toml
[project]
name = "fluxfiles-token"
version = "0.1.0"
description = "Server-side Python SDK for minting FluxFiles JWTs (plain + BYOB), byte-compatible with the PHP core and @fluxfiles/node"
readme = "README.md"
license = {text = "MIT"}
requires-python = ">=3.10"
dependencies = ["PyJWT>=2.8,<3", "cryptography>=42"]

[project.urls]
Homepage = "https://github.com/thai-pc/fluxfiles#python-server-side-token-sdk"
Repository = "https://github.com/thai-pc/fluxfiles"

[build-system]
requires = ["hatchling"]
build-backend = "hatchling.build"

[tool.hatch.build.targets.wheel]
packages = ["src/fluxfiles_token"]
```

## 8. Non-goals

Restating explicitly, since this is the section most likely to be
mis-scoped later:

- **This package only mints and decodes/verifies tokens**, exactly like
  `@fluxfiles/node`. It does not implement, wrap, or proxy a single
  `/api/fm/*` route.
- **No server-side file-operation logic.** No listing, uploading, disk
  management, quota enforcement, SSRF guarding (beyond the client-side BYOB
  pre-check in §2, which is a convenience, not a security boundary — the
  real SSRF check is `CredentialEncryptor::validate()`, server-side, on
  every decode), rate limiting, or audit logging. All of that stays in
  `packages/core/api/`.
- **No file-manager UI of any kind.** Python has no browser runtime; there
  is nothing here analogous to `packages/sdk/`'s iframe embed or
  `packages/react/`'s `<FluxFiles>` component. A Django/Flask app embedding
  FluxFiles still does so with an `<iframe>` and the plain JS
  `fluxfiles.js`/`@fluxfiles/sdk` in its templates — this package's sole job
  ends at producing the JWT string that iframe/SDK is given.
- **No `postMessage` bridge, no `FM_COMMAND`/`FM_EVENT` handling.** Those are
  iframe↔host-window browser concepts with no Python-side equivalent or need.
- **No new JWT claims, no `docs/CONFIG.md` changes.** This SDK is a pure
  consumer of the existing claim table — see §3's "no new claims" statement.
  If a future FluxFiles feature needs a new claim, that claim gets added
  once to `docs/CONFIG.md`/`Claims.php`, and this SDK's `claims` escape
  hatch picks it up immediately with zero code changes, until a later SDK
  release promotes it to a typed kwarg for parity with Node.

## 9. Decisions (2026-09-08)

The following seven items were open questions in earlier drafts of this doc
and are now final:

1. **API surface** — full typed parity with Node's ~80 `BaseTokenOptions`
   kwargs (§2), snake_case, not the smaller base-plus-dict surface
   originally proposed. `claims` remains only for not-yet-typed claims.
2. **Python floor** — 3.10+, committed (§1), no "revisit later" hedge.
3. **Package name** — `fluxfiles-token` on PyPI, confirmed final (§1),
   referenced as decided (not a proposal) throughout this document.
4. **role/edition on BYOB tokens** — included, following the Laravel/
   WordPress precedent rather than core/Node's original exclusion (§5.1),
   with the exact merge order re-verified against
   `packages/laravel/src/FluxFilesManager.php` and
   `packages/wordpress/includes/FluxFilesPlugin.php`. The asymmetry this
   originally left (core/Node's `createByobToken()` excluding role/edition,
   blocking a shared BYOB+role vector) is resolved by decision #6 below —
   this is no longer deferred.
5. **Shared cross-language test fixtures — full adoption (PHP + Node +
   Python), not Python-only and not status quo.** Superseding an earlier
   draft's decision to keep the precedented hardcoded-per-language style:
   `docs/testdata/token-vectors.json` (role/edition preset + claims-escape-
   hatch vectors) and `docs/testdata/byob-vectors.json` (HKDF/AES-GCM
   known-answer vectors) are now the single source of truth for the
   cross-language-risk parts of all three test suites — see §6.1 for the
   exact format and §6.3 for precisely which existing PHP
   (`test-role-preset.php`, `test-byob.php`) and Node (`token.test.ts`,
   `php-compat.test.ts`) tests migrate to it. §6.4's verification
   requirement (run the full existing PHP+Node suites, not just Python's,
   before considering this done) is binding on whoever implements this.
6. **Close the BYOB+role/edition asymmetry in core and Node too, not just
   Python.** `packages/core/embed.php`'s `fluxfiles_byob_token()` and
   `packages/node/src/token.ts`'s `createByobToken()` gain the exact same
   role/edition merge (§5.1's 8-step order) that Laravel/WordPress and this
   Python SDK already have — this is a behavior *addition*, not a breaking
   change: a `role`/`edition` option passed to either function previously
   had no effect on a BYOB token, so any existing caller either wasn't
   passing it (no change) or was passing it and getting nothing (now gets
   the expected preset applied instead — strictly more useful, not a
   contract broken). No new claim, no new env var — `role`/`edition` already
   exist as mint-time-only options on the plain-token path in both. Once
   done, a BYOB+role/edition vector belongs in the shared
   `docs/testdata/token-vectors.json` alongside the plain-token vectors
   (§6.1), and Python's inline-only BYOB+role tests (§6.2) fold into that
   shared file too — no per-vector "applicable languages" flag needed after
   all, since every mint-side language now supports the combination. This
   also makes core and Node first-class references for the BYOB+role merge
   order, not just Laravel/WordPress. Scope note: this does NOT touch the
   `disks`-merging divergence already described in §5.1 step 2
   (Laravel/WordPress merge server-config disks with BYOB disk names;
   core/Node/Python don't, since none of the three carry a server-side
   config store) — that stays as-is, only the role/edition preset mechanics
   are unified.

## Claims to add to `docs/CONFIG.md`

**None.** This SDK introduces zero new claims, zero new claim defaults, and
zero new env vars — it is a pure client of the existing table (§2's "How to
set claims" section already documents PHP/Node/Laravel/WordPress as the
minting surfaces; the only change needed there, if this ships, is adding
"Python (`fluxfiles-token`)" as a fifth bullet alongside the existing four,
with a matching `create_token(claims={...})` one-liner — not a new table row).
