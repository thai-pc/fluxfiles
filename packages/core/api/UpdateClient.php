<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Self-hosted update client for the commercial modules. No third-party platform:
 * the vendor runs a tiny update server (see docs/update-server.example.php) that
 * verifies the license and returns a SIGNED manifest pointing at the module zip.
 *
 * Trust chain (offline-verifiable, like LicenseManager):
 *   1. The manifest is an Ed25519-signed token (header.payload.sig) — the client
 *      verifies it with the embedded RELEASE public key, so a compromised server or
 *      a MITM can't feed a malicious version/URL.
 *   2. The manifest carries the zip's sha256; the client re-hashes the downloaded
 *      bytes and refuses a mismatch. So the zip's integrity is anchored to the
 *      signature even when it's served from a plain CDN/GitHub release.
 *
 * The runtime never *requires* this — modules already run offline (perpetual
 * licences keep working without the network). This only pulls NEW builds.
 */
final class UpdateClient
{
    /**
     * Embedded Ed25519 RELEASE public key(s) (base64), keyed by `kid`. The matching
     * private key signs manifests offline and never ships. Replace at release time.
     *
     * @var array<string,string>
     */
    private const RELEASE_PUBLIC_KEYS = [
        // Set at release time; matching private key signs manifests offline (gitignored).
        'r1' => 'v0LL3IVHN7DfqW8P4cp271O2O46NhBz6tOBictUHIhM=',
    ];

    /** @var array<string,string> */
    private array $keys;

    /** @param array<string,string>|null $publicKeys override (tests); null = embedded */
    public function __construct(?array $publicKeys = null)
    {
        $this->keys = $publicKeys ?? array_filter(self::RELEASE_PUBLIC_KEYS);
    }

    /**
     * Verify a signed manifest token and return its payload, or null when invalid
     * (bad signature / unknown key / malformed / expired). Never throws.
     *
     * Token = base64url(headerJson).base64url(payloadJson).base64url(sig), where
     * header = {"alg":"Ed25519","kid":"r1"} and payload carries at least
     * {module, version, url, sha256}.
     *
     * @return array<string,mixed>|null
     */
    public function verifyManifest(string $token, ?int $now = null): ?array
    {
        if ($token === '' || !function_exists('sodium_crypto_sign_verify_detached')) {
            return null;
        }
        $parts = explode('.', trim($token));
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
        $pubB64 = $this->keys[(string) ($header['kid'] ?? '')] ?? null;
        if ($pubB64 === null || $pubB64 === '') {
            return null;
        }
        $pub = base64_decode($pubB64, true);
        if ($pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }
        if (!sodium_crypto_sign_verify_detached($sig, $h64 . '.' . $p64, $pub)) {
            return null;
        }
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload) || ($payload['url'] ?? '') === '' || ($payload['sha256'] ?? '') === '') {
            return null;
        }
        // Optional freshness bound so an old manifest can't be replayed forever.
        if (isset($payload['expires']) && is_numeric($payload['expires']) && ($now ?? time()) > (int) $payload['expires']) {
            return null;
        }
        return $payload;
    }

    /** True when $bytes hash to the manifest's sha256 (timing-safe compare). */
    public static function verifyZip(string $bytes, string $sha256): bool
    {
        $expected = strtolower(trim($sha256));
        return $expected !== '' && hash_equals($expected, hash('sha256', $bytes));
    }

    /**
     * True when a manifest version is newer than the installed one (semver-ish:
     * numeric dotted compare, tolerant of a leading 'v').
     */
    public static function isNewer(string $candidate, string $current): bool
    {
        $norm = static fn (string $v): string => ltrim(trim($v), 'vV');
        return version_compare($norm($candidate), $norm($current), '>');
    }

    /**
     * Extract a verified module zip into $destDir (replacing its contents). Guards
     * against path traversal (Zip Slip) — every entry must resolve inside $destDir.
     * Writes to a temp dir first, then swaps, so a half-extracted zip never replaces
     * a working install. Throws ApiException on any failure.
     */
    public function install(string $zipBytes, string $destDir): void
    {
        if (!class_exists('ZipArchive')) {
            throw new ApiException('ext-zip is required to install updates', 500, 'zip_unavailable');
        }
        $tmpZip = tempnam(sys_get_temp_dir(), 'ffupd_');
        $stage = $tmpZip . '_x';
        try {
            if ($tmpZip === false || @file_put_contents($tmpZip, $zipBytes) === false) {
                throw new ApiException('Cannot stage update', 500, 'update_failed');
            }
            $zip = new \ZipArchive();
            if ($zip->open($tmpZip) !== true) {
                throw new ApiException('Downloaded update is not a valid zip', 422, 'update_corrupt');
            }
            @mkdir($stage, 0755, true);
            $stageReal = realpath($stage) ?: $stage;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/')) {
                    $zip->close();
                    throw new ApiException('Unsafe path in update zip', 422, 'update_unsafe');
                }
            }
            if (!$zip->extractTo($stage)) {
                $zip->close();
                throw new ApiException('Failed to extract update', 500, 'update_failed');
            }
            $zip->close();
            // Belt-and-braces: nothing escaped the stage dir.
            foreach (self::walk($stage) as $path) {
                if (strncmp((string) realpath($path), $stageReal, strlen($stageReal)) !== 0) {
                    throw new ApiException('Unsafe path in update zip', 422, 'update_unsafe');
                }
            }
            // Swap: remove old dest, move stage into place.
            if (is_dir($destDir)) {
                self::rrmdir($destDir);
            }
            @mkdir(dirname($destDir), 0755, true);
            if (!@rename($stage, $destDir)) {
                throw new ApiException('Failed to activate update', 500, 'update_failed');
            }
        } finally {
            if (is_string($tmpZip)) {
                @unlink($tmpZip);
            }
            if (is_dir($stage)) {
                self::rrmdir($stage);
            }
        }
    }

    /** @return iterable<string> every file path under $dir */
    private static function walk(string $dir): iterable
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            yield $f->getPathname();
        }
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

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
