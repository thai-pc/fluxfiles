<?php

declare(strict_types=1);

namespace FluxFiles;

// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_errno, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- standalone/Laravel build only (the WordPress build omits this file and fetches through wp_remote_get): SSRF-guarded fetch, cURL pins the validated IP (CURLOPT_RESOLVE)
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- core is a WordPress-independent library: streamed file handle

/** The default {@see UrlFetcherInterface}: one DNS-pinned cURL handle per hop. */
final class CurlUrlFetcher implements UrlFetcherInterface
{
    public function fetch(string $url, array $pinIps, ?array $allowedConnectedIps, string $tmpPath, int $maxBytes, int $timeout): array
    {
        $fh = fopen($tmpPath, 'w');
        if ($fh === false) {
            throw new ApiException('Could not open temp file', 500, 'server_error');
        }
        $disposition = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, SsrfGuard::curlOptionsForIps($url, $pinIps));
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION  => false,             // the caller follows manually to re-validate
            CURLOPT_FILE            => $fh,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_MAXFILESIZE     => $maxBytes,         // aborts on a too-big Content-Length
            CURLOPT_NOPROGRESS      => false,
            CURLOPT_PROGRESSFUNCTION => static function ($c, $dlTotal, $dlNow) use ($maxBytes) {
                return ($dlNow > $maxBytes || $dlTotal > $maxBytes) ? 1 : 0;  // non-zero aborts (chunked cap)
            },
            CURLOPT_HEADERFUNCTION  => static function ($c, $line) use (&$disposition) {
                if (stripos($line, 'content-disposition:') === 0) {
                    $disposition = trim(substr($line, 20));
                }
                return strlen($line);
            },
            CURLOPT_USERAGENT       => 'FluxFiles-URLImport/1.0',
            CURLOPT_ACCEPT_ENCODING => '',                // let curl handle gzip transparently
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $redirectUrl = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);

        // DNS-rebinding backstop: the IP curl actually connected to.
        try {
            SsrfGuard::assertConnectedIpSafe((string) curl_getinfo($ch, CURLINFO_PRIMARY_IP), $allowedConnectedIps);
        } finally {
            fclose($fh);
        }

        if ($ok === false) {
            if ($errno === CURLE_FILESIZE_EXCEEDED || $errno === CURLE_ABORTED_BY_CALLBACK) {
                throw new ApiException('File exceeds the import size limit', 422, 'size_exceeded');
            }
            throw new ApiException('Could not download from this URL', 422, 'fetch_failed');
        }
        return ['status' => $code, 'redirect_url' => $redirectUrl, 'disposition' => $disposition];
    }
}
