<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Domain\Exceptions;

final class FloatLimitExceededException extends \DomainException
{
    public function __construct(
        public readonly string $limitType,
        public readonly string $errorCode = 'FLOAT_LIMIT_EXCEEDED',
        public readonly array $context = [],
    ) {
        parent::__construct("Float limit exceeded: {$limitType}.");
    }

    public static function maxBalance(int $current, int $requested, int $max): self
    {
        return new self('max_balance', 'FLOAT_LIMIT_EXCEEDED', [
            'current_balance' => $current,
            'requested'       => $requested,
            'max_balance'     => $max,
        ]);
    }
}
