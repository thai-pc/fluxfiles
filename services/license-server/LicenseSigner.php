<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

/**
 * Signs a FluxFiles license key with the offline Ed25519 private key — the SAME
 * format `scripts/license-gen.php` produces and `LicenseManager` verifies:
 *
 *     base64url(header).base64url(payload).base64url(ed25519_sig)
 *     header  = {"alg":"Ed25519","kid":"k1"}
 *     payload = {customer,edition,modules[],enforcement,limits{},issued,[expires,grace],[domains],jti}
 *
 * The private key is the 64-byte Ed25519 secret (base64), loaded from a file
 * (FLUXFILES_LICENSE_PRIVATE_KEY_FILE) or the raw env (FLUXFILES_LICENSE_PRIVATE_KEY).
 * It NEVER ships in the product — this service is the vendor back-office.
 */
final class LicenseSigner
{
    private string $secret; // 64-byte Ed25519 secret key (binary)
    private string $kid;

    public function __construct(?string $secretB64 = null, string $kid = 'k1')
    {
        $secretB64 = $secretB64 ?? self::loadSecretB64();
        $secret = base64_decode(trim($secretB64), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('Invalid signing key (need base64 of a 64-byte Ed25519 secret key)');
        }
        $this->secret = $secret;
        $this->kid = $kid;
    }

    /** Load the secret from FLUXFILES_LICENSE_PRIVATE_KEY_FILE (path) or _KEY (raw). */
    private static function loadSecretB64(): string
    {
        $file = getenv('FLUXFILES_LICENSE_PRIVATE_KEY_FILE') ?: '';
        if ($file !== '' && is_file($file)) {
            return trim((string) file_get_contents($file));
        }
        $raw = getenv('FLUXFILES_LICENSE_PRIVATE_KEY') ?: '';
        if ($raw !== '') {
            return $raw;
        }
        throw new \RuntimeException('No signing key: set FLUXFILES_LICENSE_PRIVATE_KEY_FILE or FLUXFILES_LICENSE_PRIVATE_KEY');
    }

    /**
     * Mint a license. Returns ['key' => <signed key>, 'payload' => <array>].
     *
     * @param array{customer?:string,edition?:string,modules?:string[],enforcement?:string,
     *              sites?:int,ttlDays?:?int,graceDays?:int,domains?:string[]} $opts
     * @return array{key:string,payload:array<string,mixed>}
     */
    public function mint(array $opts): array
    {
        $now = time();
        // An explicit null ttlDays = lifetime (no expiry); use array_key_exists so
        // `?? 365` doesn't turn the intentional null into an annual expiry.
        $ttlDays = array_key_exists('ttlDays', $opts) ? $opts['ttlDays'] : 365;
        $payload = [
            'customer'    => (string) ($opts['customer'] ?? ''),
            'edition'     => (string) ($opts['edition'] ?? 'pro'),
            'modules'     => array_values(array_map('strval', $opts['modules'] ?? [])),
            'enforcement' => (($opts['enforcement'] ?? 'perpetual') === 'subscription') ? 'subscription' : 'perpetual',
            'limits'      => ['sites' => (int) ($opts['sites'] ?? 0)],
            'issued'      => $now,
            'jti'         => bin2hex(random_bytes(12)), // unique id for tracking/revoke
        ];
        if ($ttlDays !== null) {
            $payload['expires'] = $now + ((int) $ttlDays) * 86400;
            $payload['grace']   = ((int) ($opts['graceDays'] ?? 14)) * 86400;
        }
        if (!empty($opts['domains'])) {
            $payload['domains'] = array_values(array_map('strval', $opts['domains']));
        }

        $h = self::b64url((string) json_encode(['alg' => 'Ed25519', 'kid' => $this->kid]));
        $p = self::b64url((string) json_encode($payload));
        $sig = self::b64url(sodium_crypto_sign_detached($h . '.' . $p, $this->secret));
        return ['key' => $h . '.' . $p . '.' . $sig, 'payload' => $payload];
    }

    private static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
