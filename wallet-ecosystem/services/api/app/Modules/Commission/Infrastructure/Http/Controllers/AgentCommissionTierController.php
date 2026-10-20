<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Http\Controllers;

use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\Commission\Infrastructure\Http\Requests\SetAgentTierRequest;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AgentCommissionTierController extends Controller
{
    public function __construct(private readonly AuditServiceInterface $audit) {}

    public function update(SetAgentTierRequest $request, string $agentId): JsonResponse
    {
        $agent  = AgentModel::findOrFail($agentId);
        $before = $agent->agent_tier;
        $agent->agent_tier = $request->validated('agent_tier');
        $agent->save();

        $actorId       = (string) $request->attributes->get('identity.user_id');
        $correlationId = $request->header('X-Correlation-Id', Str::uuid()->toString());

        $this->audit->record(new AuditEventData(
            action:        'commission.agent_tier.updated',
            actorType:     'admin',
            resourceType:  'agent',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $agentId,
            beforeState:   ['agent_tier' => $before],
            afterState:    ['agent_tier' => $agent->agent_tier],
        ));

        return response()->json([
            'data' => [
                'agent_id'   => $agent->id,
                'agent_tier' => $agent->agent_tier,
            ],
        ]);
    }
}
