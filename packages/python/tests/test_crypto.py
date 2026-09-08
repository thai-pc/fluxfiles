"""AES-256-GCM / HKDF-SHA256 byte-layout tests for crypto.py.

Loads the shared cross-language fixture (docs/testdata/byob-vectors.json), per
docs/PYTHON-TOKEN-SDK-DESIGN.md §6.2 — these are the release-blocking
known-answer checks: a wrong salt/info/byte-order fails immediately against
PHP-derived ground truth, with no PHP install required at Python test time
(unlike test_php_compat.py, which shells out live).
"""

import base64
import json
from pathlib import Path
from typing import Any

import pytest

from fluxfiles_token import FluxFilesByobError, decrypt_byob, encrypt_byob
from fluxfiles_token.crypto import derive_byob_key

VECTORS_PATH = Path(__file__).resolve().parents[3] / "docs" / "testdata" / "byob-vectors.json"
VECTORS: dict[str, Any] = json.loads(VECTORS_PATH.read_text())


@pytest.mark.parametrize("v", VECTORS["hkdf_key_vectors"], ids=lambda v: v["name"])
def test_hkdf_known_answer(v: dict[str, Any]) -> None:
    assert derive_byob_key(v["secret"]) == bytes.fromhex(v["expected_key_hex"])


@pytest.mark.parametrize("v", VECTORS["decrypt_vectors"], ids=lambda v: v["name"])
def test_decrypt_known_answer(v: dict[str, Any]) -> None:
    assert decrypt_byob(v["blob_base64"], v["secret"]) == v["expected_config"]


def test_encrypt_decrypt_round_trip() -> None:
    secret = "round-trip-secret-key-32-bytes-min!!"
    cfg = {"driver": "s3", "key": "AK", "secret": "SK", "bucket": "b", "region": "eu-west-1"}
    blob = encrypt_byob(cfg, secret)
    assert decrypt_byob(blob, secret) == cfg


def test_encrypt_uses_a_fresh_nonce_each_call() -> None:
    secret = "round-trip-secret-key-32-bytes-min!!"
    cfg = {"driver": "s3", "key": "AK", "secret": "SK", "bucket": "b", "region": "eu-west-1"}
    assert encrypt_byob(cfg, secret) != encrypt_byob(cfg, secret)


def test_decrypt_rejects_malformed_blob() -> None:
    secret = "round-trip-secret-key-32-bytes-min!!"
    with pytest.raises(FluxFilesByobError):
        decrypt_byob("not-valid-base64!!!", secret)
    with pytest.raises(FluxFilesByobError):
        decrypt_byob(base64.b64encode(b"too-short").decode("ascii"), secret)


def test_decrypt_rejects_tampered_ciphertext() -> None:
    secret = "round-trip-secret-key-32-bytes-min!!"
    cfg = {"driver": "s3", "key": "AK", "secret": "SK", "bucket": "b", "region": "eu-west-1"}
    blob = encrypt_byob(cfg, secret)
    raw = bytearray(base64.b64decode(blob))
    raw[-1] ^= 0xFF  # flip a byte in the GCM tag
    tampered = base64.b64encode(bytes(raw)).decode("ascii")
    with pytest.raises(FluxFilesByobError):
        decrypt_byob(tampered, secret)


def test_decrypt_rejects_wrong_secret() -> None:
    cfg = {"driver": "s3", "key": "AK", "secret": "SK", "bucket": "b", "region": "eu-west-1"}
    blob = encrypt_byob(cfg, "secret-one-that-is-32-bytes-min!!")
    with pytest.raises(FluxFilesByobError):
        decrypt_byob(blob, "secret-two-that-is-32-bytes-min!!")


def test_decrypt_rejects_non_object_payload() -> None:
    """A blob that decrypts fine but whose plaintext JSON isn't an object."""
    import os

    from cryptography.hazmat.primitives.ciphers.aead import AESGCM

    secret = "round-trip-secret-key-32-bytes-min!!"
    key = derive_byob_key(secret)
    nonce = os.urandom(12)
    ct = AESGCM(key).encrypt(nonce, json.dumps([1, 2, 3]).encode("utf-8"), None)
    blob = base64.b64encode(nonce + ct).decode("ascii")
    with pytest.raises(FluxFilesByobError):
        decrypt_byob(blob, secret)
