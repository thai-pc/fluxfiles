<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Fetches + caches an OIDC provider's discovery document and JWKS.
 *
 * The issuer URL is operator server config (FLUXFILES_SSO_OIDC_ISSUER, from
 * .env), never user/tenant input — same trust posture already documented for
 * FLUXFILES_AIVISION_ENDPOINT ("operator-trusted, not user input — no SSRF
 * guard needed"). SsrfGuard stays reserved for genuinely tenant-supplied URLs.
 */
final class OidcDiscovery
{
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 10;
    private const CACHE_TTL = 3600;
    /** Discovery documents and JWKS are a few KB; 1 MB is generous. */
    private const MAX_BYTES = 1048576;

    private string $cacheDir;

    private HttpTransportInterface $transport;

    public function __construct(string $cacheDir, ?HttpTransportInterface $transport = null)
    {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->transport = $transport ?? new CurlTransport(self::CONNECT_TIMEOUT, self::MAX_BYTES);
    }

    /**
     * @return array{authorization_endpoint:string,token_endpoint:string,jwks_uri:string,issuer:string}
     */
    public function discover(string $issuer): array
    {
        $cached = $this->readCache('discovery-' . $this->cacheKey($issuer));
        if ($cached !== null) {
            $this->assertEndpointsSecure($cached); // a cache written before this rule existed
            return $cached;
        }

        $doc = $this->fetchJson(rtrim($issuer, '/') . '/.well-known/openid-configuration');
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'issuer'] as $field) {
            if (empty($doc[$field])) {
                throw new ApiException('OIDC discovery document is missing required fields', 502, 'sso_idp_unreachable');
            }
        }

        $this->assertEndpointsSecure($doc);
        $this->writeCache('discovery-' . $this->cacheKey($issuer), $doc);
        return $doc;
    }

    /** Raw JWKS document ({keys: [...]}), as consumed by Firebase\JWT\JWK::parseKeySet(). */
    public function jwks(string $jwksUri): array
    {
        $cached = $this->readCache('jwks-' . $this->cacheKey($jwksUri));
        if ($cached !== null) {
            return $cached;
        }

        $keys = $this->fetchJson($jwksUri);
        if (empty($keys['keys']) || !is_array($keys['keys'])) {
            throw new ApiException('OIDC JWKS document is malformed', 502, 'sso_idp_unreachable');
        }

        $this->writeCache('jwks-' . $this->cacheKey($jwksUri), $keys);
        return $keys;
    }

    /**
     * Drop cached JWKS (e.g. after a signature failure caused by a rotated key) so the
     * next jwks() call re-fetches. With a URI: just that key set. Without: every cached
     * key set in this cache directory (discovery documents are kept).
     */
    public function forgetJwks(?string $jwksUri = null): void
    {
        if ($jwksUri !== null) {
            @unlink($this->cacheDir . '/jwks-' . $this->cacheKey($jwksUri) . '.json');
            return;
        }
        foreach (@scandir($this->cacheDir) ?: [] as $file) {
            if (str_starts_with($file, 'jwks-') && str_ends_with($file, '.json')) {
                @unlink($this->cacheDir . '/' . $file);
            }
        }
    }

    /**
     * Discovery documents and key sets are trust anchors for id_token verification, so
     * they travel over TLS. Plain http is accepted only for a loopback host (local dev).
     * Applied in fetchJson(), i.e. to the issuer's discovery URL and to jwks_uri on both
     * the normal fetch and the forget/refetch path.
     */
    private function assertEndpointsSecure(array $doc): void
    {
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'userinfo_endpoint', 'end_session_endpoint'] as $field) {
            if (isset($doc[$field]) && $doc[$field] !== '') {
                $this->assertSecureUrl(is_string($doc[$field]) ? $doc[$field] : '');
            }
        }
    }

    private function assertSecureUrl(string $url): void
    {
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        if ($scheme === 'https') {
            return;
        }
        $host = strtolower(trim((string) (parse_url($url, PHP_URL_HOST) ?? ''), '[]'));
        if ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return;
        }
        // A configuration problem, not an outage: reuse the existing localised code.
        throw new ApiException('The identity provider URL must use https', 500, 'sso_misconfigured');
    }

    private function fetchJson(string $url): array
    {
        $this->assertSecureUrl($url);
        $res = $this->transport->request('GET', $url, ['Accept: application/json'], null, self::TIMEOUT);
        $status = $res['status'];
        $body = $res['body'];

        if ($status < 200 || $status >= 300) {
            throw new ApiException('Could not reach the identity provider', 502, 'sso_idp_unreachable');
        }
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new ApiException('Identity provider returned an invalid response', 502, 'sso_idp_unreachable');
        }
        return $data;
    }

    private function cacheKey(string $url): string
    {
        return substr(hash('sha256', $url), 0, 32);
    }

    private function readCache(string $key): ?array
    {
        $path = $this->cacheDir . '/' . $key . '.json';
        if (!is_file($path) || !$this->isOwnedByUs($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['_cached_at']) || (time() - (int) $decoded['_cached_at']) > self::CACHE_TTL) {
            return null;
        }
        unset($decoded['_cached_at']);
        return $decoded;
    }

    private function writeCache(string $key, array $data): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0700, true) && !is_dir($this->cacheDir)) {
            return; // Cache is best-effort; a fresh fetch next call is fine.
        }
        // Without a dedicated FLUXFILES_STORAGE_PATH, this directory lives under
        // the shared sys_get_temp_dir() at a fixed, predictable name — another
        // local user/process could have pre-created it. Since this cache holds
        // the JWKS used to verify id_token signatures, trusting a directory we
        // don't own would let a local attacker plant a forged key and bypass
        // signature verification entirely. Refuse to read/write it.
        if (!$this->isOwnedByUs($this->cacheDir)) {
            return;
        }
        $data['_cached_at'] = time();
        $path = $this->cacheDir . '/' . $key . '.json';
        @file_put_contents($path, json_encode($data));
        @chmod($path, 0600);
    }

    /** True if $path is owned by the current process user, or ownership can't be determined on this platform. */
    private function isOwnedByUs(string $path): bool
    {
        $mine = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if ($mine === false) {
            return true;
        }
        $owner = @fileowner($path);
        return $owner !== false && $owner === $mine;
    }
}
