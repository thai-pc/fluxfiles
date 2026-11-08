<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Licence key refresh, the pure half: ask the vendor licence server for the newest key of
 * the subscription behind the one in use, and decide whether to believe the answer.
 *
 * No file IO and no global state: the HTTP transport is injected (core passes
 * {@see CurlTransport}, WordPress Pro passes a wp_remote_post wrapper). Shipped
 * byte-identical in FluxFiles Pro's lib/, so it depends on {@see LicenseManager} only.
 *
 * The server is never trusted for anything but a vendor-signed key: {@see accept()}
 * re-verifies it against the embedded public key and refuses a downgrade by comparing
 * the SIGNED issued/expires values (never the local clock). The key travels in the
 * Authorization header only, never in a URL, argv, log line or exception message.
 */
final class LicenseRefresh
{
    public const DEFAULT_URL = 'https://fluxfiles-license.thai-pc.com';
    public const TIMEOUT = 10;
    /** Largest answer we read; a licence key is ~600 bytes. */
    public const MAX_RESPONSE = 16384;
    public const MAX_KEY_LEN = 4096;

    /** https, or plain http to the local machine only (a dev/test licence server). */
    public static function isSecureUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === 'https') {
            return true;
        }
        $host = strtolower(trim((string) $parts['host'], '[]'));
        return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * The licence server base URL: the configured one, else the vendor default. '' when the
     * configured value is not an acceptable URL (the caller reports a config error).
     */
    public static function baseUrl(?string $configured): string
    {
        $configured = trim((string) $configured);
        if ($configured === '') {
            return self::DEFAULT_URL;
        }
        $configured = rtrim($configured, '/');
        return self::isSecureUrl($configured) ? $configured : '';
    }

    /**
     * POST /refresh with the key as a Bearer credential.
     *
     * @param callable $http fn(string $method, string $url, array $headers, ?string $body, int $timeout):
     *                       array{status:int, headers:array<string,string>, body:string, error?:string}
     *                       (the {@see HttpTransportInterface::request()} shape; header names lower-case)
     * @return array{status:string, http:int, key?:string, retry_after?:int, error?:string}
     *         status: refreshed | current | invalid (401) | unavailable (404) | throttled (429) | error
     */
    public static function request(#[\SensitiveParameter] string $key, string $baseUrl, callable $http, string $client = 'core/unknown'): array
    {
        if (!self::isSecureUrl($baseUrl)) {
            return ['status' => 'error', 'http' => 0, 'error' => 'licence server URL must be https'];
        }
        $client = substr((string) preg_replace('/[^a-z0-9.\/-]/', '', strtolower($client)), 0, 64);
        $body = (string) json_encode(['v' => 1, 'client' => $client]);
        try {
            $res = $http('POST', rtrim($baseUrl, '/') . '/refresh', [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ], $body, self::TIMEOUT);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'http' => 0, 'error' => 'server unreachable'];
        }
        $code = (int) ($res['status'] ?? 0);
        $resBody = (string) ($res['body'] ?? '');
        if ($code === 0) {
            return ['status' => 'error', 'http' => 0, 'error' => 'server unreachable'];
        }
        if ($code === 429) {
            $ra = (string) ($res['headers']['retry-after'] ?? '');
            $out = ['status' => 'throttled', 'http' => 429];
            if (ctype_digit($ra)) {
                $out['retry_after'] = min((int) $ra, 86400);
            }
            return $out;
        }
        if ($code === 401) {
            return ['status' => 'invalid', 'http' => 401];
        }
        if ($code === 404) {
            return ['status' => 'unavailable', 'http' => 404];
        }
        if ($code !== 200) {
            return ['status' => 'error', 'http' => $code, 'error' => 'unexpected response (HTTP ' . $code . ')'];
        }
        if (strlen($resBody) > self::MAX_RESPONSE) {
            return ['status' => 'error', 'http' => 200, 'error' => 'response too large'];
        }
        $json = json_decode($resBody, true);
        if (!is_array($json)) {
            return ['status' => 'error', 'http' => 200, 'error' => 'malformed response'];
        }
        $st = $json['status'] ?? null;
        if ($st === 'current') {
            return ['status' => 'current', 'http' => 200];
        }
        if ($st === 'refreshed') {
            $k = is_string($json['license_key'] ?? null) ? (string) preg_replace('/\s+/', '', $json['license_key']) : '';
            if ($k === '' || strlen($k) > self::MAX_KEY_LEN || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $k) !== 1) {
                return ['status' => 'error', 'http' => 200, 'error' => 'malformed response'];
            }
            return ['status' => 'refreshed', 'http' => 200, 'key' => $k];
        }
        return ['status' => 'error', 'http' => 200, 'error' => 'malformed response'];
    }

    /**
     * May $candidate replace $current? Only when it verifies against the embedded public key,
     * is a paid edition, was issued strictly after $current and does not expire earlier.
     * Compares the signed values with each other, never with the local clock.
     */
    public static function accept(#[\SensitiveParameter] string $current, #[\SensitiveParameter] string $candidate): bool
    {
        $cur = new LicenseManager($current);
        $new = new LicenseManager($candidate);
        if (!$cur->isVerified() || !$new->isVerified() || $new->edition() === 'free') {
            return false;
        }
        $curIssued = $cur->issuedAt();
        $newIssued = $new->issuedAt();
        if ($newIssued === null || $newIssued <= ($curIssued ?? 0)) {
            return false;
        }
        $curExp = $cur->expiresAt();
        $newExp = $new->expiresAt();
        if ($curExp === null) {
            return $newExp === null; // an unlimited key is never swapped for a dated one
        }
        return $newExp === null || $newExp >= $curExp;
    }
}
