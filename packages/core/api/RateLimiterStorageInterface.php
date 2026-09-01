<?php

declare(strict_types=1);

namespace FluxFiles;

interface RateLimiterStorageInterface
{
    /** @throws ApiException 429 rate_limited when the caller has exceeded its limit */
    public function check(string $identifier, string $actionType): void;
}
