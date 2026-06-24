<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Offline license verifier for the commercial editions (Pro / AI / Enterprise).
 *
 * This class lives in the MIT core and only ever VERIFIES a signed license — it
 * embeds FluxFiles' Ed25519 **public** key(s). The private signing key never ships
 * (it lives offline; `license-gen` uses it to mint keys). Verification is fully
 * offline (no phone-home), so it works on an air-gapped VPS.
 *
 * License key format (compact, JWT-like, base64url, no padding):
 *
 *     base64url(headerJson) . base64url(payloadJson) . base64url(ed25519_sig)
 *
 *   header  = {"alg":"Ed25519","kid":"k1"}
 *   payload = {customer, edition, modules[], limits{}, domains[], issued, expires, grace}
 *   sig     = Ed25519 over the ASCII "base64url(header).base64url(payload)"
 *
 * Absent / malformed / bad-signature / unknown-kid → treated as the free edition
 * (no modules), never an error: the MIT core must run fine with no license at all.
 */
class LicenseManager
{
    /**
     * Embedded Ed25519 PUBLIC keys (base64), keyed by `kid`, for signature
     * rotation. The matching private keys are kept offline and never committed.
     *
     * @var array<string,string>
     */
    private const PUBLIC_KEYS = [
        'k1' => 'XQ53L9xJZJrIERVbvTHnlZ/pfYJJspUSsbu3330G3vk=',
    ];

    /** Default grace window (seconds) after `expires` when `grace` is unset. */
    private const DEFAULT_GRACE = 14 * 86400; // 14 days

    /** @var array<string,mixed> Verified payload, or [] when free/invalid. */
    private array $claims = [];

    /** True only when a syntactically valid, correctly-signed license was parsed. */
    private bool $verified = false;

    private int $now;

    /**
     * @param string|null              $key        the FLUXFILES_LICENSE_KEY value
     * @param array<string,string>|null $publicKeys override key map (tests); null = embedded
     */
    public function __construct(?string $key, ?array $publicKeys = null, ?int $now = null)
    {
        $this->now = $now ?? time();
        $keys = $publicKeys ?? self::PUBLIC_KEYS;

        $parsed = self::verify($key, $keys);
        if ($parsed !== null) {
            $this->claims = $parsed;
            $this->verified = true;
        }
    }

    /** Build from an environment array (defaults to $_ENV). */
    public static function fromEnv(?array $env = null, ?int $now = null): self
    {
        $env = $env ?? $_ENV;
        return new self(isset($env['FLUXFILES_LICENSE_KEY']) ? (string) $env['FLUXFILES_LICENSE_KEY'] : null, null, $now);
    }

