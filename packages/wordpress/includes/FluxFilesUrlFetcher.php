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
        if (!self::curlTransportUsable()) {
            throw new ApiException('Fetching a remote URL needs the cURL HTTP transport, which is not available on this server', 422, 'fetch_failed');
        }
        $pin = SsrfGuard::curlOptionsForIps($url, $pinIps);   // throws fetch_failed on an unpinnable host

        $state = (object) ['handle' => null];
        $hook = static function ($handle, $args = null, $reqUrl = null) use ($url, $pin, $maxBytes, $state): void {
            if ($reqUrl !== $url || $state->handle !== null) {
                return;   // not our request
            }
            $state->handle = $handle;
            curl_setopt_array($handle, $pin + [
                CURLOPT_FOLLOWLOCATION   => false,
                CURLOPT_PROTOCOLS        => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_MAXFILESIZE      => $maxBytes,   // aborts on a too-big Content-Length
                CURLOPT_NOPROGRESS       => false,
                CURLOPT_PROGRESSFUNCTION => static function ($c, $dlTotal, $dlNow) use ($maxBytes) {
                    return ($dlNow > $maxBytes || $dlTotal > $maxBytes) ? 1 : 0;   // non-zero aborts (chunked cap)
                },
            ]);
        };

        // Last priority: nothing another plugin hooks in can undo the pin.
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
                return curl_getinfo($handle, $opt);
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
                $errno = curl_errno($handle);
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

    /** WordPress prefers its cURL transport; only that one lets us pin and verify the connection. */
    private static function curlTransportUsable(): bool
    {
        if (!extension_loaded('curl')) {
            return false;
        }
        if (function_exists('apply_filters') && apply_filters('use_curl_transport', true, []) === false) {
            return false;
        }
        foreach (['\\WpOrg\\Requests\\Transport\\Curl', '\\Requests_Transport_cURL'] as $cls) {
            if (class_exists($cls)) {
                return (bool) $cls::test();
            }
        }
        return true;   // unknown WP internals: the hook-fired check after the request still fails closed
    }
}
