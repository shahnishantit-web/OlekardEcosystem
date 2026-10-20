<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class IdempotencyInProgressException extends RuntimeException
{
    public function __construct(
        private readonly string $correlationId,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('This request is currently being processed. Retry after it completes.', 0, $previous);
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }
}
