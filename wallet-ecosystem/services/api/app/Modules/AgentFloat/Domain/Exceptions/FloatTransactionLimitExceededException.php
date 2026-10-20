<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Domain\Exceptions;

final class FloatTransactionLimitExceededException extends \DomainException
{
    public function __construct(
        public readonly int $amount,
        public readonly int $limit,
        public readonly string $errorCode = 'TRANSACTION_LIMIT_EXCEEDED',
    ) {
        parent::__construct("Amount {$amount} exceeds per-transaction limit {$limit}.");
    }

    public static function exceeded(int $amount, int $limit): self
    {
        return new self($amount, $limit);
    }
}
