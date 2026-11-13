<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * One DNS-pinned, size-capped, non-redirecting GET of a tenant-supplied URL, streamed to a
 * temp file. {@see UrlImporter} (and {@see BucketDoctor} for a BYOB presign probe) own the
 * SSRF policy and the redirect loop; an implementation owns only the socket:
 *
 *   - connect ONLY to $pinIps (the addresses SsrfGuard already vetted), no proxy;
 *   - never follow a redirect, http(s) only;
 *   - stop at $maxBytes (size_exceeded) and write what was received to $tmpPath;
 *   - afterwards re-check the address it actually connected to through
 *     {@see SsrfGuard::assertConnectedIpSafe()} with $allowedConnectedIps.
 *
 * The default is {@see CurlUrlFetcher}; WordPress injects an implementation over its HTTP API.
 */
interface UrlFetcherInterface
{
    /**
     * @param string[]      $pinIps              vetted addresses the connection is pinned to
     * @param string[]|null $allowedConnectedIps what assertConnectedIpSafe() may wave through
     * @return array{status:int, redirect_url:string, disposition:string}
     * @throws ApiException size_exceeded | fetch_failed | ssrf_blocked
     */
    public function fetch(string $url, array $pinIps, ?array $allowedConnectedIps, string $tmpPath, int $maxBytes, int $timeout): array;
}
