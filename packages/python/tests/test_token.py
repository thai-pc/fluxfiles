"""Unit tests for create_token()/create_byob_token()/verify_token()/decode_token().

Loads the shared cross-language fixtures (docs/testdata/token-vectors.json) for
plain_tokens/role_presets/edition_presets/byob_role_presets, per
docs/design/PYTHON-TOKEN-SDK-DESIGN.md §6.2 — the same vectors Node's token.test.ts and
PHP's test-role-preset.php/test-byob.php load, so all three mint the exact same
cases instead of hand-copying them per language.
"""

import json
import time
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


VALID_ROLES = {"viewer", "editor", "admin", "superadmin"}
VALID_EDITIONS = {"free", "pro", "agency", "studio", "enterprise"}


def _assert_vector(v: dict[str, Any]) -> None:
    """Mint a vector's `input` via create_token() and assert its expect/expect_true/
    expect_absent fields against the decoded claims. `<claim>_present` asserts
    effective presence (is True), not literal key presence — matches the Node/PHP
    harnesses, since "absent" and "present-but-false" both mean "not enabled" for
    every boolean claim these fixtures cover.

    A vector naming an unknown role/edition must now raise (A-N2): a typo used to
    silently mint a token without the intended bundle."""
    inp = v["input"]
    if (inp.get("role") and inp["role"].lower() not in VALID_ROLES) or (
        inp.get("edition") and inp["edition"].lower() not in VALID_EDITIONS
    ):
        with pytest.raises(FluxFilesTokenError, match="unknown"):
            create_token(secret=SECRET, user_id=inp["user_id"], role=inp.get("role"),
                         edition=inp.get("edition"))
        return
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


@pytest.mark.parametrize("bad", [0, -1, 1.5, float("nan"), float("inf"), "3600", True])
def test_rejects_invalid_ttl(bad: Any) -> None:
    """A-M5: a NaN/string/non-positive ttl used to mint a token with a null or
    far-future `exp`; the core now rejects tokens without a numeric exp."""
    with pytest.raises(FluxFilesTokenError, match="ttl"):
        create_token(secret=SECRET, user_id="u", ttl=bad)
    with pytest.raises(FluxFilesTokenError, match="ttl"):
        create_byob_token(
            secret=SECRET,
            user_id="u",
            ttl=bad,
            byob_disks={"s": {"driver": "s3", "key": "AK", "secret": "SK", "bucket": "b"}},
        )


def test_exp_is_always_an_integer() -> None:
    for ttl in (None, 1, 7200):
        c = decode_token(create_token(secret=SECRET, user_id="u", ttl=ttl))
        assert type(c["exp"]) is int
        assert c["exp"] - c["iat"] == (ttl or 3600)
    c = decode_token(
        create_byob_token(
            secret=SECRET,
            user_id="u",
            byob_disks={"s": {"driver": "s3", "key": "AK", "secret": "SK", "bucket": "b"}},
        )
    )
    assert type(c["exp"]) is int
    assert c["exp"] - c["iat"] == 1800


def test_claims_escape_hatch_cannot_emit_a_non_integer_exp() -> None:
    for bad in ("9999999999", 1.5, float("nan"), True):
        with pytest.raises(FluxFilesTokenError, match="exp"):
            create_token(secret=SECRET, user_id="u", claims={"exp": bad})


