<?php

declare(strict_types=1);

namespace FluxFiles;

// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_error, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- SSRF-guarded outbound fetch: cURL pins the validated IP (CURLOPT_RESOLVE) and caps size and redirects, which wp_remote_* cannot do; core also runs outside WordPress

/** The default {@see HttpTransportInterface}: plain cURL, no redirects, http(s) only. */
final class CurlTransport implements HttpTransportInterface
{
    /** Default response-body cap: a hostile or compromised upstream must not fill memory. */
    public const DEFAULT_MAX_BYTES = 8 * 1024 * 1024;

    private int $connectTimeout;
    private int $maxBytes;

    /** @param int $maxBytes response-body cap in bytes; larger answers fail with status 0 (0 = default) */
    public function __construct(int $connectTimeout = 10, int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
        $this->connectTimeout = max(1, $connectTimeout);
        $this->maxBytes = $maxBytes > 0 ? $maxBytes : self::DEFAULT_MAX_BYTES;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = is_int($name) ? (string) $value : $name . ': ' . $value;
        }

        $respHeaders = [];
        $received = '';
        $tooLarge = false;
        $max = $this->maxBytes;
        $method = strtoupper($method);
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_TIMEOUT        => max(1, $timeout),
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders, &$tooLarge, $max): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $name = strtolower(trim(substr($line, 0, $pos)));
                    $respHeaders[$name] = trim(substr($line, $pos + 1));
                    // Refuse early on a declared oversize body (returning short aborts the transfer).
                    if ($name === 'content-length' && ctype_digit($respHeaders[$name]) && (float) $respHeaders[$name] > $max) {
                        $tooLarge = true;
                        return 0;
                    }
                }
                return strlen($line);
            },
            // Abort as soon as the cap is crossed (chunked / lying Content-Length).
            CURLOPT_WRITEFUNCTION  => static function ($c, string $chunk) use (&$received, &$tooLarge, $max): int {
                if (strlen($received) + strlen($chunk) > $max) {
                    $tooLarge = true;
                    return 0;
                }
                $received .= $chunk;
                return strlen($chunk);
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

        if ($tooLarge) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'response too large (limit ' . $max . ' bytes)'];
        }
        if ($resp === false) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => $error !== '' ? $error : 'request failed'];
        }
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $received];
    }
}
