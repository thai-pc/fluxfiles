"""Mint FluxFiles-compatible JWTs — plain (`create_token`) and BYOB
(`create_byob_token`). Mirrors `packages/node/src/token.ts` 1:1 (camelCase ->
snake_case) and PHP's `embed.php` (`_fluxfiles_build_token()` /
`fluxfiles_byob_token()`), including the role/edition preset mechanics from
docs/ACL-ROLE-PRESETS-DESIGN.md.
"""

import os
import secrets
import time
from collections.abc import Mapping
from typing import Any

import jwt

from .crypto import encrypt_byob
from .exceptions import FluxFilesByobError, FluxFilesTokenError
from .types import ByobDiskConfig

_MIN_SECRET_BYTES = 32

# Role preset (DX sugar, docs/ACL-ROLE-PRESETS-DESIGN.md): defaults a person's
# capability level; explicit kwargs always win. `role` never itself becomes a
# JWT claim — it only ever expands into ordinary claims decoded server-side
# already. `viewer`/`editor` set `allow_extract`/`allow_chmod` explicitly
# (rather than omitting them) because `Claims::fromJwtPayload` defaults BOTH
# to `True` when absent — omitting them here would silently grant chmod/
# extract to a token meant to be read-only/contributor-only (the shipped
# `d880b98`/`fb7c8a2` bug, see CHANGELOG.md `[0.3.00]`).
ROLE_PRESETS: dict[str, dict[str, Any]] = {
    "viewer": {
        "perms": ["read"],
        "owner_only": True,
        "allow_extract": False,
        "allow_chmod": False,
    },
    "editor": {
        "perms": ["read", "write"],
        "owner_only": True,
        "allow_extract": True,
        "allow_chmod": False,
    },
    "admin": {
        "perms": ["read", "write", "delete", "audit"],
        "owner_only": False,
        "allow_extract": True,
        "allow_chmod": True,
        "allow_code_edit": True,
        "show_hidden": True,
    },
    "superadmin": {  # identical bundle to admin — see ACL doc §2's note
        "perms": ["read", "write", "delete", "audit"],
        "owner_only": False,
        "allow_extract": True,
        "allow_chmod": True,
        "allow_code_edit": True,
        "show_hidden": True,
    },
}

# Edition preset (DX sugar): defaults a tier's claims; explicit kwargs still
# win, and the license is still the real gate — a preset just defaults the
# per-tenant claims for a tier.
EDITION_PRESETS: dict[str, dict[str, bool]] = {
    "pro": {"allow_optimize": True, "allow_share": True, "allow_intake": True},
    "agency": {"allow_optimize": True, "allow_share": True, "allow_intake": True},
    "studio": {
        "allow_optimize": True,
        "allow_share": True,
        "allow_intake": True,
        "allow_versioning": True,
        "allow_webhooks": True,
        "allow_ai_vision": True,
        "allow_ocr": True,
    },
    "enterprise": {
        "allow_optimize": True,
        "allow_share": True,
        "allow_intake": True,
        "allow_versioning": True,
        "allow_webhooks": True,
        "allow_ai_vision": True,
        "allow_ocr": True,
        "allow_virus_scan": True,
        "allow_c2pa": True,
        "allow_backup": True,
        "allow_audit_export": True,
        "allow_dlp_scan": True,
        "allow_legal_hold": True,
    },
}


def _resolve_secret(explicit: str | None) -> str:
    secret = explicit if explicit is not None else os.environ.get("FLUXFILES_SECRET", "")
    if len(secret.encode("utf-8")) < _MIN_SECRET_BYTES:
        raise FluxFilesTokenError(
            f"FluxFiles: signing secret must be at least {_MIN_SECRET_BYTES} bytes "
            "(HS256 key requirement). Set `secret` or FLUXFILES_SECRET."
        )
    return secret


def _sign(payload: dict[str, Any], secret: str) -> str:
    return jwt.encode(payload, secret, algorithm="HS256")


def _new_jti() -> str:
    return secrets.token_hex(12)


