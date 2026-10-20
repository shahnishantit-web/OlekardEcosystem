<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Controllers;

use App\Modules\Support\Application\Services\DisputeRefundService;
use App\Modules\Support\Infrastructure\Http\Resources\DisputeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class DisputeConfirmRefundController
{
    public function __construct(private readonly DisputeRefundService $service) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $actorId = (string) $request->attributes->get('identity.user_id');
        $actorType = (string) $request->attributes->get('identity.actor_type', 'customer');
        $actorRole = strtolower($actorType) === 'admin' ? 'admin' : 'user';
        $idempotencyKey = (string) $request->header('Idempotency-Key', (string) Str::uuid());
        $correlationId = (string) $request->header('X-Correlation-ID', (string) Str::uuid());

        $dispute = $this->service->confirmRefund(
            disputeId: $id,
            confirmedById: $actorId,
            actorRole: $actorRole,
            idempotencyKey: $idempotencyKey,
            correlationId: $correlationId,
            stepUpToken: (string) $request->input('step_up_token', ''),
            sessionId: (string) $request->attributes->get('identity.session_id'),
        );

        return response()->json([
            'data' => new DisputeResource($dispute),
            'success' => true,
        ]);
    }
}
