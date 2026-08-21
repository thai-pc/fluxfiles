<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * AES-256-GCM encryption for BYOB (Bring Your Own Bucket) credentials.
 *
 * Encrypts S3/R2 credentials before embedding them in JWT tokens,
 * so that credentials are not exposed in plain text.
 */
class CredentialEncryptor
{
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_LEN = 12;
    private const TAG_LEN = 16;
    private const HKDF_INFO = 'fluxfiles-byob-enc';

    /**
     * Encrypt a disk config array into a base64-encoded blob.
     *
     * @param array  $config Disk config: driver, key, secret, bucket, region, endpoint
     * @param string $secret The FLUXFILES_SECRET (used to derive encryption key)
     * @return string Base64-encoded ciphertext (nonce + ciphertext + tag)
     */
    public static function encrypt(array $config, string $secret): string
    {
        $key = self::deriveKey($secret);
        $plaintext = json_encode($config, JSON_UNESCAPED_SLASHES);
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LEN
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('BYOB credential encryption failed');
        }

        // Pack: nonce (12) + ciphertext (variable) + tag (16)
        return base64_encode($nonce . $ciphertext . $tag);
    }

    /**
     * Decrypt a base64-encoded blob back into a disk config array.
     *
     * @param string $blob Base64-encoded encrypted blob
     * @param string $secret The FLUXFILES_SECRET
     * @return array Decrypted disk config
     * @throws ApiException If decryption fails (tampered or wrong secret)
     */
    public static function decrypt(string $blob, string $secret): array
    {
        $key = self::deriveKey($secret);
        $raw = base64_decode($blob, true);

        if ($raw === false || strlen($raw) < self::NONCE_LEN + self::TAG_LEN + 1) {
            throw new ApiException('Invalid BYOB credential blob', 401);
        }

        $nonce = substr($raw, 0, self::NONCE_LEN);
        $tag = substr($raw, -self::TAG_LEN);
        $ciphertext = substr($raw, self::NONCE_LEN, -self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($plaintext === false) {
            throw new ApiException('BYOB credential decryption failed — token may be tampered', 401);
        }

        $config = json_decode($plaintext, true);

        if (!is_array($config)) {
            throw new ApiException('Invalid BYOB credential format', 401);
        }

        return $config;
    }

    /**
     * Validate a decrypted BYOB disk config.
     *
     * @param string $diskName Name of the BYOB disk
     * @param array  $config   Decrypted config array
     * @throws ApiException If validation fails
     * @return string|null For an s3 disk with a custom endpoint that resolved, the
     *         first validated public IP — so the caller can pin the connection to
     *         it (see Claims::fromJwtPayload / DiskManager::build). Null otherwise.
     */
    public static function validate(string $diskName, array $config): ?string
    {
        $driver = $config['driver'] ?? '';

        // CRITICAL: Never allow BYOB local driver — prevents path traversal attacks
        if ($driver === 'local') {
            throw new ApiException(
                "BYOB disk '{$diskName}' cannot use local driver — only S3-compatible storage is allowed",
                403
            );
        }

        // BYOB SFTP: a user's own SFTP server (VPS). Host is SSRF-checked so the
        // config can't aim the server at internal infrastructure.
        if ($driver === 'sftp') {
            if (empty($config['host'])) {
                throw new ApiException("BYOB disk '{$diskName}' is missing 'host'", 400);
            }
            if (empty($config['username'])) {
                throw new ApiException("BYOB disk '{$diskName}' is missing 'username'", 400);
            }
            if (empty($config['password']) && empty($config['private_key'])) {
                throw new ApiException("BYOB disk '{$diskName}' needs a 'password' or 'private_key'", 400);
            }
            if (\class_exists('\\FluxFiles\\SsrfGuard')) {
                SsrfGuard::assertHostSafe((string) $config['host']);
            }
            return null;
        }

        if ($driver !== 's3') {
            throw new ApiException(
                "BYOB disk '{$diskName}' has unsupported driver: {$driver}",
                400
            );
        }

        // Required fields for S3-compatible storage
        if (empty($config['bucket'])) {
            throw new ApiException("BYOB disk '{$diskName}' is missing 'bucket'", 400);
        }
        if (empty($config['key'])) {
            throw new ApiException("BYOB disk '{$diskName}' is missing 'key'", 400);
        }
        if (empty($config['secret'])) {
            throw new ApiException("BYOB disk '{$diskName}' is missing 'secret'", 400);
        }

        // Custom endpoints (MinIO/R2/etc.) must not point at internal infrastructure —
        // otherwise a BYOB config could turn the server into an SSRF proxy (e.g. cloud
        // metadata at 169.254.169.254, or internal hosts behind the firewall).
        if (!empty($config['endpoint'])) {
            // Return the IP this same check already resolved so the caller can pin
            // the S3 client's connection to it (CURLOPT_RESOLVE) instead of letting
            // curl re-resolve the host at actual-connect time, which could be much
            // later in the request — closing the DNS-rebinding TOCTOU window without
            // any extra DNS lookup (see Claims::fromJwtPayload / DiskManager::build()).
            return self::assertSafeEndpoint($diskName, (string) $config['endpoint']);
        }
        return null;
    }

    /**
     * Reject endpoints whose host resolves to a loopback, link-local, private or
     * otherwise-reserved address — the classic SSRF targets. Runs on every request
     * (Claims::fromJwtPayload calls validate() per-decode, not just at token-mint
     * time) so a malicious BYOB config never reaches the S3 client. Returns the
     * first validated public IP for the caller to pin the connection to.
     */
    private static function assertSafeEndpoint(string $diskName, string $endpoint): ?string
    {
        $parts = parse_url($endpoint);
        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            throw new ApiException("BYOB disk '{$diskName}' has a malformed endpoint", 400);
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new ApiException("BYOB disk '{$diskName}' endpoint must use http(s)", 400);
        }

        $host = strtolower(trim($parts['host'], '[]')); // strip IPv6 brackets

        // Obvious internal names.
        if ($host === 'localhost' || substr($host, -10) === '.localhost' || substr($host, -6) === '.local') {
            throw new ApiException("BYOB disk '{$diskName}' endpoint host is not allowed", 403, 'endpoint_blocked');
        }

        // Resolve every form (literal / numeric / A+AAAA) and reject if any maps
        // to a non-public address. Shared with the URL-import SSRF guard so the
        // denylist (incl. CGNAT, IPv6 ULA, IPv4-mapped IPv6) stays in one place.
        // An empty result (host didn't resolve here, e.g. offline test env) is
        // intentionally NOT treated as a violation — same as before this method
        // started returning a value — it just means there's nothing to pin to.
        $ips = SsrfGuard::resolveHostIps($host);
        foreach ($ips as $ip) {
            if (!SsrfGuard::isPublicIp($ip)) {
                throw new ApiException(
                    "BYOB disk '{$diskName}' endpoint resolves to a blocked (internal) address",
                    403,
                    'endpoint_blocked'
                );
            }
        }
        return $ips[0] ?? null;
    }

    /**
     * Derive an encryption key from the JWT secret using HKDF.
     * Separate from signing key to provide defense-in-depth.
     */
    private static function deriveKey(string $secret): string
    {
        return hash_hkdf('sha256', $secret, 32, self::HKDF_INFO);
    }
}
