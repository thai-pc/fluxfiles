<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * SSRF guard for server-side outbound fetches (URL import, and the BYOB custom
 * endpoint check via CredentialEncryptor).
 *
 * The job: never let a user-supplied URL/host cause the server to connect to a
 * loopback, link-local, private, or otherwise-reserved address — the classic
 * SSRF targets (cloud metadata at 169.254.169.254, internal services, etc.).
 *
 * Layers:
 *   - assertSafeUrl()  — pre-fetch: parse, scheme allowlist, no creds, optional
 *                        host allowlist, resolve EVERY A/AAAA + numeric-obfuscated
 *                        form, and reject if any resolves to a non-public IP.
 *   - assertConnectedIpSafe() — post-connect: re-check the IP curl actually
 *                        connected to (defends DNS rebinding / redirect tricks).
 *   - isPublicIp()     — the shared IP denylist used by both this guard and the
 *                        BYOB endpoint check.
 */
final class SsrfGuard
{
    /**
     * Hosts ("host" or "host:port") allowed past the IP denylist — **TEST USE
     * ONLY**. There is no env / config / request path that populates this; only
     * in-process test code sets it (to reach a local fixture server). It is empty
     * in production, so SSRF enforcement is unconditional there.
     *
     * @var string[]
     */
    public static array $allowTestHosts = [];

