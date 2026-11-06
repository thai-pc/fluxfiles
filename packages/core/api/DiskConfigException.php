<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * A broken FLUXFILES_DISKS_CONFIG. The message is generic and safe to send to any
 * HTTP caller; the operator-facing detail (path, inner error) is in getDetail(),
 * which only the CLI/scripts print and DiskConfig also writes to error_log().
 */
final class DiskConfigException extends ApiException
{
    private string $detail;

    public function __construct(string $detail)
    {
        $this->detail = $detail;
        parent::__construct('Server disk configuration is invalid', 500, 'server_error');
    }

    public function getDetail(): string
    {
        return $this->detail;
    }
}
