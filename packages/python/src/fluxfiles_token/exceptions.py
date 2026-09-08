"""Errors raised by fluxfiles_token."""


class FluxFilesTokenError(Exception):
    """Base error for token construction/verification failures."""


class FluxFilesByobError(FluxFilesTokenError):
    """BYOB disk validation, or credential encryption/decryption, failure."""
