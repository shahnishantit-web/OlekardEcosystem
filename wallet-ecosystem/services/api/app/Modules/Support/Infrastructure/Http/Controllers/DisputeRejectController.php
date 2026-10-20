<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Controllers;

use App\Modules\Support\Application\Services\DisputeResolutionService;
use App\Modules\Support\Infrastructure\Http\Requests\RejectDisputeRequest;
use App\Modules\Support\Infrastructure\Http\Resources\DisputeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class DisputeRejectController extends Controller
{
    public function __construct(private readonly DisputeResolutionService $service) {}

    public function __invoke(RejectDisputeRequest $request, string $id): JsonResponse
    {
        $actorId   = (string) $request->attributes->get('identity.user_id');
        $actorType = (string) $request->attributes->get('identity.actor_type', 'customer');
        $actorRole = $actorType === 'admin' ? 'admin' : 'user';
        $idempotencyKey = (string) $request->header('Idempotency-Key', (string) Str::uuid());
        $correlationId  = (string) $request->header('X-Correlation-ID', (string) Str::uuid());

        $dispute = $this->service->rejectDispute(
            disputeId:       $id,
            rejectedById:    $actorId,
            actorRole:       $actorRole,
            rejectionReason: $request->validated('rejection_reason'),
            idempotencyKey:  $idempotencyKey,
            correlationId:   $correlationId,
        );

        return response()->json([
            'data'    => new DisputeResource($dispute),
            'success' => true,
        ]);
    }
}
