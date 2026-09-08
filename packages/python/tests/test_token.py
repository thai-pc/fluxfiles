"""Unit tests for create_token()/create_byob_token()/verify_token()/decode_token().

Loads the shared cross-language fixtures (docs/testdata/token-vectors.json) for
plain_tokens/role_presets/edition_presets/byob_role_presets, per
docs/PYTHON-TOKEN-SDK-DESIGN.md §6.2 — the same vectors Node's token.test.ts and
PHP's test-role-preset.php/test-byob.php load, so all three mint the exact same
cases instead of hand-copying them per language.
"""

import json
from pathlib import Path
from typing import Any

import jwt
import pytest

from fluxfiles_token import (
    FluxFilesByobError,
    FluxFilesTokenError,
    create_byob_token,
    create_token,
    decode_token,
    decrypt_byob,
    verify_token,
)

SECRET = "test-secret-key-that-is-at-least-32-bytes-long"

VECTORS_PATH = Path(__file__).resolve().parents[3] / "docs" / "testdata" / "token-vectors.json"
VECTORS: dict[str, Any] = json.loads(VECTORS_PATH.read_text())


def _assert_vector(v: dict[str, Any]) -> None:
    """Mint a vector's `input` via create_token() and assert its expect/expect_true/
    expect_absent fields against the decoded claims. `<claim>_present` asserts
    effective presence (is True), not literal key presence — matches the Node/PHP
    harnesses, since "absent" and "present-but-false" both mean "not enabled" for
    every boolean claim these fixtures cover."""
    inp = v["input"]
    token = create_token(
        secret=SECRET,
        user_id=inp["user_id"],
        role=inp.get("role"),
        edition=inp.get("edition"),
        ttl=inp.get("ttl_seconds"),
        claims=inp.get("claims"),
    )
    c = decode_token(token)

    for key, expected in v.get("expect", {}).items():
        if key.endswith("_present"):
            claim = key[: -len("_present")]
            assert (c.get(claim) is True) == expected
            continue
        if key == "ttl_seconds":
            assert c["exp"] - c["iat"] == expected
            continue
        # Raw comparison — a genuinely absent claim is `None`, NOT coerced to
        # `False`. Coercing here would make an omitted key indistinguishable
        # from an explicit `False`, which is exactly the historical B1 bug
        # (allow_extract/allow_chmod default to TRUE when absent) — mirrors
        # _assert_byob_role_vector below, which never coerced.
        assert c.get(key) == expected

    for claim in v.get("expect_true", []):
        assert c.get(claim) is True
    for claim in v.get("expect_absent", []):
        assert claim not in c


@pytest.mark.parametrize("v", VECTORS["plain_tokens"], ids=lambda v: v["name"])
def test_plain_token_vectors(v: dict[str, Any]) -> None:
    _assert_vector(v)


@pytest.mark.parametrize("v", VECTORS["role_presets"], ids=lambda v: v["name"])
def test_role_preset_vectors(v: dict[str, Any]) -> None:
    _assert_vector(v)


@pytest.mark.parametrize("v", VECTORS["edition_presets"], ids=lambda v: v["name"])
def test_edition_preset_vectors(v: dict[str, Any]) -> None:
    _assert_vector(v)


def _assert_byob_role_vector(v: dict[str, Any]) -> None:
    inp = v["input"]
    token = create_byob_token(
        secret=SECRET,
        user_id=inp["user_id"],
        byob_disks=inp["byob_disks"],
        role=inp.get("role"),
        edition=inp.get("edition"),
    )
    c = decode_token(token)

    for key, expected in v.get("expect", {}).items():
        assert c.get(key) == expected
    for claim in v.get("expect_true", []):
        assert c.get(claim) is True
    if v.get("expect_byob_disks_present"):
        byob_disks = c.get("byob_disks") or {}
        assert len(byob_disks) > 0
        # Round-trip: each disk's blob must decrypt back to its original config.
        for name, blob in byob_disks.items():
            assert decrypt_byob(blob, SECRET) == inp["byob_disks"][name]


@pytest.mark.parametrize("v", VECTORS["byob_role_presets"], ids=lambda v: v["name"])
def test_byob_role_preset_vectors(v: dict[str, Any]) -> None:
    _assert_byob_role_vector(v)


def test_byob_explicit_perms_overrides_role_viewer() -> None:
    """Kept inline (not in the shared fixture, per §6.2's scoping note): asserts
    Python's own explicit-kwarg-overrides-preset merge order, not a
    cross-language value."""
    token = create_byob_token(
        secret=SECRET,
        user_id="u",
        role="viewer",
        perms=["read", "write", "delete"],
        byob_disks={
            "my-s3": {"driver": "s3", "key": "AK", "secret": "SK", "bucket": "b", "region": "us-east-1"}
        },
    )
    c = decode_token(token)
    assert c["perms"] == ["read", "write", "delete"]


