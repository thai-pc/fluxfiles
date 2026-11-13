<?php

defined('ABSPATH') || exit;

use FluxFiles\ApiException;
use FluxFiles\SsrfGuard;
use FluxFiles\UrlFetcherInterface;

/**
 * URL import / bucket-probe fetches over WordPress' HTTP API (wp_remote_get) instead of raw
 * cURL. UrlImporter keeps the SSRF policy (assertSafeUrl on every hop, manual redirects);
 * this class only owns the socket and must not weaken it:
 *
 *   - The validated addresses are pinned onto THIS request's cURL handle through the
 *     `http_api_curl` action (CURLOPT_RESOLVE + no proxy + http(s) only + a size cap). The
 *     hook is added right before the request, removed right after, and only acts on the
 *     exact URL being fetched.
 *   - Only WordPress' cURL transport can be pinned. If another transport would run (or the
 *     hook never fired) the fetch is refused rather than made unpinned.
 *   - Afterwards the address the handle really connected to goes through
 *     SsrfGuard::assertConnectedIpSafe(); if it cannot be read the fetch fails closed.
 *   - Redirects are never followed (`redirection => 0`); the Location is handed back.
 */
class FluxFilesUrlFetcher implements UrlFetcherInterface
{
    public function fetch(string $url, array $pinIps, ?array $allowedConnectedIps, string $tmpPath, int $maxBytes, int $timeout): array
    {
        // Canonical lower-case scheme: WordPress runs the URL through kses before the hook sees
        // it, which would lower-case it; refuse anything kses would rewrite beyond that.
        $url = (string) preg_replace_callback('/^[A-Za-z][A-Za-z0-9+.\-]*/', static fn (array $m): string => strtolower($m[0]), $url);
        $pinned = self::endpoint($url);
        if ($pinned === null || wp_kses_bad_protocol($url, wp_allowed_protocols()) !== $url) {
            throw new ApiException('Could not download from this URL', 422, 'fetch_failed');
        }
        if (!self::curlTransportUsable($pinned[0] === 'https')) {
            throw new ApiException('Fetching a remote URL needs the cURL HTTP transport, which is not available on this server', 422, 'fetch_failed');
        }
        $pin = SsrfGuard::curlOptionsForIps($url, $pinIps);   // throws fetch_failed on an unpinnable host

        $state = (object) ['handle' => null];
        $hook = static function ($handle, $args = null, $reqUrl = null) use ($pinned, $pin, $maxBytes, $state): void {
            // The hook only exists for the length of our synchronous request, so every firing is
            // ours. Throwing here escapes wp_remote_get() (WP_Http only catches Requests'
            // own exceptions) BEFORE curl_exec(), so no socket is opened for a request we cannot pin.
            if ($state->handle !== null) {
                throw new ApiException('Unexpected second request (redirect) during a pinned fetch', 422, 'ssrf_blocked');
            }
            if (!is_string($reqUrl) || self::endpoint($reqUrl) !== $pinned) {
                throw new ApiException('The request target changed before it was sent', 422, 'ssrf_blocked');
            }
            $state->handle = $handle;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- options on WordPress' own Requests cURL handle via the http_api_curl hook (DNS pin + protocol/size limits for SSRF safety)
            $ok = curl_setopt_array($handle, $pin + [
                CURLOPT_FOLLOWLOCATION   => false,
                CURLOPT_PROTOCOLS        => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_MAXFILESIZE      => $maxBytes,   // aborts on a too-big Content-Length
                CURLOPT_NOPROGRESS       => false,
                CURLOPT_PROGRESSFUNCTION => static function ($c, $dlTotal, $dlNow) use ($maxBytes) {
                    return ($dlNow > $maxBytes || $dlTotal > $maxBytes) ? 1 : 0;   // non-zero aborts (chunked cap)
                },
            ]);
            if ($ok !== true) {
                // A rejected option would silently drop CURLOPT_RESOLVE and everything after it.
                throw new ApiException('Could not pin the connection address', 422, 'ssrf_blocked');
            }
        };

        // Last priority: runs after every normally-prioritised callback (a later PHP_INT_MAX
        // callback from another plugin would still run after it; the connected-IP check below
        // is the backstop for that).
        add_action('http_api_curl', $hook, PHP_INT_MAX, 3);
        try {
            $res = wp_remote_get($url, [
                'timeout'             => max(1, $timeout),
                'redirection'         => 0,
                'stream'              => true,
                'filename'            => $tmpPath,
                'limit_response_size' => $maxBytes + 1,   // one over: UrlImporter's size check then trips
                'reject_unsafe_urls'  => false,           // SsrfGuard is the authority (it also honours the operator allow-list)
                'user-agent'          => 'FluxFiles-URLImport/1.0',
                'headers'             => ['Accept-Encoding' => 'identity'],
            ]);
        } finally {
            remove_action('http_api_curl', $hook, PHP_INT_MAX);
        }

        $handle = $state->handle;
        if ($handle === null) {
            // The request ran on a transport we could not pin. Discard whatever arrived.
            throw new ApiException('Could not download from this URL', 422, 'fetch_failed');
        }

        $info = static function (int $opt) use ($handle) {
            try {
                return curl_getinfo($handle, $opt); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- reads the connected IP / redirect target of WordPress' own handle (SSRF backstop)
            } catch (\Throwable $e) {
                return null;
            }
        };

        // DNS-rebinding backstop: the address the handle actually connected to.
        $ip = $info(CURLINFO_PRIMARY_IP);
        if (!is_string($ip)) {
            throw new ApiException('Could not verify the connection address', 422, 'fetch_failed');
        }
        SsrfGuard::assertConnectedIpSafe($ip, $allowedConnectedIps);

        if (is_wp_error($res)) {
            $errno = 0;
            try {
                $errno = curl_errno($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno -- distinguishes our size-limit abort on WordPress' own handle
            } catch (\Throwable $e) {
            }
            if ($errno === CURLE_FILESIZE_EXCEEDED || $errno === CURLE_ABORTED_BY_CALLBACK) {
                throw new ApiException('File exceeds the import size limit', 422, 'size_exceeded');
            }
            throw new ApiException('Could not download from this URL', 422, 'fetch_failed');
        }

        $disposition = wp_remote_retrieve_header($res, 'content-disposition');
        $redirect = $info(CURLINFO_REDIRECT_URL);
        return [
            'status'       => (int) wp_remote_retrieve_response_code($res),
            'redirect_url' => is_string($redirect) ? $redirect : '',
            'disposition'  => is_array($disposition) ? (string) end($disposition) : (string) $disposition,
        ];
    }

    /** [scheme, host, port] with scheme/host lower-cased and the default port filled in; null if not http(s). */
    private static function endpoint(string $url): ?array
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
            return null;
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        return [$scheme, strtolower(trim($p['host'], '[]')), (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80))];
    }

    /** WordPress prefers its cURL transport; only that one lets us pin and verify the connection. */
    private static function curlTransportUsable(bool $https): bool
    {
        if (!extension_loaded('curl')) {
            return false;
        }
        if ($https) {
            // Without SSL support Requests would silently fall back to the (unpinnable) fsockopen transport.
            $features = function_exists('curl_version') ? (curl_version()['features'] ?? 0) : 0; // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_version -- capability probe only, no request
            if (($features & CURL_VERSION_SSL) === 0) {
                return false;
            }
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter, read not defined here
        $curlAllowed = function_exists('apply_filters') ? apply_filters('use_curl_transport', true, []) : true;
        if ($curlAllowed === false) {
            return false;
        }
        foreach (['\\WpOrg\\Requests\\Transport\\Curl', '\\Requests_Transport_cURL'] as $cls) {
            if (class_exists($cls)) {
                return (bool) $cls::test(['ssl' => $https]);
            }
        }
        return true;   // unknown WP internals: the hook-fired check after the request still fails closed
    }
}
