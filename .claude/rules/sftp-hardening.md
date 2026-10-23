---
paths:
  - "packages/core/api/DiskManager.php"
  - "packages/core/api/CredentialEncryptor.php"
---

**SFTP host-key / cipher hardening** (`DiskManager::buildSftpProvider()`, config keys `require_host_key`/`strict_algorithms`, env `SFTP_REQUIRE_HOST_KEY`/`SFTP_STRICT_ALGORITHMS`): two opt-in (default-off, backward-compatible) tightenings on top of the pre-existing `host_fingerprint` pinning. `require_host_key` fails closed at disk-build time (`sftp_host_key_required` error) if `host_fingerprint` isn't also set, instead of silently trusting any host key. `strict_algorithms` passes a modern-only `preferredAlgorithms` allowlist (curve25519/ECDH/DH-group16+ KEX, ed25519/ecdsa/rsa-sha2 host keys, AES-GCM/CTR/ChaCha20 ciphers, SHA-2 MACs) to `SftpConnectionProvider`, excluding phpseclib's legacy-compat defaults (SHA-1 KEX, RC4, 3DES, CBC ciphers, ssh-dss, MD5/SHA-1 MACs) — turning it on for a legacy/embedded SFTP server fails the handshake outright rather than downgrading. BYOB SFTP configs get both keys for free since `CredentialEncryptor::validate()` doesn't allowlist config fields.
