<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Http\Controllers;

use App\Modules\Transaction\Application\Services\RefundService;
use App\Modules\Transaction\Domain\Exceptions\TransactionNotFoundException;
use App\Modules\Transaction\Domain\Exceptions\TransactionNotReversibleException;
use App\Modules\Transaction\Infrastructure\Http\Requests\InitiateRefundRequest;
use App\Modules\Transaction\Infrastructure\Http\Resources\TransactionProjectionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Ramsey\Uuid\Uuid;

final class RefundController extends Controller
{
    public function __construct(
        private readonly RefundService $refundService,
    ) {}

    /** POST /api/v1/transactions/{id}/refunds */
    public function initiate(InitiateRefundRequest $request, string $id): JsonResponse
    {
        $idempotencyKey = $request->idempotencyKey();
        $initiatedBy    = (string) ($request->user()?->id ?? $request->header('X-Test-User-Id') ?? '');
        $rawCorr       = $request->header('X-Correlation-Id');
        $correlationId = is_string($rawCorr) ? $rawCorr : (string) Uuid::uuid7();

        try {
            $refund = $this->refundService->initiate(
                transactionId:  $id,
                initiatedBy:    $initiatedBy,
                idempotencyKey: $idempotencyKey,
                reason:         $request->input('reason'),
                correlationId:  $correlationId,
            );

            return response()->json(['data' => new TransactionProjectionResource($refund)], 201);
        } catch (TransactionNotFoundException $e) {
            return response()->json(['error' => ['code' => 'TRANSACTION_NOT_FOUND', 'message' => $e->getMessage()]], 404);
        } catch (TransactionNotReversibleException $e) {
            return response()->json(['error' => ['code' => 'TRANSACTION_NOT_REFUNDABLE', 'message' => $e->getMessage()]], 422);
        }
    }
}
