<?php

declare(strict_types=1);

namespace FluxFiles;

class ApiException extends \RuntimeException
{
    /** @var int */
    private $httpCode;

    /** @var string|null */
    private $errorCode;

    /** @var array */
    private $errorParams;

    public function __construct(string $message, int $httpCode = 400, ?string $errorCode = null, array $errorParams = [])
    {
        $this->httpCode = $httpCode;
        $this->errorCode = $errorCode;
        $this->errorParams = $errorParams;
        parent::__construct($message, $httpCode);
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getErrorParams(): array
    {
        return $this->errorParams;
    }
}