def test_claims_escape_hatch_sets_any_raw_claim_and_explicit_wins() -> None:
    token = create_token(
        secret=SECRET,
        user_id="u",
        edition="pro",
        claims={
            "allow_terminal": True,
            "terminal_pty_url": "https://t.example.com/",
            "upload_collision": "overwrite",
            "allow_optimize": False,
        },
    )
    c = decode_token(token)
    assert c["allow_terminal"] is True
    assert c["terminal_pty_url"] == "https://t.example.com/"
    assert c["upload_collision"] == "overwrite"
    # explicit claim overrides the edition preset (pro defaults allow_optimize True)
    assert c["allow_optimize"] is False


def test_emits_per_tenant_overrides_and_sanitizes_variants() -> None:
    token = create_token(
        secret=SECRET,
        user_id="u",
        ai_auto_tag=True,
        rate_read=120,
        rate_write=30,
        variants={"thumb": 64, "medium": 1024, "large": 99999},  # 99999 out of range -> dropped
    )
    c = decode_token(token)
    assert c["ai_auto_tag"] is True
    assert c["rate_read"] == 120
    assert c["rate_write"] == 30
    assert c["variants"] == {"thumb": 64, "medium": 1024}


def test_omits_per_tenant_overrides_when_unset() -> None:
    c = decode_token(create_token(secret=SECRET, user_id="u"))
    assert "ai_auto_tag" not in c
    assert "rate_read" not in c
    assert "rate_write" not in c
    assert "variants" not in c
    assert "allow_url_import" not in c
    assert "max_import_mb" not in c


# --- Full-typed-surface coverage: table-driven, one row per typed kwarg group. ---
# Kept inline (not in the shared fixture, per §6.1's scoping note): these exercise
# every one of the ~80 typed kwargs, not a value the other languages need to agree on.
_TYPED_KWARG_CASES: list[tuple[dict[str, Any], dict[str, Any]]] = [
    ({"media_preview": False, "preview_url_ttl": 7200, "max_preview_mb": 250, "stream_token_ttl": 1800},
     {"media_preview": False, "preview_url_ttl": 7200, "max_preview_mb": 250, "stream_token_ttl": 1800}),
    ({"usage_cache_ttl": 600, "usage_warning_threshold": 60, "usage_critical_threshold": 85,
      "usage_top_folders_count": 5, "usage_folder_depth": 2},
     {"usage_cache_ttl": 600, "usage_warning_threshold": 60, "usage_critical_threshold": 85,
      "usage_top_folders_count": 5, "usage_folder_depth": 2}),
    ({"allow_download": False, "allow_chmod": False, "allow_code_edit": True, "allow_optimize": True,
      "allow_zip": False, "allow_extract": False, "zip_max_mb": 50, "zip_max_files": 7,
      "watermark_enabled": True, "watermark_type": "text", "watermark_text": "© Acme",
      "watermark_position": "center", "watermark_opacity": 0.5, "watermark_font_size": 20},
     {"allow_download": False, "allow_chmod": False, "allow_code_edit": True, "allow_optimize": True,
      "allow_zip": False, "allow_extract": False, "zip_max_mb": 50, "zip_max_files": 7,
      "watermark_enabled": True, "watermark_type": "text", "watermark_text": "© Acme",
      "watermark_position": "center", "watermark_opacity": 0.5, "watermark_font_size": 20}),
    ({"allow_terminal": True}, {"allow_terminal": True}),
    ({"allow_share": True, "share_url_ttl": 120, "share_base_url": "https://files.acme.com/public/share.html",
      "share_preview": False, "share_analytics": True},
     {"allow_share": True, "share_url_ttl": 120, "share_base_url": "https://files.acme.com/public/share.html",
      "share_preview": False, "share_analytics": True}),
    ({"allow_intake": True, "intake_base_url": "https://files.acme.com/public/intake.html"},
     {"allow_intake": True, "intake_base_url": "https://files.acme.com/public/intake.html"}),
    ({"webp_enabled": False, "webp_max_width": 1600, "webp_default_quality": 75,
      "srcset_widths": [400, 1200], "srcset_sizes": "100vw"},
     {"webp_enabled": False, "webp_max_width": 1600, "webp_default_quality": 75,
      "srcset_widths": [400, 1200], "srcset_sizes": "100vw"}),
    ({"allow_url_import": True, "max_import_mb": 20, "import_url_allowlist": ["*.unsplash.com"],
      "import_path": "imports", "import_rate_limit": 5, "import_concurrency": 2},
     {"allow_url_import": True, "max_import_mb": 20, "import_url_allowlist": ["*.unsplash.com"],
      "import_path": "imports", "import_rate_limit": 5, "import_concurrency": 2}),
    ({"auto_optimize": True, "optimize_quality": 80, "optimize_keep_original": True,
      "optimize_max_mb": 15, "pdf_level": "ebook"},
     {"auto_optimize": True, "optimize_quality": 80, "optimize_keep_original": True,
      "optimize_max_mb": 15, "pdf_level": "ebook"}),
    ({"upload_collision": "reject", "show_hidden": True, "dedupe_uploads": True},
     {"upload_collision": "reject", "show_hidden": True, "dedupe_uploads": True}),
    ({"allow_versioning": True, "versioning_max": 10, "versioning_max_mb": 200},
     {"allow_versioning": True, "versioning_max": 10, "versioning_max_mb": 200}),
    ({"allow_webhooks": True, "webhook_url": "https://hooks.example.com/x", "webhook_events": ["upload"],
      "webhook_secret": "whsec"},
     {"allow_webhooks": True, "webhook_url": "https://hooks.example.com/x", "webhook_events": ["upload"],
      "webhook_secret": "whsec"}),
    ({"allow_ai_vision": True, "allow_ocr": True, "allow_virus_scan": True, "allow_backup": True,
      "allow_c2pa": True},
     {"allow_ai_vision": True, "allow_ocr": True, "allow_virus_scan": True, "allow_backup": True,
      "allow_c2pa": True}),
    ({"terminal_pty_url": "https://ttyd.example.com/", "pdf_tools_url": "https://pdf.example.com/",
      "office_url": "https://office.example.com/?url={url}", "esign_url": "https://sign.example.com/?url={url}"},
     {"terminal_pty_url": "https://ttyd.example.com/", "pdf_tools_url": "https://pdf.example.com/",
      "office_url": "https://office.example.com/?url={url}", "esign_url": "https://sign.example.com/?url={url}"}),
]


