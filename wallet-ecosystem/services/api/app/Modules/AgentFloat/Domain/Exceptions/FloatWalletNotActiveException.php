<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Domain\Exceptions;

final class FloatWalletNotActiveException extends \DomainException
{
    public function __construct(
        public readonly string $agentId,
        public readonly string $status,
        public readonly string $errorCode = 'AGENT_SUSPENDED',
    ) {
        parent::__construct("Float wallet for agent [{$agentId}] is not active (status: {$status}).");
    }

    public static function suspended(string $agentId): self
    {
        return new self($agentId, 'suspended');
    }
}