def _sanitize_variants(v: dict[str, int] | None) -> dict[str, int] | None:
    """Matches PHP `Claims::sanitizeVariants` / Node's `sanitizeVariants`."""
    if not v or not isinstance(v, dict):
        return None
    out: dict[str, int] = {}
    for name in ("thumb", "medium", "large"):
        if name not in v:
            continue
        try:
            width = int(v[name])
        except (TypeError, ValueError):
            continue
        if 16 <= width <= 8000:
            out[name] = width
    return out or None


def _apply_tenant_overrides(
    payload: dict[str, Any],
    extras: dict[str, Any],
    edition: str | None,
    role_preset: dict[str, Any] | None,
    claims: dict[str, Any] | None,
) -> None:
    """Copy the optional per-tenant override claims into a payload when set.
    Merge order (matches embed.php's `_fluxfiles_build_token()` and Node's
    `applyTenantOverrides()` exactly): edition preset (fills absent keys only)
    -> role preset, excluding perms/owner_only, already resolved by the caller
    (fills absent keys only) -> every typed kwarg in `extras` (explicit,
    always overwrites a preset default) -> `claims` escape hatch (always wins,
    merged last)."""
    edition_preset = EDITION_PRESETS.get(str(edition).lower()) if edition else None
    if edition_preset:
        for k, v in edition_preset.items():
            if k not in payload:
                payload[k] = v
    # Role preset: the rest of the bundle, excluding `perms`/`owner_only` — both
    # are already resolved by the caller before this function ever runs.
    if role_preset:
        for k, v in role_preset.items():
            if k not in ("perms", "owner_only") and k not in payload:
                payload[k] = v

    if extras.get("ai_auto_tag") is not None:
        payload["ai_auto_tag"] = bool(extras["ai_auto_tag"])
    rate_read = extras.get("rate_read")
    if rate_read and rate_read > 0:
        payload["rate_read"] = int(rate_read)
    rate_write = extras.get("rate_write")
    if rate_write and rate_write > 0:
        payload["rate_write"] = int(rate_write)
    variants = _sanitize_variants(extras.get("variants"))
    if variants:
        payload["variants"] = variants

    # URL-import claims (the server sanitizes/clamps these on decode).
    if extras.get("allow_url_import"):
        payload["allow_url_import"] = True
    max_import_mb = extras.get("max_import_mb")
    if max_import_mb and max_import_mb > 0:
        payload["max_import_mb"] = int(max_import_mb)
    import_rate_limit = extras.get("import_rate_limit")
    if import_rate_limit and import_rate_limit > 0:
        payload["import_rate_limit"] = int(import_rate_limit)
    import_concurrency = extras.get("import_concurrency")
    if import_concurrency and import_concurrency > 0:
        payload["import_concurrency"] = int(import_concurrency)
    import_path = extras.get("import_path")
    if import_path:
        payload["import_path"] = str(import_path)
    import_url_allowlist = extras.get("import_url_allowlist")
    if import_url_allowlist:
        payload["import_url_allowlist"] = [str(h) for h in import_url_allowlist]

    # Media-preview claims (the server sanitizes/clamps these on decode).
    if extras.get("media_preview") is not None:
        payload["media_preview"] = bool(extras["media_preview"])
    preview_url_ttl = extras.get("preview_url_ttl")
    if preview_url_ttl and preview_url_ttl > 0:
        payload["preview_url_ttl"] = int(preview_url_ttl)
    max_preview_mb = extras.get("max_preview_mb")
    if max_preview_mb and max_preview_mb > 0:
        payload["max_preview_mb"] = int(max_preview_mb)
    stream_token_ttl = extras.get("stream_token_ttl")
    if stream_token_ttl and stream_token_ttl > 0:
        payload["stream_token_ttl"] = int(stream_token_ttl)

    # On-demand WebP/AVIF claims.
    if extras.get("webp_enabled") is not None:
        payload["webp_enabled"] = bool(extras["webp_enabled"])
    webp_max_width = extras.get("webp_max_width")
    if webp_max_width and webp_max_width > 0:
        payload["webp_max_width"] = int(webp_max_width)
    webp_default_quality = extras.get("webp_default_quality")
    if webp_default_quality and webp_default_quality > 0:
        payload["webp_default_quality"] = int(webp_default_quality)
    srcset_widths = extras.get("srcset_widths")
    if srcset_widths is not None:
        payload["srcset_widths"] = [int(w) for w in srcset_widths]
    srcset_sizes = extras.get("srcset_sizes")
    if srcset_sizes:
        payload["srcset_sizes"] = str(srcset_sizes)

    # Download gate / SFTP chmod / code-edit / terminal / BYO-embed URLs.
    if extras.get("allow_download") is not None:
        payload["allow_download"] = bool(extras["allow_download"])
    if extras.get("allow_chmod") is not None:
        payload["allow_chmod"] = bool(extras["allow_chmod"])
    if extras.get("allow_code_edit") is not None:
        payload["allow_code_edit"] = bool(extras["allow_code_edit"])
    # SSH terminal (SFTP disks). Core-standalone — the token must target a real
    # core (this SDK mints for one), not a proxy adapter that doesn't serve
    # /api/fm/terminal.
    if extras.get("allow_terminal") is not None:
        payload["allow_terminal"] = bool(extras["allow_terminal"])
    terminal_pty_url = extras.get("terminal_pty_url")
    if terminal_pty_url:
        payload["terminal_pty_url"] = str(terminal_pty_url)
    pdf_tools_url = extras.get("pdf_tools_url")
    if pdf_tools_url:
        payload["pdf_tools_url"] = str(pdf_tools_url)
    office_url = extras.get("office_url")
    if office_url:
        payload["office_url"] = str(office_url)
    esign_url = extras.get("esign_url")
    if esign_url:
        payload["esign_url"] = str(esign_url)

    # Optimization (free/core).
    if extras.get("allow_optimize") is not None:
        payload["allow_optimize"] = bool(extras["allow_optimize"])
    if extras.get("auto_optimize") is not None:
        payload["auto_optimize"] = bool(extras["auto_optimize"])
    optimize_quality = extras.get("optimize_quality")
    if optimize_quality and optimize_quality > 0:
        payload["optimize_quality"] = int(optimize_quality)
    if extras.get("optimize_keep_original") is not None:
        payload["optimize_keep_original"] = bool(extras["optimize_keep_original"])
    optimize_max_mb = extras.get("optimize_max_mb")
    if optimize_max_mb and optimize_max_mb > 0:
        payload["optimize_max_mb"] = int(optimize_max_mb)
    pdf_level = extras.get("pdf_level")
    if pdf_level in ("screen", "ebook", "printer", "prepress", "default"):
        payload["pdf_level"] = pdf_level

    # Upload behavior / listing.
    upload_collision = extras.get("upload_collision")
    if upload_collision in ("rename", "overwrite", "reject"):
        payload["upload_collision"] = upload_collision
    if extras.get("show_hidden") is not None:
        payload["show_hidden"] = bool(extras["show_hidden"])
    if extras.get("dedupe_uploads") is not None:
        payload["dedupe_uploads"] = bool(extras["dedupe_uploads"])

    # Zip/extract.
    if extras.get("allow_zip") is not None:
        payload["allow_zip"] = bool(extras["allow_zip"])
    if extras.get("allow_extract") is not None:
        payload["allow_extract"] = bool(extras["allow_extract"])
    zip_max_mb = extras.get("zip_max_mb")
    if zip_max_mb and zip_max_mb > 0:
        payload["zip_max_mb"] = int(zip_max_mb)
    zip_max_files = extras.get("zip_max_files")
    if zip_max_files and zip_max_files > 0:
        payload["zip_max_files"] = int(zip_max_files)

    # Paid-module claims (all default off; inert unless installed + licensed).
    if extras.get("allow_share") is not None:
        payload["allow_share"] = bool(extras["allow_share"])
    # Share landing config — read at create time and baked into the share
    # record (the core clamps the TTL and drops a non-http(s) base URL on decode).
    share_url_ttl = extras.get("share_url_ttl")
    if share_url_ttl and share_url_ttl > 0:
        payload["share_url_ttl"] = int(share_url_ttl)
    share_base_url = extras.get("share_base_url")
    if share_base_url:
        payload["share_base_url"] = str(share_base_url)
    if extras.get("share_preview") is not None:
        payload["share_preview"] = bool(extras["share_preview"])
    if extras.get("share_analytics") is not None:
        payload["share_analytics"] = bool(extras["share_analytics"])
    if extras.get("allow_intake") is not None:
        payload["allow_intake"] = bool(extras["allow_intake"])
    # Intake portal link base — same shape as share_base_url (the core drops a
    # non-http(s) value on decode).
    intake_base_url = extras.get("intake_base_url")
    if intake_base_url:
        payload["intake_base_url"] = str(intake_base_url)
    if extras.get("allow_versioning") is not None:
        payload["allow_versioning"] = bool(extras["allow_versioning"])
    versioning_max = extras.get("versioning_max")
    if versioning_max and versioning_max > 0:
        payload["versioning_max"] = int(versioning_max)
    versioning_max_mb = extras.get("versioning_max_mb")
    if versioning_max_mb and versioning_max_mb > 0:
        payload["versioning_max_mb"] = int(versioning_max_mb)
    if extras.get("allow_webhooks") is not None:
        payload["allow_webhooks"] = bool(extras["allow_webhooks"])
    webhook_url = extras.get("webhook_url")
    if webhook_url:
        payload["webhook_url"] = str(webhook_url)
    webhook_events = extras.get("webhook_events")
    if webhook_events:
        payload["webhook_events"] = list(webhook_events)
    webhook_secret = extras.get("webhook_secret")
    if webhook_secret:
        payload["webhook_secret"] = str(webhook_secret)
    if extras.get("allow_ai_vision") is not None:
        payload["allow_ai_vision"] = bool(extras["allow_ai_vision"])
    if extras.get("allow_ocr") is not None:
        payload["allow_ocr"] = bool(extras["allow_ocr"])
    if extras.get("allow_virus_scan") is not None:
        payload["allow_virus_scan"] = bool(extras["allow_virus_scan"])
    if extras.get("allow_backup") is not None:
        payload["allow_backup"] = bool(extras["allow_backup"])
    if extras.get("allow_c2pa") is not None:
        payload["allow_c2pa"] = bool(extras["allow_c2pa"])

    # Watermark overlay (preview-time; source file is never modified).
    if extras.get("watermark_enabled"):
        payload["watermark_enabled"] = True
        watermark_type = extras.get("watermark_type")
        if watermark_type:
            payload["watermark_type"] = watermark_type
        watermark_text = extras.get("watermark_text")
        if watermark_text:
            payload["watermark_text"] = str(watermark_text)
        watermark_logo_path = extras.get("watermark_logo_path")
        if watermark_logo_path:
            payload["watermark_logo_path"] = str(watermark_logo_path)
        watermark_position = extras.get("watermark_position")
        if watermark_position:
            payload["watermark_position"] = watermark_position
        watermark_opacity = extras.get("watermark_opacity")
        if watermark_opacity is not None:
            payload["watermark_opacity"] = float(watermark_opacity)
        watermark_font_size = extras.get("watermark_font_size")
        if watermark_font_size and watermark_font_size > 0:
            payload["watermark_font_size"] = int(watermark_font_size)

    # Usage-dashboard claims.
    usage_cache_ttl = extras.get("usage_cache_ttl")
    if usage_cache_ttl and usage_cache_ttl > 0:
        payload["usage_cache_ttl"] = int(usage_cache_ttl)
    usage_warning_threshold = extras.get("usage_warning_threshold")
    if usage_warning_threshold and usage_warning_threshold > 0:
        payload["usage_warning_threshold"] = int(usage_warning_threshold)
    usage_critical_threshold = extras.get("usage_critical_threshold")
    if usage_critical_threshold and usage_critical_threshold > 0:
        payload["usage_critical_threshold"] = int(usage_critical_threshold)
    usage_top_folders_count = extras.get("usage_top_folders_count")
    if usage_top_folders_count and usage_top_folders_count > 0:
        payload["usage_top_folders_count"] = int(usage_top_folders_count)
    usage_folder_depth = extras.get("usage_folder_depth")
    if usage_folder_depth and usage_folder_depth > 0:
        payload["usage_folder_depth"] = int(usage_folder_depth)

    # Generic escape hatch: ANY claim by its raw (snake_case) name. Merged last
    # so an explicit claim wins over a preset/group default. The server
    # sanitizes on decode. See docs/CONFIG.md for the full claim list.
    if claims:
        for k, v in claims.items():
            if v is not None:
                payload[k] = v


