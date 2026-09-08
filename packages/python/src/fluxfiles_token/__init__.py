"""fluxfiles-token: mint and verify FluxFiles JWTs from Python.

Byte-compatible with the PHP core (`packages/core/embed.php`,
`CredentialEncryptor.php`) and a 1:1 port of `@fluxfiles/node`
(camelCase -> snake_case). See docs/PYTHON-TOKEN-SDK-DESIGN.md.
"""

from .crypto import decrypt_byob, encrypt_byob
from .exceptions import FluxFilesByobError, FluxFilesTokenError
from .token import EDITION_PRESETS, ROLE_PRESETS, create_byob_token, create_token
from .types import (
    ByobDiskConfig,
    ByobS3DiskConfig,
    ByobSftpDiskConfig,
    FluxClaims,
    FluxPermission,
)
from .verify import decode_token, verify_token

__all__ = [
    "create_token",
    "create_byob_token",
    "verify_token",
    "decode_token",
    "encrypt_byob",
    "decrypt_byob",
    "ROLE_PRESETS",
    "EDITION_PRESETS",
    "FluxFilesTokenError",
    "FluxFilesByobError",
    "ByobDiskConfig",
    "ByobS3DiskConfig",
    "ByobSftpDiskConfig",
    "FluxClaims",
    "FluxPermission",
]

__version__ = "0.1.0"
