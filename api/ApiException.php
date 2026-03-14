<?php

declare(strict_types=1);

namespace FluxFiles;

class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private int $httpCode = 400
    ) {
        parent::__construct($message, $httpCode);
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }
}
