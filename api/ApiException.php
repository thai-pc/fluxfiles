<?php

declare(strict_types=1);

namespace FluxFiles;

class ApiException extends \RuntimeException
{
    /** @var int */
    private $httpCode;

    public function __construct(string $message, int $httpCode = 400)
    {
        $this->httpCode = $httpCode;
        parent::__construct($message, $httpCode);
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }
}