def _validate_byob_disk(name: str, config: Mapping[str, Any]) -> None:
    """Light client-side validation. The server independently re-validates
    (incl. SSRF checks on the endpoint), so this only catches obvious mistakes
    early. Mirrors Node's `validateByobDisk`."""
    driver = config.get("driver") if isinstance(config, Mapping) else None
    if driver not in ("s3", "sftp"):
        raise FluxFilesByobError(
            f'FluxFiles BYOB disk "{name}": driver must be "s3" or "sftp" '
            '(the server rejects "local").'
        )
    if driver == "sftp":
        for field in ("host", "username"):
            if not config.get(field):
                raise FluxFilesByobError(f'FluxFiles BYOB disk "{name}": missing required "{field}".')
        if not config.get("password") and not config.get("private_key"):
            raise FluxFilesByobError(f'FluxFiles BYOB disk "{name}": needs a "password" or "private_key".')
        return
    for field in ("key", "secret", "bucket"):
        if not config.get(field):
            raise FluxFilesByobError(f'FluxFiles BYOB disk "{name}": missing required "{field}".')


def create_token(
    *,
    # --- identity / access / quota (CreateTokenOptions base) ---
    user_id: str,
    secret: str | None = None,
    perms: list[str] | None = None,
    disks: list[str] | None = None,
    prefix: str | None = None,
    max_upload_mb: int | None = None,
    allowed_ext: list[str] | None = None,
    ttl: int | None = None,
    owner_only: bool | None = None,
    max_storage_mb: int | None = None,
    max_files: int | None = None,
    # --- presets (DX sugar, never themselves become claims) ---
    edition: str | None = None,
    role: str | None = None,
    # --- per-tenant AI / rate-limit / variants ---
    ai_auto_tag: bool | None = None,
    rate_read: int | None = None,
    rate_write: int | None = None,
    variants: dict[str, int] | None = None,
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
    pdf_level: str | None = None,  # "screen"|"ebook"|"printer"|"prepress"|"default"
    # --- upload behavior / listing ---
    upload_collision: str | None = None,  # "rename"|"overwrite"|"reject"
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
    watermark_type: str | None = None,  # "text" | "logo"
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
) -> str:
    """Mint a standard FluxFiles JWT. The payload mirrors the PHP
    `fluxfiles_token()` helper exactly, so the FluxFiles core decodes it
    natively."""
    resolved_secret = _resolve_secret(secret)
    now = int(time.time())

    # Role preset (DX sugar, docs/ACL-ROLE-PRESETS-DESIGN.md): resolved BEFORE
    # the base payload dict, because `perms`/`owner_only` already have an
    # unconditional default baked into that dict below — a plain "set if
    # absent" guard would never fire for them.
    role_preset = ROLE_PRESETS.get(role.lower()) if role else None
    resolved_perms = (
        perms
        if perms is not None
        else role_preset.get("perms") if role_preset is not None else ["read"]
    )
    resolved_owner_only = (
        owner_only
        if owner_only is not None
        else role_preset.get("owner_only") if role_preset is not None else False
    )

    payload: dict[str, Any] = {
        "sub": str(user_id),
        "iat": now,
        "exp": now + (ttl if ttl is not None else 3600),
        "jti": _new_jti(),
        "perms": resolved_perms,
        "disks": disks if disks is not None else ["local"],
        "prefix": prefix if prefix is not None else "",
        "max_upload": max_upload_mb if max_upload_mb is not None else 10,
        "allowed_ext": allowed_ext,
        "max_storage": max_storage_mb if max_storage_mb is not None else 0,
        "max_files": max_files if max_files is not None else 0,
    }
    if resolved_owner_only:
        payload["owner_only"] = True

    extras: dict[str, Any] = dict(
        ai_auto_tag=ai_auto_tag,
        rate_read=rate_read,
        rate_write=rate_write,
        variants=variants,
        allow_url_import=allow_url_import,
        max_import_mb=max_import_mb,
        import_url_allowlist=import_url_allowlist,
        import_path=import_path,
        import_rate_limit=import_rate_limit,
        import_concurrency=import_concurrency,
        media_preview=media_preview,
        preview_url_ttl=preview_url_ttl,
        max_preview_mb=max_preview_mb,
        stream_token_ttl=stream_token_ttl,
        webp_enabled=webp_enabled,
        webp_max_width=webp_max_width,
        webp_default_quality=webp_default_quality,
        srcset_widths=srcset_widths,
        srcset_sizes=srcset_sizes,
        allow_download=allow_download,
        allow_chmod=allow_chmod,
        allow_code_edit=allow_code_edit,
        allow_terminal=allow_terminal,
        terminal_pty_url=terminal_pty_url,
        pdf_tools_url=pdf_tools_url,
        office_url=office_url,
        esign_url=esign_url,
        allow_optimize=allow_optimize,
        auto_optimize=auto_optimize,
        optimize_quality=optimize_quality,
        optimize_keep_original=optimize_keep_original,
        optimize_max_mb=optimize_max_mb,
        pdf_level=pdf_level,
        upload_collision=upload_collision,
        show_hidden=show_hidden,
        dedupe_uploads=dedupe_uploads,
        allow_zip=allow_zip,
        allow_extract=allow_extract,
        zip_max_mb=zip_max_mb,
        zip_max_files=zip_max_files,
        allow_share=allow_share,
        share_url_ttl=share_url_ttl,
        share_base_url=share_base_url,
        share_preview=share_preview,
        share_analytics=share_analytics,
        allow_intake=allow_intake,
        intake_base_url=intake_base_url,
        allow_versioning=allow_versioning,
        versioning_max=versioning_max,
        versioning_max_mb=versioning_max_mb,
        allow_webhooks=allow_webhooks,
        webhook_url=webhook_url,
        webhook_events=webhook_events,
        webhook_secret=webhook_secret,
        allow_ai_vision=allow_ai_vision,
        allow_ocr=allow_ocr,
        allow_virus_scan=allow_virus_scan,
        allow_backup=allow_backup,
        allow_c2pa=allow_c2pa,
        watermark_enabled=watermark_enabled,
        watermark_type=watermark_type,
        watermark_text=watermark_text,
        watermark_logo_path=watermark_logo_path,
        watermark_position=watermark_position,
        watermark_opacity=watermark_opacity,
        watermark_font_size=watermark_font_size,
        usage_cache_ttl=usage_cache_ttl,
        usage_warning_threshold=usage_warning_threshold,
        usage_critical_threshold=usage_critical_threshold,
        usage_top_folders_count=usage_top_folders_count,
        usage_folder_depth=usage_folder_depth,
    )
    _apply_tenant_overrides(payload, extras, edition, role_preset, claims)
    return _sign(payload, resolved_secret)


