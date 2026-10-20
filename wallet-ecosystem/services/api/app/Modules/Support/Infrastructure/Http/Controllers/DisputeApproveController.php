<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Controllers;

use App\Modules\Support\Application\Services\DisputeResolutionService;
use App\Modules\Support\Infrastructure\Http\Requests\ApproveDisputeRequest;
use App\Modules\Support\Infrastructure\Http\Resources\DisputeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class DisputeApproveController extends Controller
{
    public function __construct(private readonly DisputeResolutionService $service) {}

    public function __invoke(ApproveDisputeRequest $request, string $id): JsonResponse
    {
        $actorId   = (string) $request->attributes->get('identity.user_id');
        $actorType = (string) $request->attributes->get('identity.actor_type', 'customer');
        $actorRole = $actorType === 'admin' ? 'admin' : 'user';
        $idempotencyKey = (string) $request->header('Idempotency-Key', (string) Str::uuid());
        $correlationId  = (string) $request->header('X-Correlation-ID', (string) Str::uuid());

        $dispute = $this->service->approveDispute(
            disputeId:      $id,
            approvedById:   $actorId,
            actorRole:      $actorRole,
            resolutionNote: $request->validated('resolution_note'),
            idempotencyKey: $idempotencyKey,
            correlationId:  $correlationId,
        );

        return response()->json([
            'data'    => new DisputeResource($dispute),
            'success' => true,
        ]);
    }
}
