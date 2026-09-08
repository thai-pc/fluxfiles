"""Thin PyJWT wrappers: verify (signature + expiry) and decode (inspection only)."""

import os
from typing import cast

import jwt

from .exceptions import FluxFilesTokenError
from .types import FluxClaims


def verify_token(token: str, secret: str | None = None) -> FluxClaims:
    """Verify an HS256 FluxFiles token: signature + expiry. Returns the decoded
    claims, or raises on a bad signature, wrong algorithm, or an expired token.

    PyJWT's own exceptions propagate unwrapped (`jwt.ExpiredSignatureError`,
    `jwt.InvalidSignatureError`, …) — a Python user of a JWT-adjacent library
    expects PyJWT's exception hierarchy, not a re-wrapped one.
    """
    key = secret if secret is not None else os.environ.get("FLUXFILES_SECRET", "")
    if not key:
        raise FluxFilesTokenError("FluxFiles: no secret provided to verify_token")
    claims = jwt.decode(token, key, algorithms=["HS256"])
    return cast(FluxClaims, claims)


def decode_token(token: str) -> FluxClaims:
    """Decode a token WITHOUT verifying the signature. Useful for
    inspection/logging; never trust the result for authorization — use
    verify_token() instead."""
    claims = jwt.decode(token, options={"verify_signature": False})
    return cast(FluxClaims, claims)
