<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Http\Controllers;

use App\Modules\Transaction\Application\Services\ReversalService;
use App\Modules\Transaction\Domain\Exceptions\ReversalNotApprovableException;
use App\Modules\Transaction\Domain\Exceptions\ReversalNotFoundException;
use App\Modules\Transaction\Domain\Exceptions\TransactionNotFoundException;
use App\Modules\Transaction\Domain\Exceptions\TransactionNotReversibleException;
use App\Modules\Transaction\Infrastructure\Http\Requests\ApproveReversalRequest;
use App\Modules\Transaction\Infrastructure\Http\Requests\RejectReversalRequest;
use App\Modules\Transaction\Infrastructure\Http\Requests\RequestReversalRequest;
use App\Modules\Transaction\Infrastructure\Http\Resources\ReversalAuditLogResource;
use App\Modules\Transaction\Infrastructure\Http\Resources\ReversalResource;
use App\Modules\Transaction\Infrastructure\Models\ReversalModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ramsey\Uuid\Uuid;

final class ReversalController extends Controller
{
    public function __construct(
        private readonly ReversalService $reversalService,
    ) {}

    /** POST /api/v1/transactions/{id}/reversals */
    public function request(RequestReversalRequest $request, string $id): JsonResponse
    {
        $rawIdempotency = $request->header('Idempotency-Key');
        if (empty($rawIdempotency) || ! is_string($rawIdempotency)) {
            return $this->missingIdempotencyKey();
        }

        $requestedBy   = (string) ($request->user()?->id ?? $request->header('X-Test-User-Id') ?? '');
        $correlationId = $this->correlationId($request);

        try {
            $reversal = $this->reversalService->request(
                transactionId:  $id,
                requestedBy:    $requestedBy,
                idempotencyKey: $rawIdempotency,
                reason:         $request->input('reason'),
                correlationId:  $correlationId,
            );

            return response()->json(['data' => new ReversalResource($reversal)], 201);
        } catch (TransactionNotFoundException $e) {
            return response()->json(['error' => ['code' => 'TRANSACTION_NOT_FOUND', 'message' => $e->getMessage()]], 404);
        } catch (TransactionNotReversibleException $e) {
            return response()->json(['error' => ['code' => 'TRANSACTION_NOT_REVERSIBLE', 'message' => $e->getMessage()]], 422);
        }
    }

    /** GET /api/v1/transactions/{id}/reversals */
    public function index(Request $request, string $id): JsonResponse
    {
        $reversals = ReversalModel::where('transaction_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => ReversalResource::collection($reversals)]);
    }

    /** POST /api/v1/transactions/{id}/reversals/{rid}/approve */
    public function approve(ApproveReversalRequest $request, string $id, string $rid): JsonResponse
    {
        $idempotencyKey = $request->idempotencyKey();
        $approvedBy     = (string) ($request->user()?->id ?? $request->header('X-Test-User-Id') ?? '');
        $correlationId  = $this->correlationId($request);

        try {
            $reversal = $this->reversalService->approve(
                reversalId:     $rid,
                approvedBy:     $approvedBy,
                idempotencyKey: $idempotencyKey,
                correlationId:  $correlationId,
            );

            return response()->json(['data' => new ReversalResource($reversal)]);
        } catch (ReversalNotFoundException $e) {
            return response()->json(['error' => ['code' => 'REVERSAL_NOT_FOUND', 'message' => $e->getMessage()]], 404);
        } catch (ReversalNotApprovableException $e) {
            return response()->json(['error' => ['code' => 'REVERSAL_NOT_APPROVABLE', 'message' => $e->getMessage()]], 422);
        }
    }

    /** POST /api/v1/transactions/{id}/reversals/{rid}/reject */
    public function reject(RejectReversalRequest $request, string $id, string $rid): JsonResponse
    {
        $rejectedBy    = (string) ($request->user()?->id ?? $request->header('X-Test-User-Id') ?? '');
        $correlationId = $this->correlationId($request);

        try {
            $reversal = $this->reversalService->reject(
                reversalId:    $rid,
                rejectedBy:    $rejectedBy,
                reason:        $request->input('reason'),
                correlationId: $correlationId,
            );

            return response()->json(['data' => new ReversalResource($reversal)]);
        } catch (ReversalNotFoundException $e) {
            return response()->json(['error' => ['code' => 'REVERSAL_NOT_FOUND', 'message' => $e->getMessage()]], 404);
        } catch (\DomainException $e) {
            return response()->json(['error' => ['code' => 'REVERSAL_NOT_REJECTABLE', 'message' => $e->getMessage()]], 422);
        }
    }

    /** POST /api/v1/transactions/{id}/reversals/{rid}/execute */
    public function execute(Request $request, string $id, string $rid): JsonResponse
    {
        $executedBy    = (string) ($request->user()?->id ?? $request->header('X-Test-User-Id') ?? 'system');
        $correlationId = $this->correlationId($request);

        try {
            $reversal = $this->reversalService->execute(
                reversalId:    $rid,
                executedBy:    $executedBy,
                correlationId: $correlationId,
            );

            return response()->json(['data' => new ReversalResource($reversal)]);
        } catch (ReversalNotFoundException $e) {
            return response()->json(['error' => ['code' => 'REVERSAL_NOT_FOUND', 'message' => $e->getMessage()]], 404);
        } catch (\DomainException $e) {
            return response()->json(['error' => ['code' => 'REVERSAL_NOT_EXECUTABLE', 'message' => $e->getMessage()]], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => ['code' => 'REVERSAL_EXECUTION_FAILED', 'message' => 'Reversal execution failed. See logs.']], 500);
        }
    }

    /** GET /api/v1/transactions/{id}/reversals/{rid}/audit */
    public function audit(Request $request, string $id, string $rid): JsonResponse
    {
        $reversal = ReversalModel::find($rid);

        if ($reversal === null) {
            return response()->json(['error' => ['code' => 'REVERSAL_NOT_FOUND', 'message' => "Reversal [{$rid}] not found."]], 404);
        }

        $log = $reversal->auditLog()->get();

        return response()->json(['data' => ReversalAuditLogResource::collection($log)]);
    }

    private function correlationId(Request $request): string
    {
        $raw = $request->header('X-Correlation-Id');
        return is_string($raw) ? $raw : (string) Uuid::uuid7();
    }

    private function missingIdempotencyKey(): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'MISSING_IDEMPOTENCY_KEY', 'message' => 'Idempotency-Key header is required.'],
        ], 400);
    }
}
