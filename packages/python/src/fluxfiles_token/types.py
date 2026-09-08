"""Typed shapes for FluxFiles tokens: BYOB disk configs and the decoded claims
payload. Mirrors packages/node/src/types.ts 1:1 (camelCase -> snake_case)."""

from typing import Literal, TypedDict

# A FluxFiles permission. `audit` gates reading the activity log.
FluxPermission = Literal["read", "write", "delete", "audit"]


class _ByobS3DiskConfigRequired(TypedDict):
    driver: Literal["s3"]
    key: str
    secret: str
    bucket: str


class ByobS3DiskConfig(_ByobS3DiskConfigRequired, total=False):
    """A BYOB (Bring Your Own Bucket) S3-compatible disk config. Encrypted into
    the JWT and decrypted only at runtime by the FluxFiles server. Only
    S3-compatible storage is allowed — the server rejects the `local` driver.
    """

    region: str
    # Custom S3 endpoint (R2, MinIO, Spaces, …). Omit for native AWS S3.
    endpoint: str
    visibility: Literal["private", "public"]
    # Public base URL for direct (unsigned) object links on a public disk.
    public_url: str


class _ByobSftpDiskConfigRequired(TypedDict):
    driver: Literal["sftp"]
    host: str
    username: str


class ByobSftpDiskConfig(_ByobSftpDiskConfigRequired, total=False):
    """A BYOB SFTP disk — a user's own SFTP server (e.g. a VPS). Auth is a
    password OR a private key. The server SSRF-checks the host (no
    loopback/private/metadata targets). SFTP files are streamed through the
    app (no static/presigned URL).
    """

    password: str
    private_key: str
    private_key_passphrase: str
    port: int
    root: str


ByobDiskConfig = ByobS3DiskConfig | ByobSftpDiskConfig


class FluxClaims(TypedDict, total=False):
    """Decoded JWT payload (snake_case, as emitted by the PHP core). `total=False`
    since every claim beyond `sub`/`iat`/`exp`/`jti` is conditionally present —
    and a newer core may emit claims this SDK predates."""

    sub: str
    iat: int
    exp: int
    jti: str
    perms: list[str]
    disks: list[str]
    prefix: str
    max_upload: int
    allowed_ext: list[str] | None
    max_storage: int
    max_files: int
    owner_only: bool
    byob_disks: dict[str, str]
