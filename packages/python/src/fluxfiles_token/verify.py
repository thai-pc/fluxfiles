"""Thin PyJWT wrappers: verify (signature + expiry) and decode (inspection only)."""

import os
from typing import cast

import jwt

from .exceptions import FluxFilesTokenError
from .types import FluxClaims


def verify_token(token: str, secret: str | None = None) -> FluxClaims:
    """Verify an HS256 FluxFiles **API access token**: signature, a numeric
    ``exp`` that has not passed, and no type marker. Same rules as core's
    JwtMiddleware: a token without ``exp`` never expires, and the narrow tokens
    signed with the same secret (``t`` = img/stream/share/intake/..., or the
    legacy ``share``/``intake`` flags) are only safe on their own endpoints, so
    neither is accepted here. Returns the decoded claims, or raises.

    PyJWT's own exceptions propagate unwrapped (`jwt.ExpiredSignatureError`,
    `jwt.InvalidSignatureError`, …) — a Python user of a JWT-adjacent library
    expects PyJWT's exception hierarchy, not a re-wrapped one.
    """
    key = secret if secret is not None else os.environ.get("FLUXFILES_SECRET", "")
    if not key:
        raise FluxFilesTokenError("FluxFiles: no secret provided to verify_token")
    claims = jwt.decode(token, key, algorithms=["HS256"], options={"require": ["exp"]})
    if "t" in claims or claims.get("share") or claims.get("intake"):
        raise FluxFilesTokenError("FluxFiles: not an API access token")
    return cast(FluxClaims, claims)


def decode_token(token: str) -> FluxClaims:
    """Decode a token WITHOUT verifying the signature. Useful for
    inspection/logging; never trust the result for authorization — use
    verify_token() instead."""
    claims = jwt.decode(token, options={"verify_signature": False})
    return cast(FluxClaims, claims)