def create_byob_token(
    *,
    user_id: str,
    byob_disks: dict[str, ByobDiskConfig],
    secret: str | None = None,
    perms: list[str] | None = None,
    prefix: str | None = None,
    ttl: int | None = None,
    max_upload_mb: int | None = None,
    allowed_ext: list[str] | None = None,
    owner_only: bool | None = None,
    # --- presets: INCLUDED on BYOB tokens (decided — see design doc §5.1) ---
    edition: str | None = None,
    role: str | None = None,
    # --- identical typed kwargs as create_token(), present 1:1 ---
    ai_auto_tag: bool | None = None,
    rate_read: int | None = None,
    rate_write: int | None = None,
    variants: dict[str, int] | None = None,
    allow_url_import: bool | None = None,
    max_import_mb: int | None = None,
    import_url_allowlist: list[str] | None = None,
    import_path: str | None = None,
    import_rate_limit: int | None = None,
    import_concurrency: int | None = None,
    media_preview: bool | None = None,
    preview_url_ttl: int | None = None,
    max_preview_mb: int | None = None,
    stream_token_ttl: int | None = None,
    webp_enabled: bool | None = None,
    webp_max_width: int | None = None,
    webp_default_quality: int | None = None,
    srcset_widths: list[int] | None = None,
    srcset_sizes: str | None = None,
    allow_download: bool | None = None,
    allow_chmod: bool | None = None,
    allow_code_edit: bool | None = None,
    allow_terminal: bool | None = None,
    terminal_pty_url: str | None = None,
    pdf_tools_url: str | None = None,
    office_url: str | None = None,
    esign_url: str | None = None,
    allow_optimize: bool | None = None,
    auto_optimize: bool | None = None,
    optimize_quality: int | None = None,
    optimize_keep_original: bool | None = None,
    optimize_max_mb: int | None = None,
    pdf_level: str | None = None,
    upload_collision: str | None = None,
    show_hidden: bool | None = None,
    dedupe_uploads: bool | None = None,
    allow_zip: bool | None = None,
    allow_extract: bool | None = None,
    zip_max_mb: int | None = None,
    zip_max_files: int | None = None,
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
    watermark_enabled: bool | None = None,
    watermark_type: str | None = None,
    watermark_text: str | None = None,
    watermark_logo_path: str | None = None,
    watermark_position: str | None = None,
    watermark_opacity: float | None = None,
    watermark_font_size: int | None = None,
    usage_cache_ttl: int | None = None,
    usage_warning_threshold: int | None = None,
    usage_critical_threshold: int | None = None,
    usage_top_folders_count: int | None = None,
    usage_folder_depth: int | None = None,
    claims: dict[str, Any] | None = None,
) -> str:
    """Mint a BYOB token. Each disk's S3-compatible/SFTP credentials are
    AES-256-GCM encrypted into the token (decrypted only at runtime by the
    server). Mirrors the PHP `fluxfiles_byob_token()` helper, but — unlike
    core/Node's original `createByobToken()` — also supports `role`/`edition`
    presets, following the Laravel/WordPress precedent (design doc §5.1):

    1. Encrypt each `byob_disks` entry (independent of any preset).
    2. `disks` = the BYOB disk names (this SDK has no server-config store to
       merge against, unlike Laravel/WordPress).
    3. Resolve `perms`/`owner_only` early (BYOB's own base default is
       `["read", "write"]`, unlike plain tokens' `["read"]`).
    4. Build the base payload (encrypted `byob_disks`, `disks`, `perms`,
       `owner_only`).
    5-6. Apply the edition preset, then the role preset (excluding
       `perms`/`owner_only`, already resolved in step 3) — fills absent keys.
    7. Apply every other typed kwarg — explicit values always win.
    8. Apply the `claims` escape hatch last — always wins.
    """
    resolved_secret = _resolve_secret(secret)
    now = int(time.time())

    # Step 1: encrypt each BYOB disk entry.
    encrypted: dict[str, str] = {}
    names: list[str] = []
    for name, config in byob_disks.items():
        _validate_byob_disk(name, config)
        encrypted[name] = encrypt_byob(dict(config), resolved_secret)
        names.append(name)

    # Step 2: disks = BYOB disk names only (no server-config merge).
    resolved_disks = names

    # Step 3: resolve perms/owner_only early.
    role_preset = ROLE_PRESETS.get(role.lower()) if role else None
    resolved_perms = (
        perms
        if perms is not None
        else role_preset.get("perms") if role_preset is not None else ["read", "write"]
    )
    resolved_owner_only = (
        owner_only
        if owner_only is not None
        else role_preset.get("owner_only") if role_preset is not None else False
    )

    # Step 4: base payload.
    payload: dict[str, Any] = {
        "sub": str(user_id),
        "iat": now,
        "exp": now + (ttl if ttl is not None else 1800),
        "jti": _new_jti(),
        "perms": resolved_perms,
        "disks": resolved_disks,
        "prefix": prefix if prefix is not None else "",
        "max_upload": max_upload_mb if max_upload_mb is not None else 10,
        "allowed_ext": allowed_ext,
        "byob_disks": encrypted,
    }
    if resolved_owner_only:
        payload["owner_only"] = True

    # Steps 5-8: edition preset -> role preset -> typed kwargs -> claims.
    extras: dict[str, Any] = dict(
        ai_auto_tag=ai_auto_tag,
        rate_read=rate_read,
        rate_write=rate_write,
        variants=variants,
        allow_url_import=allow_url_import,
        max_import_mb=max_import_mb,
        import_url_allowlist=import_url_allowlist,
        import_path=import_path,
        import_rate_limit=import_rate_limit,
        import_concurrency=import_concurrency,
        media_preview=media_preview,
        preview_url_ttl=preview_url_ttl,
        max_preview_mb=max_preview_mb,
        stream_token_ttl=stream_token_ttl,
        webp_enabled=webp_enabled,
        webp_max_width=webp_max_width,
        webp_default_quality=webp_default_quality,
        srcset_widths=srcset_widths,
        srcset_sizes=srcset_sizes,
        allow_download=allow_download,
        allow_chmod=allow_chmod,
        allow_code_edit=allow_code_edit,
        allow_terminal=allow_terminal,
        terminal_pty_url=terminal_pty_url,
        pdf_tools_url=pdf_tools_url,
        office_url=office_url,
        esign_url=esign_url,
        allow_optimize=allow_optimize,
        auto_optimize=auto_optimize,
        optimize_quality=optimize_quality,
        optimize_keep_original=optimize_keep_original,
        optimize_max_mb=optimize_max_mb,
        pdf_level=pdf_level,
        upload_collision=upload_collision,
        show_hidden=show_hidden,
        dedupe_uploads=dedupe_uploads,
        allow_zip=allow_zip,
        allow_extract=allow_extract,
        zip_max_mb=zip_max_mb,
        zip_max_files=zip_max_files,
        allow_share=allow_share,
        share_url_ttl=share_url_ttl,
        share_base_url=share_base_url,
        share_preview=share_preview,
        share_analytics=share_analytics,
        allow_intake=allow_intake,
        intake_base_url=intake_base_url,
        allow_versioning=allow_versioning,
        versioning_max=versioning_max,
        versioning_max_mb=versioning_max_mb,
        allow_webhooks=allow_webhooks,
        webhook_url=webhook_url,
        webhook_events=webhook_events,
        webhook_secret=webhook_secret,
        allow_ai_vision=allow_ai_vision,
        allow_ocr=allow_ocr,
        allow_virus_scan=allow_virus_scan,
        allow_backup=allow_backup,
        allow_c2pa=allow_c2pa,
        watermark_enabled=watermark_enabled,
        watermark_type=watermark_type,
        watermark_text=watermark_text,
        watermark_logo_path=watermark_logo_path,
        watermark_position=watermark_position,
        watermark_opacity=watermark_opacity,
        watermark_font_size=watermark_font_size,
        usage_cache_ttl=usage_cache_ttl,
        usage_warning_threshold=usage_warning_threshold,
        usage_critical_threshold=usage_critical_threshold,
        usage_top_folders_count=usage_top_folders_count,
        usage_folder_depth=usage_folder_depth,
    )
    _apply_tenant_overrides(payload, extras, edition, role_preset, claims)
    return _sign(payload, resolved_secret)
