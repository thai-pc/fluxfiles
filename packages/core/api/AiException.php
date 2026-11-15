<?php

declare(strict_types=1);

namespace FluxFiles;

/**
 * A classified AI-provider failure (see {@see AiError}). HTTP 502 for anything the
 * provider did, 400 for `ai_not_configured`; never 401/403/429.
 *
 * `error_params` (the only part that reaches the API envelope) is
 * {provider, model, default_model, retry_after?, reason?, missing?}. The redacted provider
 * text lives in getDetail() for logs and the operator-facing diagnostics, never in the
 * envelope.
 *
 * Its own file (not in AiError.php) so the PSR-4 autoloader can find it by name.
 */
class AiException extends ApiException
{
    private string $detail;
    private int $upstreamStatus;

    /**
     * @param array<string,mixed> $params
     * @param int                 $upstreamStatus HTTP status the provider answered (0 = none)
     */
    public function __construct(string $code, array $params = [], string $detail = '', int $upstreamStatus = 0)
    {
        parent::__construct(AiError::message($code), AiError::httpStatus($code), $code, $params);
        $this->detail = $detail;
        $this->upstreamStatus = $upstreamStatus;
    }

    /** Redacted provider text, for logs and the operator's diagnostics. */
    public function getDetail(): string
    {
        return $this->detail;
    }

    public function getUpstreamStatus(): int
    {
        return $this->upstreamStatus;
    }
}
