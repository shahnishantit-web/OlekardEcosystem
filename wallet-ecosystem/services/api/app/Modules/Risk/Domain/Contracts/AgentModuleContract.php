<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Contracts;

interface AgentModuleContract
{
    /**
     * Returns agent profile data relevant to risk evaluation.
     * Returns null when the agent is not found.
     *
     * @return array{agent_id: string, tier: string, status: string}|null
     */
    public function getAgentProfile(string $agentId): ?array;
}
