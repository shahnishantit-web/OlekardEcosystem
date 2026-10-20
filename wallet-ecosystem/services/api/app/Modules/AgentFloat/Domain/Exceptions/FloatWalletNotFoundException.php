<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Domain\Exceptions;

final class FloatWalletNotFoundException extends \DomainException
{
    public function __construct(
        public readonly string $agentId,
        public readonly string $errorCode = 'FLOAT_WALLET_NOT_FOUND',
    ) {
        parent::__construct("No float wallet found for agent [{$agentId}].");
    }

    public static function forAgent(string $agentId): self
    {
        return new self($agentId);
    }
}
