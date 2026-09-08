# FluxFiles for Python (server-side token SDK)

Mint [FluxFiles](https://github.com/thai-pc/fluxfiles) JWTs from any Python
backend (Django, FastAPI, Flask, …). Tokens are **byte-compatible with the PHP
core**, so non-PHP apps can issue access tokens — including encrypted BYOB
(Bring Your Own Bucket) credentials — without running PHP.

> **This package only issues tokens — it is not a backend.** You still run a
> FluxFiles **core service** (the file-manager backend that talks to storage;
> a PHP app, e.g. the Docker image) for the token to authenticate against.
> `fluxfiles-token` simply removes the need for *your app* to be PHP in order
> to mint those tokens.

## Requirements

- Python 3.10+
- A running FluxFiles **core service** the issued tokens authenticate against
  (the SDK/iframe `endpoint` points at it).
- The same **`FLUXFILES_SECRET`** your FluxFiles core server uses to verify
  tokens (HS256, **must be ≥ 32 bytes**). Keep it server-side only.

## Installation

```bash
pip install fluxfiles-token
```

## Usage

### Mint a token

```python
from fluxfiles_token import create_token

token = create_token(
    user_id="user-42",
    perms=["read", "write"],
    disks=["local", "s3"],
    prefix="users/42",       # scope the user to their own directory
    max_upload_mb=25,
    allowed_ext=["png", "jpg", "pdf"],
    ttl=3600,                # seconds
    # secret=...,            # or omit to read FLUXFILES_SECRET from the environment
)
```

### Role & edition presets

Mint-time-only DX sugar — they expand into ordinary claims and are never
themselves a JWT claim. Explicit kwargs always win over a preset default.

```python
from fluxfiles_token import create_token

# perms=["read", "write"], owner_only=True, allow_extract=True,
# allow_chmod=False (the "editor" bundle).
token = create_token(user_id="user-42", role="editor")

# Every module claim the "enterprise" edition grants — license/module
# install are still the real gate; a preset just defaults the per-tenant claim.
token = create_token(user_id="tenant-9", edition="enterprise")
```

### Enable Import from URL

Import-from-URL is **off by default**. Turn it on for a token by setting the
import options — no server-side per-tenant config is needed:

```python
token = create_token(
    user_id="user-42",
    perms=["read", "write"],
    allow_url_import=True,                     # required — enables the feature
    max_import_mb=20,                          # optional — cap per import (MB)
    import_url_allowlist=["*.unsplash.com"],   # optional — restrict source hosts
    # import_path, import_rate_limit, import_concurrency also supported
)
```

The core then accepts `POST /api/fm/import-url` for that token (SSRF-guarded,
sharing the quota/dedup/variants pipeline). Server-wide defaults come from
`FLUXFILES_IMPORT_*` env vars on the core service.

### SFTP disk: chmod & SSH terminal

When the token targets a FluxFiles server that has an **SFTP disk** configured
(`SFTP_*` env on that server — see the
[core README](https://github.com/thai-pc/fluxfiles#sftp-disk-vps--shared-hosting)),
you can hand a token the SFTP file-manager tools. `chmod` is on by default for
SFTP; the **SSH terminal** is opt-in (it grants shell access as the SSH user):

```python
token = create_token(
    user_id="admin-7",
    perms=["read", "write"],
    disks=["sftp"],           # an SFTP disk configured on the FluxFiles server
    allow_chmod=True,         # cPanel-style permissions (default on for SFTP)
    allow_terminal=True,      # SSH terminal — opt-in, off by default
)
```

These are **standalone-core** features: `fluxfiles-token` mints for a real
FluxFiles server (Docker / standalone), which serves them — they aren't
available behind the WordPress / Laravel-proxy adapters.

### BYOB — encrypt a user's own bucket credentials

```python
import os
from fluxfiles_token import create_byob_token

token = create_byob_token(
    user_id="user-42",
    byob_disks={
        "my-s3": {
            "driver": "s3",
            "key": os.environ["USER_AWS_KEY"],
            "secret": os.environ["USER_AWS_SECRET"],
            "bucket": "user-personal-bucket",
            "region": "us-east-1",
            # "endpoint": "https://<acct>.r2.cloudflarestorage.com",  # R2/MinIO/Spaces
        },
    },
)
```

Credentials are AES-256-GCM encrypted into the token and decrypted only at
runtime by the FluxFiles server (which also re-validates the endpoint for
SSRF). `role`/`edition` presets are supported on BYOB tokens too.

### Verify / decode (optional)

```python
from fluxfiles_token import verify_token, decode_token

claims = verify_token(token)   # checks HS256 signature + expiry, raises on failure
peek = decode_token(token)     # decode only, NO verification — never trust for auth
```

### Django view

```python
from django.http import JsonResponse
from fluxfiles_token import create_token

def fluxfiles_token_view(request):
    token = create_token(
        user_id=str(request.user.id),
        perms=["read", "write"],
        prefix=f"users/{request.user.id}",
    )
    return JsonResponse({"token": token})
```

### FastAPI route

```python
from fastapi import APIRouter, Depends
from fluxfiles_token import create_token

router = APIRouter()

@router.get("/fluxfiles/token")
def fluxfiles_token(user=Depends(get_current_user)):
    return {"token": create_token(user_id=user.id, perms=["read", "write"])}
```

## API

| Function | Description |
|----------|-------------|
| `create_token(**opts)` | Standard token. Mirrors PHP `fluxfiles_token()`. |
| `create_byob_token(**opts)` | Token with encrypted BYOB disk credentials. Mirrors `fluxfiles_byob_token()`. |
| `verify_token(token, secret=None)` | Verify HS256 signature + expiry; returns decoded claims or raises. |
| `decode_token(token)` | Decode without verifying (inspection/logging only). |

`create_token` keyword arguments: `secret=None`, `user_id`, `perms=None`,
`disks=None`, `prefix=None`, `max_upload_mb=None`, `allowed_ext=None`,
`ttl=None`, `owner_only=None`, `max_storage_mb=None`, `max_files=None`,
`edition=None`, `role=None`. Per-tenant overrides (omit to inherit the server
default): `ai_auto_tag=None` (bool), `rate_read=None` / `rate_write=None`
(req/min), `variants=None` (`{"thumb": .., "medium": .., "large": ..}` px),
and every other `allow_*`/module claim documented in
[`docs/CONFIG.md`](https://github.com/thai-pc/fluxfiles/blob/master/docs/CONFIG.md) —
plus a `claims: dict` escape hatch for any claim by its raw snake_case name.
`create_byob_token` replaces `disks` with `byob_disks` (a map of name →
S3-compatible/SFTP config) and does not take `max_storage_mb`/`max_files`
(matching the core), but accepts the same `role`/`edition`/per-tenant kwargs.

## Compatibility

Tokens and BYOB blobs are validated against the PHP core in CI: a
Python-minted token decodes in `JwtCompat::decode`, and BYOB credentials
round-trip both ways through `CredentialEncryptor` (HS256 + HKDF-SHA256 +
AES-256-GCM). Always mint tokens **on the server** — never ship
`FLUXFILES_SECRET` to the browser.

## License

MIT — see [LICENSE](LICENSE) for details.

## Links

- Main repository: `https://github.com/thai-pc/fluxfiles`
- Documentation: `https://github.com/thai-pc/fluxfiles#python-server-side-token-sdk`
- Issues: `https://github.com/thai-pc/fluxfiles/issues`
