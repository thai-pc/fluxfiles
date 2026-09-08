"""Cross-language parity: tokens minted in Python must decode in the PHP core,
and BYOB blobs must round-trip both ways (this is the real guard for the HKDF
salt / AES-GCM / JWT compatibility). Skips cleanly when PHP or the core vendor
autoloader isn't available (e.g. a Python-only CI lane) — but release CI must
run it for real; see docs/PYTHON-TOKEN-SDK-DESIGN.md §6.2/§6.4.
"""

import json
import shutil
import subprocess
from pathlib import Path
from typing import Any

import pytest

from fluxfiles_token import create_byob_token, create_token, decode_token

SECRET = "php-compat-secret-key-at-least-32-bytes!!"

REPO_ROOT = Path(__file__).resolve().parents[3]
AUTOLOAD = REPO_ROOT / "packages" / "core" / "vendor" / "autoload.php"

TOKEN_VECTORS_PATH = REPO_ROOT / "docs" / "testdata" / "token-vectors.json"
BYOB_VECTORS_PATH = REPO_ROOT / "docs" / "testdata" / "byob-vectors.json"
TOKEN_VECTORS: dict[str, Any] = json.loads(TOKEN_VECTORS_PATH.read_text())
BYOB_VECTORS: dict[str, Any] = json.loads(BYOB_VECTORS_PATH.read_text())
S3_VECTOR_CONFIG = next(
    v for v in BYOB_VECTORS["decrypt_vectors"] if v["name"] == "s3_with_endpoint"
)["expected_config"]

PHP_AVAILABLE = shutil.which("php") is not None
ENABLED = PHP_AVAILABLE and AUTOLOAD.exists()

pytestmark = pytest.mark.skipif(
    not ENABLED, reason="php-compat: skipped (php or core vendor autoload missing)"
)


def _php(code: str, env: dict[str, str]) -> str:
    """Run a tiny PHP script with the core autoloader; data passed via env."""
    result = subprocess.run(
        ["php", "-r", f"require getenv('FF_AUTOLOAD');\n{code}"],
        capture_output=True,
        text=True,
        env={**__import__("os").environ, "FF_AUTOLOAD": str(AUTOLOAD), "FF_SECRET": SECRET, **env},
        check=True,
    )
    return result.stdout.strip()


def test_python_minted_token_decodes_natively_in_php() -> None:
    vector = next(v for v in TOKEN_VECTORS["plain_tokens"] if v["name"] == "exact_claim_shape")
    inp = vector["input"]
    token = create_token(secret=SECRET, user_id=inp["user_id"], ttl=inp["ttl_seconds"], claims=inp["claims"])
    out = _php(
        r"echo json_encode(\FluxFiles\JwtCompat::decode(getenv('FF_TOKEN'), getenv('FF_SECRET')));",
        {"FF_TOKEN": token},
    )
    c = json.loads(out)
    for key, expected in vector["expect"].items():
        if key == "ttl_seconds":
            assert c["exp"] - c["iat"] == expected
            continue
        assert c[key] == expected


def test_python_minted_admin_role_token_decodes_with_full_bundle_in_php() -> None:
    """Catches drift between the fixture/preset table and the server's actual
    claim defaults/sanitization (Claims::fromJwtPayload), not just agreement
    between the SDKs."""
    vector = next(v for v in TOKEN_VECTORS["role_presets"] if v["name"] == "admin_full_bundle")
    token = create_token(secret=SECRET, user_id="u", role="admin")
    out = _php(
        r"echo json_encode(\FluxFiles\Claims::fromJwtPayload("
        r"\FluxFiles\JwtCompat::decode(getenv('FF_TOKEN'), getenv('FF_SECRET'))));",
        {"FF_TOKEN": token},
    )
    c = json.loads(out)
    expect = vector["expect"]
    # "owner_only_present" asserts effective presence (isset AND === true) in the
    # shared fixture; for a claim whose decode-side default is `false` when absent
    # (owner_only, unlike allow_extract/allow_chmod), that's identical to the
    # effective Claims::fromJwtPayload()->ownerOnly boolean.
    assert c["ownerOnly"] is expect["owner_only_present"]
    assert sorted(c["permissions"]) == sorted(expect["perms"])


def test_python_encrypted_byob_blob_decrypts_in_php() -> None:
    token = create_byob_token(secret=SECRET, user_id="u", byob_disks={"my-s3": S3_VECTOR_CONFIG})
    blob = decode_token(token)["byob_disks"]["my-s3"]
    out = _php(
        r"echo json_encode(\FluxFiles\CredentialEncryptor::decrypt(getenv('FF_BLOB'), getenv('FF_SECRET')));",
        {"FF_BLOB": blob},
    )
    assert json.loads(out) == S3_VECTOR_CONFIG


def test_php_encrypted_byob_blob_decrypts_in_python() -> None:
    """The reverse direction is what actually catches an HKDF salt mistake."""
    from fluxfiles_token import decrypt_byob

    blob = _php(
        r"echo \FluxFiles\CredentialEncryptor::encrypt("
        r"json_decode(getenv('FF_CONFIG'), true), getenv('FF_SECRET'));",
        {"FF_CONFIG": json.dumps(S3_VECTOR_CONFIG)},
    )
    assert decrypt_byob(blob, SECRET) == S3_VECTOR_CONFIG