@pytest.mark.parametrize("kwargs,expected", _TYPED_KWARG_CASES)
def test_typed_kwargs_land_in_correspondingly_named_claims(
    kwargs: dict[str, Any], expected: dict[str, Any]
) -> None:
    c = decode_token(create_token(secret=SECRET, user_id="u", **kwargs))
    for key, value in expected.items():
        assert c.get(key) == value


def test_rejects_secret_shorter_than_32_bytes() -> None:
    with pytest.raises(FluxFilesTokenError, match="at least 32 bytes"):
        create_token(secret="too-short", user_id="u")


# --- BYOB ---

_S3_DISK = {"driver": "s3", "key": "AK", "secret": "SK", "bucket": "b", "region": "us-east-1"}


def test_byob_lists_disk_names_and_embeds_blobs() -> None:
    token = create_byob_token(secret=SECRET, user_id="u", byob_disks={"my-s3": _S3_DISK})
    c = decode_token(token)
    assert c["disks"] == ["my-s3"]
    assert list(c["byob_disks"].keys()) == ["my-s3"]
    assert "max_storage" not in c
    assert "max_files" not in c
    assert c["perms"] == ["read", "write"]


def test_byob_rejects_non_s3_driver_and_missing_fields() -> None:
    with pytest.raises(FluxFilesByobError, match='driver must be "s3" or "sftp"'):
        create_byob_token(secret=SECRET, user_id="u", byob_disks={"x": {**_S3_DISK, "driver": "local"}})  # type: ignore[dict-item]
    with pytest.raises(FluxFilesByobError, match='missing required "bucket"'):
        create_byob_token(secret=SECRET, user_id="u", byob_disks={"x": {**_S3_DISK, "bucket": ""}})


def test_byob_accepts_sftp_disk() -> None:
    token = create_byob_token(
        secret=SECRET,
        user_id="u",
        byob_disks={
            "my-vps": {
                "driver": "sftp",
                "host": "vps.example.com",
                "username": "deploy",
                "password": "pw",
                "root": "/srv",
            }
        },
    )
    c = decode_token(token)
    assert c["disks"] == ["my-vps"]
    assert list(c["byob_disks"].keys()) == ["my-vps"]


def test_byob_rejects_sftp_disk_without_host_username_auth() -> None:
    with pytest.raises(FluxFilesByobError, match='missing required "host"'):
        create_byob_token(
            secret=SECRET, user_id="u", byob_disks={"x": {"driver": "sftp", "username": "u", "password": "p"}}
        )
    with pytest.raises(FluxFilesByobError, match='needs a "password" or "private_key"'):
        create_byob_token(
            secret=SECRET,
            user_id="u",
            byob_disks={"x": {"driver": "sftp", "host": "h.example.com", "username": "u"}},
        )


# --- verify / decode ---


def test_verify_token_round_trips_and_rejects_tampering_or_expiry() -> None:
    token = create_token(secret=SECRET, user_id="u")
    assert verify_token(token, SECRET)["sub"] == "u"
    with pytest.raises(jwt.InvalidSignatureError):
        verify_token(token, "another-secret-that-is-32-bytes-min!!")

    expired = create_token(secret=SECRET, user_id="u", ttl=-10)
    with pytest.raises(jwt.ExpiredSignatureError):
        verify_token(expired, SECRET)


def test_decode_token_does_not_require_the_secret() -> None:
    token = create_token(secret=SECRET, user_id="u", prefix="users/1")
    assert decode_token(token)["prefix"] == "users/1"
