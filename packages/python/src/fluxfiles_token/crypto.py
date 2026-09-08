"""BYOB credential encryption — matches PHP `CredentialEncryptor` and
`packages/node/src/crypto.ts` byte-for-byte.

AES-256-GCM. Key = HKDF-SHA256(ikm = secret, salt = 32 zero bytes,
info = "fluxfiles-byob-enc", len = 32). Blob = base64(nonce[12] | ct | tag[16]).
"""

import base64
import json
import os
from typing import Any

from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.kdf.hkdf import HKDF

from .exceptions import FluxFilesByobError

_BYOB_INFO = b"fluxfiles-byob-enc"
_NONCE_LEN = 12
_TAG_LEN = 16
_KEY_LEN = 32


def derive_byob_key(secret: str) -> bytes:
    """Derive the BYOB key exactly like PHP `hash_hkdf('sha256', $secret, 32, $info)`.

    PHP's empty HKDF salt means "HashLen zero bytes" (RFC 5869), so we pass an
    explicit 32-byte zero salt. `cryptography`'s HKDF already defaults an
    omitted salt to the same RFC-5869-correct value, but we pin it explicitly
    anyway so this stays self-documenting and immune to any future change in
    that default — this single line is the crux of cross-language parity.
    """
    hkdf = HKDF(algorithm=hashes.SHA256(), length=_KEY_LEN, salt=b"\x00" * 32, info=_BYOB_INFO)
    return hkdf.derive(secret.encode("utf-8"))


def encrypt_byob(config: dict[str, Any], secret: str) -> str:
    """Encrypt a disk config into a base64 blob: nonce(12) || ciphertext || tag(16)."""
    key = derive_byob_key(secret)
    nonce = os.urandom(_NONCE_LEN)
    aesgcm = AESGCM(key)
    # json.dumps never escapes "/", matching PHP's JSON_UNESCAPED_SLASHES.
    plaintext = json.dumps(config, ensure_ascii=False).encode("utf-8")
    # AESGCM.encrypt() returns ciphertext || tag already concatenated (tag is
    # always the trailing 16 bytes) — no manual tag-splicing needed.
    ct_and_tag = aesgcm.encrypt(nonce, plaintext, None)
    return base64.b64encode(nonce + ct_and_tag).decode("ascii")


def decrypt_byob(blob: str, secret: str) -> dict[str, Any]:
    """Decrypt a base64 blob back into a disk config dict. Raises
    FluxFilesByobError on a malformed blob, wrong secret, or tampered data."""
    key = derive_byob_key(secret)
    try:
        raw = base64.b64decode(blob, validate=True)
    except Exception as exc:
        raise FluxFilesByobError("FluxFiles: invalid BYOB credential blob") from exc

    if len(raw) < _NONCE_LEN + _TAG_LEN + 1:
        raise FluxFilesByobError("FluxFiles: invalid BYOB credential blob")

    nonce = raw[:_NONCE_LEN]
    ciphertext_and_tag = raw[_NONCE_LEN:]
    aesgcm = AESGCM(key)
    try:
        # AESGCM.decrypt() expects ciphertext || tag concatenated — exactly the
        # wire format above, so no re-slicing beyond splitting off the nonce.
        plaintext = aesgcm.decrypt(nonce, ciphertext_and_tag, None)
    except Exception as exc:
        raise FluxFilesByobError(
            "FluxFiles: BYOB credential decryption failed — token may be tampered"
        ) from exc

    config = json.loads(plaintext.decode("utf-8"))
    if not isinstance(config, dict):
        raise FluxFilesByobError("FluxFiles: invalid BYOB credential format")
    return config
