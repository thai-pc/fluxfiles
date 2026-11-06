<?php

defined('ABSPATH') || exit;

use FluxFiles\HttpTransportInterface;

/**
 * Core's outbound-HTTP seam over WordPress' own HTTP API, so the AI vision providers and
 * the bucket doctor honour the host's proxy / SSL / `http_request_*` configuration instead
 * of raw cURL. Redirects are never followed (core's contract); it never throws.
 *
 * Not used for URL import, which needs core's DNS-pinned cURL handle.
 */
class WpHttpTransport implements HttpTransportInterface
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                $pos = strpos((string) $value, ':');
                if ($pos === false) {
                    continue;
                }
                $name = substr((string) $value, 0, $pos);
                $value = substr((string) $value, $pos + 1);
            }
            $out[trim((string) $name)] = trim((string) $value);
        }
        $args = [
            'method'      => strtoupper($method),
            'timeout'     => max(1, $timeout),
            'redirection' => 0,
            'headers'     => $out,
        ];
        if ($body !== null) {
            $args['body'] = $body;
        }

        try {
            $res = wp_remote_request($url, $args);
        } catch (\Throwable $e) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => $e->getMessage() ?: 'request failed'];
        }
        if (is_wp_error($res)) {
            $msg = $res->get_error_message();
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => $msg !== '' ? $msg : 'request failed'];
        }

        $raw = wp_remote_retrieve_headers($res);
        if (is_object($raw) && method_exists($raw, 'getAll')) {
            $raw = $raw->getAll();
        }
        $respHeaders = [];
        foreach (is_array($raw) ? $raw : [] as $k => $v) {
            $respHeaders[strtolower((string) $k)] = is_array($v) ? implode(', ', $v) : (string) $v;
        }
        return [
            'status'  => (int) wp_remote_retrieve_response_code($res),
            'headers' => $respHeaders,
            'body'    => (string) wp_remote_retrieve_body($res),
        ];
    }
}
