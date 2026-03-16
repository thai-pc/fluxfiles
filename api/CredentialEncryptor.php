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
     */
    public static function validate(string $diskName, array $config): void
    {
        $driver = $config['driver'] ?? '';

        // CRITICAL: Never allow BYOB local driver — prevents path traversal attacks
        if ($driver === 'local') {
            throw new ApiException(
                "BYOB disk '{$diskName}' cannot use local driver — only S3-compatible storage is allowed",
                403
            );
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