def test_unknown_role_or_edition_raises_with_valid_list() -> None:
    """A-N2: a typo'd preset name raises (listing the valid names) instead of
    being silently ignored; names are case-insensitive and `free` is valid."""
    with pytest.raises(FluxFilesTokenError, match="viewer, editor, admin, superadmin"):
        create_token(secret=SECRET, user_id="u", role="admn")
    with pytest.raises(FluxFilesTokenError, match="free, pro, agency, studio, enterprise"):
        create_token(secret=SECRET, user_id="u", edition="enterprize")
    with pytest.raises(FluxFilesTokenError, match="unknown role"):
        create_byob_token(
            secret=SECRET,
            user_id="u",
            role="owner",
            byob_disks={"s": {"driver": "s3", "key": "AK", "secret": "SK", "bucket": "b"}},
        )
    with pytest.raises(FluxFilesTokenError, match="unknown edition"):
        create_byob_token(
            secret=SECRET,
            user_id="u",
            edition="gold",
            byob_disks={"s": {"driver": "s3", "key": "AK", "secret": "SK", "bucket": "b"}},
        )
    c = decode_token(create_token(secret=SECRET, user_id="u", role="Admin", edition="FREE"))
    assert c["perms"] == ["read", "write", "delete", "audit"]
    assert "allow_share" not in c
    c = decode_token(create_token(secret=SECRET, user_id="u", edition="studio"))
    assert c["allow_versioning"] is True


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
    ({"allow_audit_export": True, "audit_retention_days": 90, "allow_dlp_scan": True,
      "dlp_entity_types": ["US_SSN", "CREDIT_CARD"], "dlp_scan_extensions": ["txt", "csv"],
      "dlp_max_scan_kb": 4096, "dlp_min_score": 0.75, "allow_legal_hold": True},
     {"allow_audit_export": True, "audit_retention_days": 90, "allow_dlp_scan": True,
      "dlp_entity_types": ["US_SSN", "CREDIT_CARD"], "dlp_scan_extensions": ["txt", "csv"],
      "dlp_max_scan_kb": 4096, "dlp_min_score": 0.75, "allow_legal_hold": True}),
    ({"share_brand_name": "Acme", "share_brand_logo_url": "https://cdn.acme.com/logo.png",
      "share_brand_color": "#0b5fff", "share_brand_link_url": "https://acme.com",
      "intake_analytics": True, "intake_brand_name": "Acme Intake",
      "intake_brand_logo_url": "https://cdn.acme.com/i.png", "intake_brand_color": "#112233",
      "intake_brand_link_url": "https://acme.com/upload"},
     {"share_brand_name": "Acme", "share_brand_logo_url": "https://cdn.acme.com/logo.png",
      "share_brand_color": "#0b5fff", "share_brand_link_url": "https://acme.com",
      "intake_analytics": True, "intake_brand_name": "Acme Intake",
      "intake_brand_logo_url": "https://cdn.acme.com/i.png", "intake_brand_color": "#112233",
      "intake_brand_link_url": "https://acme.com/upload"}),
    ({"allow_git_deploy": True, "git_deploy_path": "/srv/www/app",
      "git_deploy_branch": "main", "git_deploy_hooks": True},
     {"allow_git_deploy": True, "git_deploy_path": "/srv/www/app",
      "git_deploy_branch": "main", "git_deploy_hooks": True}),
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


def test_git_deploy_is_independent_of_allow_terminal() -> None:
    """Git deploy is a fixed-command-shape subset, not a second shell door — neither
    claim may imply the other, in either direction."""
    deploy = decode_token(create_token(secret=SECRET, user_id="u", allow_git_deploy=True))
    assert deploy["allow_git_deploy"] is True
    assert "allow_terminal" not in deploy
    term = decode_token(create_token(secret=SECRET, user_id="u", allow_terminal=True))
    assert term["allow_terminal"] is True
    assert "allow_git_deploy" not in term

def test_pro_hints_is_embedded_only_when_explicitly_set() -> None:
    """pro_hints defaults to True on decode, so an absent kwarg must leave the claim
    absent — embedding a False would silently flip the core's default."""
    assert decode_token(create_token(secret=SECRET, user_id="u", pro_hints=False))["pro_hints"] is False
    assert decode_token(create_token(secret=SECRET, user_id="u", pro_hints=True))["pro_hints"] is True
    assert "pro_hints" not in decode_token(create_token(secret=SECRET, user_id="u"))

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

    # A non-positive ttl is rejected at mint time; backdate `exp` via the escape hatch.
    expired = create_token(secret=SECRET, user_id="u", claims={"exp": int(time.time()) - 10})
    with pytest.raises(jwt.ExpiredSignatureError):
        verify_token(expired, SECRET)


def test_decode_token_does_not_require_the_secret() -> None:
    token = create_token(secret=SECRET, user_id="u", prefix="users/1")
    assert decode_token(token)["prefix"] == "users/1"
