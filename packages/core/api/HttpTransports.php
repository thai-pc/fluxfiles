<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * Where core gets its default outbound transports. The WordPress build ships neither
 * {@see CurlTransport} nor {@see CurlUrlFetcher} (it injects wp_remote_* implementations
 * and never reaches the defaults), so a default must not fatal when the class is absent.
 */
final class HttpTransports
{
    /** The cURL transport, or null in a build that omits it. */
    public static function curl(int $connectTimeout = 10, int $maxBytes = 0): ?HttpTransportInterface
    {
        if (!class_exists(CurlTransport::class)) {
            return null;
        }
        return $maxBytes > 0 ? new CurlTransport($connectTimeout, $maxBytes) : new CurlTransport($connectTimeout);
    }

    /** Like {@see curl()} but a missing transport is a wiring bug: inject one. */
    public static function curlOrFail(int $connectTimeout = 10, int $maxBytes = 0): HttpTransportInterface
    {
        return self::curl($connectTimeout, $maxBytes)
            ?? throw new \LogicException('No HTTP transport: this build omits the cURL transport, inject an HttpTransportInterface');
    }

    public static function urlFetcher(): UrlFetcherInterface
    {
        if (!class_exists(CurlUrlFetcher::class)) {
            throw new ApiException('URL fetching is not available in this build', 500, 'server_error');
        }
        return new CurlUrlFetcher();
    }
}
