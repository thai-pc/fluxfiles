<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

/**
 * Signs a FluxFiles license key with the offline Ed25519 private key — the SAME
 * format `scripts/license-gen.php` produces and `LicenseManager` verifies:
 *
 *     base64url(header).base64url(payload).base64url(ed25519_sig)
 *     header  = {"alg":"Ed25519","kid":"k1"}
 *     payload = {customer,edition,modules[],enforcement,issued,[expires,grace],jti}
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
     *              ttlDays?:?int,graceDays?:int,extendFrom?:int,issuedAtLeast?:int} $opts
     * @return array{key:string,payload:array<string,mixed>}
     */
    public function mint(array $opts): array
    {
        // issuedAtLeast keeps `issued` strictly monotonic within a subscription/chain even if
        // this host's clock stepped backwards: the refresh client rejects a key that is not
        // newer than the one it holds, so a regressed `issued` would strand the customer.
        $now = max(time(), (int) ($opts['issuedAtLeast'] ?? 0));
        // An explicit null ttlDays = lifetime (no expiry); use array_key_exists so
        // `?? 365` doesn't turn the intentional null into an annual expiry.
        $ttlDays = array_key_exists('ttlDays', $opts) ? $opts['ttlDays'] : 365;
        $payload = [
            'customer'    => (string) ($opts['customer'] ?? ''),
            'edition'     => (string) ($opts['edition'] ?? 'pro'),
            'modules'     => array_values(array_map('strval', $opts['modules'] ?? [])),
            'enforcement' => (($opts['enforcement'] ?? 'perpetual') === 'subscription') ? 'subscription' : 'perpetual',
            'issued'      => $now,
            'jti'         => bin2hex(random_bytes(12)), // unique id for tracking/revoke
        ];
        if ($ttlDays !== null) {
            // extendFrom = a renewal stacks on the unexpired remainder of the licence it
            // renews, so renewing early never forfeits the days already paid for.
            $start = max($now, (int) ($opts['extendFrom'] ?? 0));   // $now >= issuedAtLeast, so expiry is monotonic too
            $payload['expires'] = $start + ((int) $ttlDays) * 86400;
            // Zero is meaningful for perpetual plans: no billing-recovery state is
            // needed when expiry only ends updates/support.
            $payload['grace']   = max(0, (int) ($opts['graceDays'] ?? 14)) * 86400;
        }
        $h = self::b64url((string) json_encode(['alg' => 'Ed25519', 'kid' => $this->kid]));
        $p = self::b64url((string) json_encode($payload));
        $sig = self::b64url(sodium_crypto_sign_detached($h . '.' . $p, $this->secret));
        return ['key' => $h . '.' . $p . '.' . $sig, 'payload' => $payload];
    }

    /**
     * Verify a presented key against THIS signer's key pair, with no DB access: Ed25519
     * over `header.payload` with the public key derived from the secret, `alg` Ed25519
     * and the signer's own `kid`. Returns the payload, or null for anything malformed,
     * oversize or not signed by us. Anyone holding core's public key can compute this
     * answer, so it leaks nothing.
     *
     * @return array<string,mixed>|null
     */
    public function verify(#[\SensitiveParameter] string $key): ?array
    {
        if ($key === '' || strlen($key) > 4096) {
            return null;
        }
        $parts = explode('.', $key);
        if (count($parts) !== 3) {
            return null;
        }
        $header = json_decode((string) self::unb64url($parts[0]), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'Ed25519' || ($header['kid'] ?? null) !== $this->kid) {
            return null;
        }
        $sig = self::unb64url($parts[2]);
        if ($sig === null || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }
        $pub = sodium_crypto_sign_publickey_from_secretkey($this->secret);
        if (!sodium_crypto_sign_verify_detached($sig, $parts[0] . '.' . $parts[1], $pub)) {
            return null;
        }
        $payload = json_decode((string) self::unb64url($parts[1]), true);
        return is_array($payload) && is_string($payload['jti'] ?? null) && $payload['jti'] !== '' ? $payload : null;
    }

    private static function unb64url(string $s): ?string
    {
        $d = base64_decode(strtr($s, '-_', '+/'), true);
        return $d === false ? null : $d;
    }

    private static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
