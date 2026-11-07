<?php

declare(strict_types=1);

namespace FluxFiles;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON API exceptions: the message travels in the JSON error envelope and is never rendered as HTML
// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url -- core is a WordPress-independent library; wp_parse_url() does not exist outside WordPress

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
    // Separate key for single-string secrets (webhook_secret_enc) so a blob of one
    // kind can never be replayed as the other.
    private const HKDF_INFO_SECRET = 'fluxfiles-webhook-secret-enc';

    /**
     * Encrypt a disk config array into a base64-encoded blob.
     *
     * @param array  $config Disk config: driver, key, secret, bucket, region, endpoint
     * @param string $secret The FLUXFILES_SECRET (used to derive encryption key)
     * @return string Base64-encoded ciphertext (nonce + ciphertext + tag)
     */
    public static function encrypt(#[\SensitiveParameter] array $config, #[\SensitiveParameter] string $secret): string
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
     * Decrypt one BYOB disk's blob and validate it, ready for
     * DiskManager::registerByobDisk(). Used for the access JWT and for the per-file
     * media tokens, so both apply the same SSRF checks.
     *
     * @throws ApiException If the blob is bad or the config is refused
     */
    public static function openForDisk(string $diskName, string $blob, #[\SensitiveParameter] string $secret): array
    {
        $config = self::decrypt($blob, $secret);
        $pinnedIp = self::validate($diskName, $config);
        if ($pinnedIp !== null) {
            // See validate() — closes the DNS-rebinding TOCTOU between this check and
            // DiskManager's actual S3 connect.
            $config['_pinned_ip'] = $pinnedIp;
        }
        return $config;
    }

    /**
     * Decrypt a base64-encoded blob back into a disk config array.
     *
     * @param string $blob Base64-encoded encrypted blob
     * @param string $secret The FLUXFILES_SECRET
     * @return array Decrypted disk config
     * @throws ApiException If decryption fails (tampered or wrong secret)
     */
    public static function decrypt(string $blob, #[\SensitiveParameter] string $secret): array
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
     * @throws ApiException If validation fails, including an s3 disk whose custom
     *         endpoint host cannot be resolved at all (fail-closed — see
     *         assertSafeEndpoint()).
     * @return string|null For an s3 disk with a custom endpoint, the first
     *         validated public IP — so the caller can pin the connection to it
     *         (see Claims::fromJwtPayload / DiskManager::build). Null otherwise
     *         (no endpoint, or a non-s3 driver).
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
                SsrfGuard::assertHostSafe((string) $config['host'], (int) ($config['port'] ?? 22));
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
     * otherwise-reserved address — the classic SSRF targets — or that fail to
     * resolve at all (fail-closed; see below). Runs on every request
     * (Claims::fromJwtPayload calls validate() per-decode, not just at token-mint
     * time) so a malicious BYOB config never reaches the S3 client. Returns the
     * first validated public IP for the caller to pin the connection to.
     */
    private static function assertSafeEndpoint(string $diskName, string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            throw new ApiException("BYOB disk '{$diskName}' has a malformed endpoint", 400);
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new ApiException("BYOB disk '{$diskName}' endpoint must use http(s)", 400);
        }

        $host = strtolower(trim($parts['host'], '[]')); // strip IPv6 brackets

        // Listed in FLUXFILES_SSRF_ALLOW_HOSTS: waive the public-IP requirement for
        // this host only. Still fails closed unresolved, so the connection stays pinned.
        $port = (int) ($parts['port'] ?? (strtolower($parts['scheme']) === 'http' ? 80 : 443));
        if (SsrfGuard::isAllowlistedHost($host, $port)) {
            $ips = SsrfGuard::resolveHostIps($host);
            if ($ips === []) {
                throw new ApiException("BYOB disk '{$diskName}' endpoint host could not be resolved", 403, 'endpoint_unresolved');
            }
            return $ips[0];
        }

        // Obvious internal names.
        if ($host === 'localhost' || substr($host, -10) === '.localhost' || substr($host, -6) === '.local') {
            throw new ApiException("BYOB disk '{$diskName}' endpoint host is not allowed", 403, 'endpoint_blocked');
        }

        // Resolve every form (literal / numeric / A+AAAA) and reject if any maps
        // to a non-public address. Shared with the URL-import SSRF guard so the
        // denylist (incl. CGNAT, IPv6 ULA, IPv4-mapped IPv6) stays in one place.
        $ips = SsrfGuard::resolveHostIps($host);

        // FAIL CLOSED on an empty result. validate() runs fresh on every request
        // (Claims::fromJwtPayload decrypts + validates on every JWT decode), and
        // DiskManager::build() only pins the S3 client's connection (CURLOPT_RESOLVE)
        // when this method returns an IP — with no pin, curl does its own DNS
        // resolution at actual-connect time with zero post-connect IP check (unlike
        // UrlImporter, which re-verifies via assertConnectedIpSafe()). A tenant who
        // controls the endpoint host's authoritative DNS could otherwise make THIS
        // lookup fail/timeout on purpose while answering normally with an internal
        // address (e.g. 169.254.169.254) moments later when curl connects — a
        // DNS-rebinding TOCTOU that would silently strip all SSRF protection for
        // every operation on the disk. Refusing the config here (instead of letting
        // it through unpinned) closes that window: an attacker forcing decode-time
        // resolution failure just gets the request rejected outright.
        if ($ips === []) {
            throw new ApiException(
                "BYOB disk '{$diskName}' endpoint host could not be resolved",
                403,
                'endpoint_unresolved'
            );
        }

        foreach ($ips as $ip) {
            if (!SsrfGuard::isPublicIp($ip)) {
                throw new ApiException(
                    "BYOB disk '{$diskName}' endpoint resolves to a blocked (internal) address",
                    403,
                    'endpoint_blocked'
                );
            }
        }
        return $ips[0];
    }

    /**
     * Encrypt a single secret string (the `webhook_secret_enc` claim): a JWT is
     * signed, not encrypted, so a plaintext secret in it is readable by whoever
     * holds the token. Same AES-256-GCM blob layout as encrypt(), own HKDF key.
     */
    public static function encryptSecret(#[\SensitiveParameter] string $value, #[\SensitiveParameter] string $secret, ?string $nonce = null): string
    {
        $key = self::deriveKey($secret, self::HKDF_INFO_SECRET);
        // $nonce is for the committed cross-language test vector ONLY; production always random.
        $nonce ??= random_bytes(self::NONCE_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt($value, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, '', self::TAG_LEN);
        if ($ciphertext === false) {
            throw new \RuntimeException('Secret encryption failed');
        }
        return base64_encode($nonce . $ciphertext . $tag);
    }

    /**
     * Minter-side guard: move a plaintext `webhook_secret` (named option or the raw
     * `claims` escape hatch) into the sealed `webhook_secret_enc` claim so the HMAC key
     * never travels in readable form. Call on the payload right before encoding it.
     */
    public static function sealWebhookSecret(array $payload, #[\SensitiveParameter] string $secret): array
    {
        if (isset($payload['webhook_secret'])) {
            // '' = unset; "0" is a valid key and is sealed like any other (same in Node/Python).
            if ((string) $payload['webhook_secret'] !== '') {
                $payload['webhook_secret_enc'] = self::encryptSecret((string) $payload['webhook_secret'], $secret);
            }
            unset($payload['webhook_secret']);
        }
        return $payload;
    }

    /**
     * @throws ApiException If the blob is malformed or tampered
     */
    public static function decryptSecret(string $blob, #[\SensitiveParameter] string $secret): string
    {
        $key = self::deriveKey($secret, self::HKDF_INFO_SECRET);
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < self::NONCE_LEN + self::TAG_LEN + 1) {
            throw new ApiException('Invalid encrypted secret blob', 401, 'token_invalid');
        }
        $plaintext = openssl_decrypt(
            substr($raw, self::NONCE_LEN, -self::TAG_LEN),
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::NONCE_LEN),
            substr($raw, -self::TAG_LEN)
        );
        if ($plaintext === false) {
            throw new ApiException('Encrypted secret decryption failed — token may be tampered', 401, 'token_invalid');
        }
        return $plaintext;
    }

    /**
     * Derive an encryption key from the JWT secret using HKDF.
     * Separate from signing key to provide defense-in-depth.
     */
    private static function deriveKey(#[\SensitiveParameter] string $secret, string $info = self::HKDF_INFO): string
    {
        return hash_hkdf('sha256', $secret, 32, $info);
    }
}