    /**
     * True only for a genuinely public, routable address. Rejects loopback,
     * link-local, private (RFC1918 / ULA), CGNAT, the "this" network, cloud
     * metadata, and IPv4-mapped IPv6 wrappers around any of those.
     */
    public static function isPublicIp(string $ip): bool
    {
        $ip = trim($ip, '[]'); // strip IPv6 brackets if present

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // Explicit singletons the range filter misses / we always block.
            if ($ip === '169.254.169.254' || $ip === '0.0.0.0') {
                return false;
            }
            // RFC1918 + reserved (0.0.0.0/8, 127/8, 169.254/16, 192.0.2/24, …).
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
            // CGNAT 100.64.0.0/10 — NOT covered by PHP's reserved filter.
            $long = ip2long($ip);
            if ($long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
                return false;
            }
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($ip === '::1' || $ip === '::') {
                return false;
            }
            // IPv4-mapped IPv6 (::ffff:127.0.0.1) — unwrap and judge the v4 address.
            if (stripos($ip, '::ffff:') === 0) {
                $v4 = substr($ip, 7);
                if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return self::isPublicIp($v4);
                }
            }
            // Blocks ULA (fc00::/7), link-local (fe80::/10), and reserved ranges.
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
            return true;
        }

        return false; // not an IP at all
    }

    /**
     * All IP addresses a host could connect to: a literal IP, a numeric-obfuscated
     * IPv4 (decimal/hex), the OS resolver result, and every A + AAAA record.
     * Returns [] when nothing resolves.
     */
    public static function resolveHostIps(string $host): array
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '') {
            return [];
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        // Numeric obfuscation: http://2130706433/ or http://0x7f000001/ → 127.0.0.1
        $numeric = self::numericHostToIpv4($host);
        if ($numeric !== null) {
            $ips[] = $numeric;
        }

        // OS resolver — also normalizes some inet_aton obfuscations, plus the A record.
        $resolved = @gethostbyname($host);
        if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP)) {
            $ips[] = $resolved;
        }

        // Explicit A + AAAA (covers IPv6 / multi-record, which gethostbyname misses).
        foreach ([DNS_A, DNS_AAAA] as $type) {
            $records = @dns_get_record($host, $type) ?: [];
            foreach ($records as $r) {
                foreach (['ip', 'ipv6'] as $k) {
                    if (!empty($r[$k]) && filter_var($r[$k], FILTER_VALIDATE_IP)) {
                        $ips[] = $r[$k];
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /** Decimal/hex integer host → dotted IPv4, or null if not numeric. */
    private static function numericHostToIpv4(string $host): ?string
    {
        if (preg_match('/^\d+$/', $host)) {
            $n = (float) $host; // (float) avoids 32-bit int overflow on the comparison
            if ($n >= 0 && $n <= 4294967295) {
                return long2ip((int) $n);
            }
        }
        if (preg_match('/^0x[0-9a-f]+$/i', $host)) {
            $n = hexdec(substr($host, 2));
            if (is_int($n) || $n <= 4294967295) {
                return long2ip((int) $n);
            }
        }
        return null;
    }

    /** Glob host allowlist: exact or `*.example.com` (matches sub.example.com). */
    public static function hostMatchesAllowlist(string $host, array $patterns): bool
    {
        $host = strtolower(trim($host, '[]'));
        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern === '') {
                continue;
            }
            if ($pattern === $host) {
                return true;
            }
            if (strncmp($pattern, '*.', 2) === 0) {
                $suffix = substr($pattern, 1);            // ".example.com"
                $bare   = substr($pattern, 2);            // "example.com"
                if ($host === $bare) {
                    return true;                          // *.example.com also covers example.com
                }
                if (strlen($host) > strlen($suffix)
                    && substr($host, -strlen($suffix)) === $suffix
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Pre-fetch validation of a user-supplied URL. Throws ApiException on any
     * SSRF / policy violation; returns the resolved IPs on success.
     *
     * @param string[]|null $allowlist Optional host glob allowlist (JWT claim).
     */
    public static function assertSafeUrl(string $url, ?array $allowlist = null): array
    {
        $url = trim($url);
        $parts = @parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new ApiException('Invalid URL', 422, 'url_invalid');
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new ApiException('Only http(s) URLs can be imported', 422, 'url_scheme_denied');
        }
        // user:pass@host can smuggle credentials or confuse parsers — reject.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ApiException('Credentials in the URL are not allowed', 422, 'url_invalid');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === '') {
            throw new ApiException('Invalid URL', 422, 'url_invalid');
        }

        // Obvious internal names (some resolvers map these to loopback).
        if ($host === 'localhost'
            || self::hasSuffix($host, '.localhost')
            || self::hasSuffix($host, '.local')
            || self::hasSuffix($host, '.internal')
        ) {
            throw new ApiException('This URL host is not allowed', 422, 'ssrf_blocked');
        }

        if ($allowlist !== null && $allowlist !== []
            && !self::hostMatchesAllowlist($host, $allowlist)
        ) {
            throw new ApiException('This URL host is not in the import allowlist', 422, 'host_not_allowed');
        }

        // Test-only fixture bypass (see $allowTestHosts). Empty in production.
        if (self::$allowTestHosts !== []) {
            $hostPort = $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
            if (in_array($host, self::$allowTestHosts, true) || in_array($hostPort, self::$allowTestHosts, true)) {
                return [$host];
            }
        }

        $ips = self::resolveHostIps($host);
        if ($ips === []) {
            throw new ApiException('Could not resolve the URL host', 422, 'fetch_failed');
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new ApiException('URL resolves to a private or reserved address', 422, 'ssrf_blocked');
            }
        }
        return $ips;
    }

    /**
     * Assert a bare host (no scheme) resolves only to public addresses — the
     * SFTP-disk equivalent of assertSafeUrl, so an SFTP disk can't be aimed at a
     * loopback / RFC1918 / link-local / CGNAT / cloud-metadata target. Throws
     * ApiException(422, ssrf_blocked) otherwise. Returns the resolved IPs.
     *
     * @return list<string>
     */
    public static function assertHostSafe(string $host): array
    {
        $host = strtolower(trim($host, " \t[]"));
        if ($host === '') {
            throw new ApiException('SFTP host is required', 422, 'ssrf_blocked');
        }
        if ($host === 'localhost'
            || self::hasSuffix($host, '.localhost')
            || self::hasSuffix($host, '.local')
            || self::hasSuffix($host, '.internal')
        ) {
            throw new ApiException('This SFTP host is not allowed', 422, 'ssrf_blocked');
        }
        // Test-only fixture bypass (shared with assertSafeUrl). Empty in production.
        if (self::$allowTestHosts !== [] && in_array($host, self::$allowTestHosts, true)) {
            return [$host];
        }
        $ips = self::resolveHostIps($host);
        if ($ips === []) {
            throw new ApiException('Could not resolve the SFTP host', 422, 'ssrf_blocked');
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new ApiException('SFTP host resolves to a private or reserved address', 422, 'ssrf_blocked');
            }
        }
        return $ips;
    }

    /**
     * Post-connect re-check: the IP curl actually connected to must still be
     * public. Defends DNS rebinding and any redirect that slipped a private hop.
     *
     * @param \CurlHandle $ch
     */
    public static function assertConnectedIpSafe($ch): void
    {
        if (self::$allowTestHosts !== []) {
            return; // a test pinned a local fixture host
        }
        $ip = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        if ($ip !== '' && !self::isPublicIp($ip)) {
            throw new ApiException('Connection resolved to a private or reserved address', 422, 'ssrf_blocked');
        }
    }

    private static function hasSuffix(string $haystack, string $suffix): bool
    {
        return strlen($haystack) >= strlen($suffix)
            && substr($haystack, -strlen($suffix)) === $suffix;
    }
}
