<?php

defined('ABSPATH') || exit;

use FluxFiles\HttpTransportInterface;
use FluxFiles\SsrfGuard;

/**
 * Outbound transport for an AI endpoint an administrator typed into the settings form.
 * Sits between core's SsrfCheckedTransport (the outer, call-time host check) and
 * FluxFilesHttpTransport (wp_remote_request):
 *
 *   - The checked addresses are pinned onto THIS request's cURL handle through the
 *     `http_api_curl` action (CURLOPT_RESOLVE, no proxy), exactly like FluxFilesUrlFetcher,
 *     and the address the handle really connected to is verified afterwards. A DNS answer that
 *     changes between the check and the connection (rebinding) therefore cannot reach a
 *     private address.
 *   - Pinning needs WordPress' cURL transport. On Streams, or when `use_curl_transport` is
 *     off, the request still runs with the call-time check only (the same policy as before).
 *   - A host the operator allow-listed (FLUXFILES_SSRF_ALLOW_HOSTS, e.g. a local Ollama) is
 *     deliberately allowed to resolve privately, so it is not pinned.
 *   - Any 3xx answer is refused: nothing follows it, and an AI endpoint never legitimately
 *     redirects. The error says so (it classifies as ai_network, not as a blocked host).
 */
class FluxFilesAiTransport implements HttpTransportInterface
{
    private HttpTransportInterface $inner;

    public function __construct(HttpTransportInterface $inner)
    {
        $this->inner = $inner;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
    {
        $parts = wp_parse_url($url);
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $port = is_array($parts) ? (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)) : 0;

        $pin = null;
        $ips = [];
        if ($host !== '' && !SsrfGuard::isAllowlistedHost($host, $parts['port'] ?? null)
            && FluxFilesUrlFetcher::curlTransportUsable($scheme === 'https')) {
            try {
                $ips = SsrfGuard::assertSafeUrl($url);
                $pin = SsrfGuard::curlOptionsForIps($url, $ips);
            } catch (\FluxFiles\ApiException $e) {
                return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'blocked_host: ' . $e->getMessage()];
            }
        }

        $state = (object) ['handle' => null];
        $hook = null;
        if ($pin !== null) {
            $hook = static function ($handle, $args = null, $reqUrl = null) use ($host, $port, $pin, $state): void {
                $p = is_string($reqUrl) ? wp_parse_url($reqUrl) : null;
                $reqScheme = is_array($p) ? strtolower((string) ($p['scheme'] ?? '')) : '';
                $reqPort = is_array($p) ? (int) ($p['port'] ?? ($reqScheme === 'https' ? 443 : 80)) : 0;
                if (!is_array($p) || strtolower(trim((string) ($p['host'] ?? ''), '[]')) !== $host || $reqPort !== $port) {
                    throw new \FluxFiles\ApiException('The request target changed before it was sent', 422, 'ssrf_blocked');
                }
                $state->handle = $handle;
                if (!FluxFilesUrlFetcher::pinHandle($handle, $pin)) {
                    throw new \FluxFiles\ApiException('Could not pin the connection address', 422, 'ssrf_blocked');
                }
            };
            add_action('http_api_curl', $hook, PHP_INT_MAX, 3);
        }
        try {
            $res = $this->inner->request($method, $url, $headers, $body, $timeout);
        } finally {
            if ($hook !== null) {
                remove_action('http_api_curl', $hook, PHP_INT_MAX);
            }
        }

        if ($pin !== null && $state->handle !== null) {
            // DNS-rebinding backstop: the address the handle actually connected to.
            try {
                $ip = FluxFilesUrlFetcher::connectedIp($state->handle);
                if ($ip === null) {
                    throw new \FluxFiles\ApiException('Could not verify the connection address', 422, 'fetch_failed');
                }
                SsrfGuard::assertConnectedIpSafe($ip, $ips);
            } catch (\Throwable $e) {
                return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'blocked_host: ' . $e->getMessage()];
            }
        }

        if ($res['status'] >= 300 && $res['status'] < 400) {
            return [
                'status'  => 0,
                'headers' => [],
                'body'    => '',
                'error'   => 'redirect refused (HTTP ' . $res['status'] . '): redirects are not followed, use the final URL in the base URL field (for example https:// instead of http://)',
            ];
        }
        return $res;
    }
}
