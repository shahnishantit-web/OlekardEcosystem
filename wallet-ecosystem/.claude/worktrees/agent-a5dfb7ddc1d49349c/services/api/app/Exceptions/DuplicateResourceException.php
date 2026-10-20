<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class DuplicateResourceException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $correlationId,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }
}