    /**
     * Parse + verify a license key. Returns the payload array on success, or null
     * for anything invalid (so the caller falls back to free). Never throws.
     *
     * @param array<string,string> $publicKeys
     * @return array<string,mixed>|null
     */
    private static function verify(?string $key, array $publicKeys): ?array
    {
        if (!is_string($key) || $key === '' || !function_exists('sodium_crypto_sign_verify_detached')) {
            return null;
        }
        $parts = explode('.', trim($key));
        if (count($parts) !== 3) {
            return null;
        }
        [$h64, $p64, $s64] = $parts;
        $headerJson = self::b64urlDecode($h64);
        $payloadJson = self::b64urlDecode($p64);
        $sig = self::b64urlDecode($s64);
        if ($headerJson === null || $payloadJson === null || $sig === null) {
            return null;
        }

        $header = json_decode($headerJson, true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'Ed25519') {
            return null;
        }
        $kid = (string) ($header['kid'] ?? '');
        $pubB64 = $publicKeys[$kid] ?? null;
        if ($pubB64 === null) {
            return null; // unknown signing key id
        }
        $pub = base64_decode($pubB64, true);
        if ($pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }

        // Signature covers the exact "header.payload" base64url string.
        $message = $h64 . '.' . $p64;
        if (!sodium_crypto_sign_verify_detached($sig, $message, $pub)) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }
        return $payload;
    }

    // ── Public API ──────────────────────────────────────────────────────────

    /** free | pro | agency | enterprise (free when unlicensed/invalid). */
    public function edition(): string
    {
        if (!$this->verified) {
            return 'free';
        }
        $e = (string) ($this->claims['edition'] ?? 'free');
        return $e !== '' ? $e : 'free';
    }

    /** @return string[] the licensed module ids (empty when free). */
    public function modules(): array
    {
        if (!$this->verified || !isset($this->claims['modules']) || !is_array($this->claims['modules'])) {
            return [];
        }
        return array_values(array_map('strval', $this->claims['modules']));
    }

    /**
     * Enforcement model: 'perpetual' (annual/lifetime self-host — runtime keeps
     * working after expiry, only UPDATES stop) or 'subscription' (monthly/hosted —
     * the module disables after the grace window). Default 'perpetual', so a
     * customer who paid is never left with a feature switched off by accident.
     */
    public function enforcement(): string
    {
        $e = (string) ($this->claims['enforcement'] ?? 'perpetual');
        return $e === 'subscription' ? 'subscription' : 'perpetual';
    }

    /**
     * Is $module entitled AND still serviceable? The actual code must ALSO be
     * installed (class_exists) — this is layer 2 only.
     *
     * - subscription: stops working once expired past grace (recurring enforcement).
     * - perpetual: keeps working forever; `expires` only bounds the UPDATE window
     *   (see {@see updatesAllowed()}), matching how JetBrains/Sidekiq/most WP plugins
     *   sell a paid self-host module.
     */
    public function licensed(string $module): bool
    {
        if (!$this->verified || !in_array($module, $this->modules(), true)) {
            return false;
        }
        if ($this->enforcement() === 'subscription') {
            return !$this->hardExpired();
        }
        return true; // perpetual — runs forever (expiry only gates updates)
    }

    /**
     * May this license still pull NEW builds (the download/Composer channel)?
     * False once past expiry — perpetual installs keep running the version they have,
     * they just can't update until they renew.
     */
    public function updatesAllowed(): bool
    {
        return $this->verified && !$this->expired();
    }

    /** Expiry timestamp (unix), or null if perpetual/unset. */
    public function expiresAt(): ?int
    {
        $e = $this->claims['expires'] ?? null;
        return is_numeric($e) ? (int) $e : null;
    }

    /** Past the expiry date (regardless of grace)? */
    public function expired(): bool
    {
        $exp = $this->expiresAt();
        return $exp !== null && $this->now > $exp;
    }

    /** Past expiry but still inside the grace window? */
    public function inGrace(): bool
    {
        $exp = $this->expiresAt();
        if ($exp === null || $this->now <= $exp) {
            return false;
        }
        $grace = isset($this->claims['grace']) && is_numeric($this->claims['grace'])
            ? (int) $this->claims['grace'] : self::DEFAULT_GRACE;
        return $this->now <= ($exp + $grace);
    }

    /** Expired beyond the grace window — modules must disable (402). */
    public function hardExpired(): bool
    {
        return $this->expired() && !$this->inGrace();
    }

    /**
     * free | active | grace | expired | perpetual — for UIs.
     * 'perpetual' = past expiry but still fully functional (annual/lifetime self-host);
     * updates are off until renewal. 'expired' = a subscription that has shut off.
     */
    public function status(): string
    {
        if (!$this->verified) {
            return 'free';
        }
        if (!$this->expired()) {
            return 'active';
        }
        if ($this->inGrace()) {
            return 'grace';
        }
        // Past grace: a perpetual licence still runs (updates off); a subscription stops.
        return $this->enforcement() === 'perpetual' ? 'perpetual' : 'expired';
    }

    /** Whole days until expiry (negative once past). Null if perpetual. */
    public function daysLeft(): ?int
    {
        $exp = $this->expiresAt();
        return $exp === null ? null : (int) floor(($exp - $this->now) / 86400);
    }

    /** @return array<string,int> e.g. ['sites'=>5,'seats'=>0] */
    public function limits(): array
    {
        if (!$this->verified || !isset($this->claims['limits']) || !is_array($this->claims['limits'])) {
            return [];
        }
        $out = [];
        foreach ($this->claims['limits'] as $k => $v) {
            $out[(string) $k] = (int) $v;
        }
        return $out;
    }

    /** A non-sensitive summary for the /api/fm/license endpoint + dashboards. */
    public function info(): array
    {
        return [
            'edition'        => $this->edition(),
            'status'         => $this->status(),
            'enforcement'    => $this->enforcement(),
            'modules'        => $this->modules(),
            'limits'         => $this->limits(),
            'expires'        => $this->expiresAt(),
            'days_left'      => $this->daysLeft(),
            'updates_allowed' => $this->updatesAllowed(),
        ];
    }

    // ── base64url helpers ───────────────────────────────────────────────────

    private static function b64urlDecode(string $s): ?string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($s, true);
        return $out === false ? null : $out;
    }
}
