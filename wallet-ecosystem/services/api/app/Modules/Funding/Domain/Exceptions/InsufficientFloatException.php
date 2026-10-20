<?php

declare(strict_types=1);

namespace App\Modules\Funding\Domain\Exceptions;

final class InsufficientFloatException extends \RuntimeException
{
    public function __construct(
        public readonly string $agentId,
        public readonly int $availableBalance,
        public readonly int $requiredAmount,
        public readonly string $currencyCode,
    ) {
        parent::__construct(
            "Agent [{$agentId}] float balance [{$availableBalance}] is insufficient for [{$requiredAmount}] {$currencyCode}."
        );
    }
}
