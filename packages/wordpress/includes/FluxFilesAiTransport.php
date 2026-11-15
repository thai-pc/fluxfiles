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
 *   - Pinning needs WordPress' cURL transport, and it overrides the proxy setting. On Streams,
 *     when `use_curl_transport` is off, or when WordPress sends this URL through a configured
 *     proxy (WP_PROXY_*), the request runs with the call-time check only: pinning would drop
 *     the proxy and break a proxy-only egress. If a pin was computed but the cURL hook never
 *     fired (a transport swapped by another plugin, or a `pre_http_request` short-circuit,
 *     which cannot be told apart reliably) the answer is accepted unpinned: the call-time
 *     check still ran.
 *   - A second firing of the hook (a redirect followed because another plugin raised
 *     `redirection`) is refused before any socket is opened.
 *   - A host the operator allow-listed (FLUXFILES_SSRF_ALLOW_HOSTS, e.g. a local Ollama) is
 *     deliberately allowed to resolve privately, so it is neither pinned nor post-checked
 *     (a divergence from core's "backstop stays on" policy: the operator named the host).
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
            && FluxFilesUrlFetcher::curlTransportUsable($scheme === 'https')
            && !self::viaProxy($url)) {
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
                if ($state->handle !== null) {
                    throw new \FluxFiles\ApiException('Unexpected second request (redirect) during a pinned call', 422, 'ssrf_blocked');
                }
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
                $ip = $this->connectedIp($state->handle);
                if ($ip === null || ($ip === '' && $res['status'] !== 0)) {
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

    /** The address the handle really connected to (a seam for tests). */
    protected function connectedIp($handle): ?string
    {
        return FluxFilesUrlFetcher::connectedIp($handle);
    }

    /** True when WordPress sends this URL through its configured proxy (WP_PROXY_*). */
    private static function viaProxy(string $url): bool
    {
        if (!class_exists('WP_HTTP_Proxy')) {
            return false;
        }
        $proxy = new \WP_HTTP_Proxy();
        return $proxy->is_enabled() && $proxy->send_through_proxy($url);
    }
}
