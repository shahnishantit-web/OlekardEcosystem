<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class IdempotencyKeyMismatchException extends RuntimeException
{
    public function __construct(
        private readonly string $correlationId,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('Idempotency key has already been used with a different request payload.', 0, $previous);
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }
}
