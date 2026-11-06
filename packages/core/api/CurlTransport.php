<?php

declare(strict_types=1);

namespace FluxFiles;

// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_error, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- SSRF-guarded outbound fetch: cURL pins the validated IP (CURLOPT_RESOLVE) and caps size and redirects, which wp_remote_* cannot do; core also runs outside WordPress

/** The default {@see HttpTransportInterface}: plain cURL, no redirects, http(s) only. */
final class CurlTransport implements HttpTransportInterface
{
    private int $connectTimeout;

    public function __construct(int $connectTimeout = 10)
    {
        $this->connectTimeout = max(1, $connectTimeout);
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = is_int($name) ? (string) $value : $name . ': ' . $value;
        }

        $respHeaders = [];
        $method = strtoupper($method);
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(1, $timeout),
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ];
        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($resp === false) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => $error !== '' ? $error : 'request failed'];
        }
        return ['status' => $status, 'headers' => $respHeaders, 'body' => (string) $resp];
    }
}
