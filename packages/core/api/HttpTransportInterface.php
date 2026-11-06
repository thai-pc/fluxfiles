<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Outbound HTTP for the places core calls an operator-configured endpoint (AI vision
 * providers, OIDC discovery, the bucket doctor's presign probe). The default is
 * {@see CurlTransport}; a host with its own HTTP stack (WordPress: wp_remote_request)
 * injects an implementation instead.
 *
 * NOT used by {@see UrlImporter}, which fetches tenant-supplied URLs and needs the
 * DNS-pinned cURL handle (CURLOPT_RESOLVE + a post-connect IP check) that an abstract
 * transport cannot express.
 *
 * Contract: never throws for a network failure. A request that could not complete returns
 * status 0, an empty body and a human-readable `error`. Redirects are NOT followed.
 */
interface HttpTransportInterface
{
    /**
     * @param array<int|string,string> $headers either `['Name: value', ...]` or `['Name' => 'value']`
     * @return array{status:int, headers:array<string,string>, body:string, error?:string}
     *         header names are lower-cased
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array;
}
