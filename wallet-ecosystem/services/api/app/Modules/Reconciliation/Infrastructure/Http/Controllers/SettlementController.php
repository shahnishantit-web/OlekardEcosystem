<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Http\Controllers;

use App\Modules\Reconciliation\Application\Services\SettlementService;
use App\Modules\Reconciliation\Infrastructure\Http\Requests\ApproveSettlementRequest;
use App\Modules\Reconciliation\Infrastructure\Http\Requests\CreateSettlementRequest;
use App\Modules\Reconciliation\Infrastructure\Http\Requests\RejectSettlementRequest;
use App\Modules\Reconciliation\Infrastructure\Http\Resources\SettlementDetailResource;
use App\Modules\Reconciliation\Infrastructure\Http\Resources\SettlementSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class SettlementController
{
    public function __construct(private readonly SettlementService $settlementService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $settlements = $this->settlementService->listSettlements(
            status:  $request->input('status'),
            page:    (int) $request->input('page', 1),
            perPage: (int) $request->input('per_page', 25),
        );

        return SettlementSummaryResource::collection($settlements);
    }

    public function show(string $settlementId): SettlementDetailResource
    {
        $settlement = $this->settlementService->getSettlement($settlementId);
        return new SettlementDetailResource($settlement);
    }

    public function create(CreateSettlementRequest $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key', '');

        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        $settlement = $this->settlementService->calculateAndCreate(
            reconBatchId:   $request->input('recon_batch_id'),
            settlementType: $request->input('settlement_type'),
            periodStart:    $request->input('period_start'),
            periodEnd:      $request->input('period_end'),
            currencyCode:   $request->input('currency_code'),
            makerId:        (string) $request->user()->id,
            idempotencyKey: $idempotencyKey,
            providerCode:   $request->input('provider_code'),
        );

        return (new SettlementDetailResource($settlement))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Submit settlement for approval.
     * Idempotency note: no Idempotency-Key required — 409 if already submitted is the natural guard.
     */
    public function submit(Request $request, string $settlementId): SettlementDetailResource
    {
        $settlement = $this->settlementService->submitForApproval($settlementId, (string) $request->user()->id);
        return new SettlementDetailResource($settlement);
    }

    public function approve(ApproveSettlementRequest $request, string $settlementId): SettlementDetailResource
    {
        $idempotencyKey = $request->header('Idempotency-Key', '');

        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            abort(422, 'Idempotency-Key header is required.');
        }

        $settlement = $this->settlementService->approveSettlement(
            settlementId:   $settlementId,
            checkerId:      (string) $request->user()->id,
            idempotencyKey: $idempotencyKey,
        );

        return new SettlementDetailResource($settlement);
    }

    public function reject(RejectSettlementRequest $request, string $settlementId): SettlementDetailResource
    {
        $settlement = $this->settlementService->rejectSettlement(
            settlementId:     $settlementId,
            checkerId:        (string) $request->user()->id,
            rejectionReason:  $request->input('rejection_reason'),
        );

        return new SettlementDetailResource($settlement);
    }

    public function execute(Request $request, string $settlementId): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key', '');

        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        $settlement = $this->settlementService->executeSettlement(
            settlementId:   $settlementId,
            executorId:     (string) $request->user()->id,
            idempotencyKey: $idempotencyKey,
        );

        return (new SettlementDetailResource($settlement))
            ->response()
            ->setStatusCode(202);
    }
}
