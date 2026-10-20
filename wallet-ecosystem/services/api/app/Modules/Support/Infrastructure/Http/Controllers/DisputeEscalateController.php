<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Controllers;

use App\Modules\Support\Application\Services\DisputeInvestigationService;
use App\Modules\Support\Infrastructure\Http\Requests\EscalateDisputeRequest;
use App\Modules\Support\Infrastructure\Http\Resources\DisputeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class DisputeEscalateController extends Controller
{
    public function __construct(private readonly DisputeInvestigationService $service) {}

    public function __invoke(EscalateDisputeRequest $request, string $id): JsonResponse
    {
        $actorId   = (string) $request->attributes->get('identity.user_id');
        $actorType = (string) $request->attributes->get('identity.actor_type', 'customer');
        $actorRole = $actorType === 'admin' ? 'admin' : 'user';
        $correlationId = (string) $request->header('X-Correlation-ID', (string) Str::uuid());

        $dispute = $this->service->escalate(
            disputeId:     $id,
            actorId:       $actorId,
            actorRole:     $actorRole,
            reason:        $request->validated('escalation_reason'),
            correlationId: $correlationId,
        );

        return response()->json([
            'data'    => new DisputeResource($dispute),
            'success' => true,
        ]);
    }
}
