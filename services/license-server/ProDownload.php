<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

/**
 * First-install delivery of the FluxFiles Pro WordPress zip.
 *
 * The buyer needs the zip BEFORE they have a plugin that can update itself, so the
 * checkout success page (/claim) shows a download link, and the licence e-mail points at the
 * landing /download page, which calls POST /pro-download for a fresh one. The link is NOT
 * minted here: the licence server has no download secret. It asks the update server for
 * the signed manifest of `fluxfiles-pro` exactly as the plugin's updater would (the
 * licence travels in the Authorization header over https, never in a URL) and hands on
 * only the manifest's `url`, an nginx secure_link URL that expires. So entitlement, the
 * update window and the revocation check stay in ONE place, the update server.
 *
 *   FLUXFILES_PRO_UPDATE_URL   base URL of the update server, e.g. https://fluxfiles-updates.thai-pc.com
 *                              (unset = feature off: /claim omits the link, /pro-download answers 404 and
 *                              the mail does not mention the download page)
 *
 * Every failure returns null: a missing download link must never fail a purchase, a
 * /claim or a mail.
 */
final class ProDownload
{
    /** The modules the zip carries; a licence needs ANY of them (mirrors the update server's $BUNDLES). */
    public const BUNDLE_MODULES = ['share', 'intake'];

    /**
     * Requested link lifetime (also the /claim window). The e-mail does NOT carry a link:
     * it points at the landing /download page, where the buyer pastes the key for a fresh one.
     */
    public const TTL_LINK = 3600;
    /** A cached link is reused only while it still has this long to live. */
    public const MIN_LEFT = 600;
    /** /claim must not hold a licence worker for long. */
    public const TIMEOUT_CLAIM = 3;
    public const TIMEOUT_DOWNLOAD = 8;

    /** @param array<string,mixed> $record a LicenseStore row */
    public static function eligible(array $record): bool
    {
        $mods = array_filter(array_map('trim', explode(',', (string) ($record['modules'] ?? ''))));
        return array_intersect($mods, self::BUNDLE_MODULES) !== [];
    }

    /**
     * @param array<string,mixed> $record a LicenseStore row (needs license_key + modules)
     * @param null|callable(string,array<int,string>):array{0:int,1:string} $http test seam: (url, headers) => [status, body]
     * @return array{url:string,version:string,expires_in:int}|null
     */
    public static function fetch(array $record, int $ttl, ?callable $http = null, ?string $base = null, int $timeout = self::TIMEOUT_DOWNLOAD): ?array
    {
        $base = rtrim($base ?? (string) getenv('FLUXFILES_PRO_UPDATE_URL'), '/');
        $key = (string) ($record['license_key'] ?? '');
        if ($base === '' || $key === '' || !self::eligible($record) || !self::safeUrl($base)) {
            return null;
        }
        $ttl = max(300, min($ttl, 7 * 86400));
        $url = $base . '/update/fluxfiles-pro?install=1&link_ttl=' . $ttl;
        $headers = ['Authorization: Bearer ' . $key, 'Accept: text/plain'];
        try {
            [$code, $body] = $http !== null ? $http($url, $headers) : self::get($url, $headers, $timeout);
        } catch (\Throwable) {
            return null;
        }
        if ($code !== 200) {
            error_log('pro download: update server answered HTTP ' . $code);
            return null;
        }
        $parts = explode('.', trim($body));
        if (count($parts) !== 3) {
            return null;
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $payload = $json === false ? null : json_decode($json, true);
        // The signature is not checked here (we hold no release public key and the hop
        // is our own server over https); the download itself is held to the signed
        // sha256 by the plugin's updater, and nginx refuses a tampered secure_link.
        if (!is_array($payload) || ($payload['module'] ?? '') !== 'fluxfiles-pro') {
            return null;
        }
        $dl = (string) ($payload['url'] ?? '');
        if ($dl === '' || !self::safeUrl($dl) || !str_contains($dl, '.zip')) {
            return null;
        }
        return ['url' => $dl, 'version' => (string) ($payload['version'] ?? ''), 'expires_in' => self::secondsLeft($dl, $ttl)];
    }

    /**
     * Seconds the link is really good for: the secure_link `expires` parameter, never the
     * TTL we merely asked for (a server that ignores link_ttl signs whatever it likes).
     * 0 when the URL carries no expiry (it does not expire, or we cannot tell).
     */
    public static function secondsLeft(string $url, int $fallback = 0, ?int $now = null): int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        if (isset($q['expires']) && ctype_digit((string) $q['expires'])) {
            return max(0, (int) $q['expires'] - ($now ?? time()));
        }
        return $fallback;
    }

    /**
     * fetch() behind a per-licence cache in the licence DB, so /claim polling, reloads and
     * /pro-download retries do not each cost an update-server round trip.
     *
     * @param array<string,mixed> $record
     * @return array{url:string,version:string,expires_in:int}|null
     */
    public static function linkFor(array $record, \FluxFiles\LicenseServer\LicenseStore $store, int $timeout, ?callable $http = null, ?string $base = null): ?array
    {
        $jti = (string) ($record['jti'] ?? '');
        if ($jti !== '' && ($c = $store->cachedLink($jti, self::MIN_LEFT)) !== null) {
            return ['url' => $c['url'], 'version' => $c['version'], 'expires_in' => max(0, $c['expires_at'] - time())];
        }
        $d = self::fetch($record, self::TTL_LINK, $http, $base, $timeout);
        if ($d !== null && $jti !== '' && $d['expires_in'] > self::MIN_LEFT) {
            $store->cacheLink($jti, $d['url'], $d['version'], time() + $d['expires_in']);
        }
        return $d;
    }

    /** Is the feature configured at all (the update server base URL is a safe URL)? */
    public static function configured(): bool
    {
        $b = rtrim((string) getenv('FLUXFILES_PRO_UPDATE_URL'), '/');
        return $b !== '' && self::safeUrl($b);
    }

    /** Throttle key for a client address: IPv4 as is, IPv6 collapsed to its /64 (a user owns a whole /64). */
    public static function ipBucket(string $ip): string
    {
        $bin = @inet_pton($ip);
        // ::ffff:a.b.c.d is an IPv4 client on a dual-stack listener: bucket it as the IPv4
        // address, not as the shared /64 of every mapped client.
        // NAT64 (64:ff9b::/96) carries an IPv4 client the same way.
        if ($bin !== false && strlen($bin) === 16
            && (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff") || str_starts_with($bin, "\0\x64\xff\x9b" . str_repeat("\0", 8)))) {
            return (string) inet_ntop(substr($bin, 12));
        }
        if ($bin !== false && strlen($bin) === 16) {
            return bin2hex(substr($bin, 0, 8)) . '/64';
        }
        return $ip;
    }

    /** https, or plain http to the local machine only (tests). */
    private static function safeUrl(string $url): bool
    {
        $p = parse_url($url);
        if (!is_array($p) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        return $scheme === 'https'
            || ($scheme === 'http' && in_array(strtolower(trim((string) $p['host'], '[]')), ['127.0.0.1', 'localhost', '::1'], true));
    }

    /** @param array<int,string> $headers @return array{0:int,1:string} */
    private static function get(string $url, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return [$code, is_string($body) ? $body : ''];
    }
}
