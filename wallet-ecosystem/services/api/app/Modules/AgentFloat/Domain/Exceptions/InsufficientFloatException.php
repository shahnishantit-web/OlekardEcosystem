<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Domain\Exceptions;

final class InsufficientFloatException extends \DomainException
{
    public function __construct(
        public readonly int $balance,
        public readonly int $requested,
        public readonly string $errorCode = 'INSUFFICIENT_FLOAT',
    ) {
        parent::__construct("Insufficient float: balance {$balance}, requested {$requested}.");
    }

    public static function withBalance(int $balance, int $requested): self
    {
        return new self($balance, $requested);
    }
}
