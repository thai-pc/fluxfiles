<?php

declare(strict_types=1);

namespace FluxFiles;

// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url -- core is a WordPress-independent library; wp_parse_url() does not exist outside WordPress

/**
 * Decorator that re-runs the SSRF rule on every outbound call of an operator-entered URL.
 *
 * For hosts where the AI base URL comes from a settings form (WordPress): the URL is
 * checked on save, but DNS can change afterwards, so the same rule runs again at call
 * time. An allowlisted host (FLUXFILES_SSRF_ALLOW_HOSTS) passes as-is, anything else must
 * clear {@see SsrfGuard::assertSafeUrl()}.
 *
 * Core and Laravel leave this unwired: there the base URL is operator env/config, and
 * Ollama on localhost must keep working.
 *
 * Residual risk: DNS can still change between this check and the inner transport's own
 * lookup, and a host HTTP API that cannot pin an IP (wp_remote_request) cannot close that.
 *
 * Honours the {@see HttpTransportInterface} contract: a refusal is a status-0 answer, not a
 * throw. Its `error` starts with `blocked_host:`, which AiError::classify() maps to
 * `ai_blocked_host`.
 */
final class SsrfCheckedTransport implements HttpTransportInterface
{
    private HttpTransportInterface $inner;

    public function __construct(HttpTransportInterface $inner)
    {
        $this->inner = $inner;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
    {
        $parts = @parse_url($url);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $port = is_array($parts) ? ($parts['port'] ?? null) : null;

        if ($host === '' || !SsrfGuard::isAllowlistedHost($host, $port)) {
            try {
                SsrfGuard::assertSafeUrl($url);
            } catch (ApiException $e) {
                $code = (string) $e->getErrorCode();
                // An unresolvable host is a DNS failure, not a policy refusal.
                $prefix = $code === 'fetch_failed' ? 'dns: ' : 'blocked_host: ';
                return ['status' => 0, 'headers' => [], 'body' => '', 'error' => $prefix . $e->getMessage()];
            }
        }

        return $this->inner->request($method, $url, $headers, $body, $timeout);
    }
}
