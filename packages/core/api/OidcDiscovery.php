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

    private string $cacheDir;

    public function __construct(string $cacheDir)
    {
        $this->cacheDir = rtrim($cacheDir, '/');
    }

    /**
     * @return array{authorization_endpoint:string,token_endpoint:string,jwks_uri:string,issuer:string}
     */
    public function discover(string $issuer): array
    {
        $cached = $this->readCache('discovery-' . $this->cacheKey($issuer));
        if ($cached !== null) {
            return $cached;
        }

        $doc = $this->fetchJson(rtrim($issuer, '/') . '/.well-known/openid-configuration');
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'issuer'] as $field) {
            if (empty($doc[$field])) {
                throw new ApiException('OIDC discovery document is missing required fields', 502, 'sso_idp_unreachable');
            }
        }

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

    private function fetchJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false, // don't chase redirects
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $body === false || $status < 200 || $status >= 300) {
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
        if (!is_file($path)) {
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
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            return; // Cache is best-effort; a fresh fetch next call is fine.
        }
        $data['_cached_at'] = time();
        @file_put_contents($this->cacheDir . '/' . $key . '.json', json_encode($data));
    }
}
