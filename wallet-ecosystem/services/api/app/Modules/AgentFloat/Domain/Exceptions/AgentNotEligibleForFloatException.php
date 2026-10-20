<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Domain\Exceptions;

final class AgentNotEligibleForFloatException extends \DomainException
{
    public function __construct(
        public readonly string $agentId,
        public readonly string $reason,
        public readonly string $errorCode = 'AGENT_NOT_ELIGIBLE',
    ) {
        parent::__construct("Agent [{$agentId}] is not eligible for a float wallet: {$reason}.");
    }

    public static function notApproved(string $agentId): self
    {
        return new self($agentId, 'agent must be approved and active');
    }
}
