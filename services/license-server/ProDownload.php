<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer;

/**
 * First-install delivery of the FluxFiles Pro WordPress zip.
 *
 * The buyer needs the zip BEFORE they have a plugin that can update itself, so the
 * checkout success page and the licence e-mail carry a download link. The link is NOT
 * minted here: the licence server has no download secret. It asks the update server for
 * the signed manifest of `fluxfiles-pro` exactly as the plugin's updater would (the
 * licence travels in the Authorization header over https, never in a URL) and hands on
 * only the manifest's `url`, an nginx secure_link URL that expires. So entitlement, the
 * update window and the revocation check stay in ONE place, the update server.
 *
 *   FLUXFILES_PRO_UPDATE_URL   base URL of the update server, e.g. https://fluxfiles-updates.thai-pc.com
 *                              (unset = feature off, /claim and the mail simply omit the link)
 *
 * Every failure returns null: a missing download link must never fail a purchase, a
 * /claim or a mail.
 */
final class ProDownload
{
    /** The modules the zip carries; a licence needs ANY of them (mirrors the update server's $BUNDLES). */
    public const BUNDLE_MODULES = ['share', 'intake'];

    /** Link lifetime on the success page (matches the /claim window) and in the e-mail. */
    public const TTL_CLAIM = 3600;
    public const TTL_MAIL = 604800;

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
    public static function fetch(array $record, int $ttl, ?callable $http = null, ?string $base = null): ?array
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
            [$code, $body] = $http !== null ? $http($url, $headers) : self::get($url, $headers);
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
        return ['url' => $dl, 'version' => (string) ($payload['version'] ?? ''), 'expires_in' => $ttl];
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
    private static function get(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, is_string($body) ? $body : ''];
    }
}
