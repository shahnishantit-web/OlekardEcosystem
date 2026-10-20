<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Domain\Exceptions;

final class FloatWalletAlreadyExistsException extends \DomainException
{
    public function __construct(
        public readonly string $agentId,
        public readonly string $errorCode = 'FLOAT_WALLET_ALREADY_EXISTS',
    ) {
        parent::__construct("Agent [{$agentId}] already has an active float wallet.");
    }

    public static function forAgent(string $agentId): self
    {
        return new self($agentId);
    }
}
