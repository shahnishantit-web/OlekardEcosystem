<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Http\Controllers;

use App\Modules\Commission\Application\Services\CommissionReversalService;
use App\Modules\Commission\Domain\Exceptions\CommissionAlreadyReversedException;
use App\Modules\Commission\Infrastructure\Http\Requests\ReverseCommissionRequest;
use App\Modules\Commission\Infrastructure\Http\Resources\CommissionResource;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class CommissionReversalController extends Controller
{
    public function __construct(
        private readonly CommissionReversalService   $service,
        private readonly IdempotencyServiceInterface $idempotency,
    ) {}

    public function reverse(ReverseCommissionRequest $request, string $commissionId): JsonResponse
    {
        $actorId        = (string) $request->attributes->get('identity.user_id');
        $idempotencyKey = 'commission_reversal:' . $request->header('Idempotency-Key');
        $correlationId  = $request->header('X-Correlation-Id', Str::uuid()->toString());

        $cached = $this->idempotency->findCompletedResponse($idempotencyKey, $actorId);
        if ($cached !== null) {
            return response()->json($cached['body'], $cached['status_code']);
        }

        try {
            $commission = $this->service->reverse(
                commissionId:  $commissionId,
                actorId:       $actorId,
                reason:        $request->validated('reason'),
                correlationId: $correlationId,
            );
        } catch (CommissionAlreadyReversedException $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], 409);
        }

        $responseBody = (new CommissionResource($commission))->toArray($request);

        $lock = $this->idempotency->acquireLock(
            $idempotencyKey,
            $actorId,
            md5($commissionId . $request->validated('reason')),
            'POST',
            $request->path(),
            $correlationId,
        );
        $this->idempotency->recordResponse($lock, 200, $responseBody);

        return response()->json(['data' => $responseBody], 200);
    }
}
