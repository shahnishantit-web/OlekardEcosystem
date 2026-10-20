<?php

declare(strict_types=1);

namespace App\Modules\Funding\Infrastructure\Http\Controllers;

use App\Modules\Funding\Application\Services\FundingCancellationService;
use App\Modules\Funding\Application\Services\FundingConfirmationService;
use App\Modules\Funding\Application\Services\FundingInitiationService;
use App\Modules\Funding\Domain\Enums\FundingMethod;
use App\Modules\Funding\Infrastructure\Http\Requests\CancelFundingRequest;
use App\Modules\Funding\Infrastructure\Http\Requests\ConfirmFundingRequest;
use App\Modules\Funding\Infrastructure\Http\Requests\InitiateFundingRequest;
use App\Modules\Funding\Infrastructure\Http\Resources\FundingTransactionResource;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class FundingTransactionController extends Controller
{
    public function __construct(
        private readonly FundingInitiationService   $initiationService,
        private readonly FundingConfirmationService  $confirmationService,
        private readonly FundingCancellationService  $cancellationService,
        private readonly IdempotencyServiceInterface $idempotency,
    ) {}

    /**
     * POST /api/v1/funding/transactions
     *
     * FR-E08-001 — FR-E08-015: Initiate a new funding transaction.
     * Idempotency: X-Idempotency-Key header required.
     */
    public function initiate(InitiateFundingRequest $request): JsonResponse
    {
        $actorId       = $request->user()?->id ?? $request->header('X-Test-User-Id');
        $correlationId = $request->header('X-Correlation-Id') ?? (string) \Ramsey\Uuid\Uuid::uuid7();
        $idempotencyKey = $request->header('X-Idempotency-Key');

        if ($idempotencyKey) {
            $cached = $this->idempotency->findCompletedResponse('funding:' . $idempotencyKey, $actorId);
            if ($cached !== null) {
                return response()->json($cached['body'], $cached['status_code']);
            }
        }

        $tx = $this->initiationService->initiate([
            'idempotency_key' => $idempotencyKey ?? $request->header('Idempotency-Key'),
            'customer_id'    => $actorId,
            'wallet_id'      => $request->input('wallet_id'),
            'gross_amount'   => (int) $request->input('gross_amount'),
            'currency_code'  => strtoupper($request->input('currency_code')),
            'funding_method' => FundingMethod::from($request->input('funding_method')),
            'correlation_id' => $correlationId,
            'ip_address'     => $request->ip(),
        ]);

        $responseBody = (new FundingTransactionResource($tx))->toArray($request);

        if ($idempotencyKey) {
            $lock = $this->idempotency->acquireLock(
                'funding:' . $idempotencyKey,
                $actorId,
                md5(json_encode($request->validated())),
                'POST',
                $request->path(),
                $correlationId,
            );
            $this->idempotency->recordResponse($lock, 201, $responseBody);
        }

        return response()->json(['data' => $responseBody], 201);
    }

    /**
     * GET /api/v1/funding/transactions/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $actorId = $request->user()?->id ?? $request->header('X-Test-User-Id');

        $tx = FundingTransactionModel::where('id', $id)
            ->where('customer_id', $actorId)
            ->firstOrFail();

        return response()->json(['data' => (new FundingTransactionResource($tx))->toArray($request)]);
    }

    /**
     * GET /api/v1/funding/transactions
     */
    public function index(Request $request): JsonResponse
    {
        $actorId = $request->user()?->id ?? $request->header('X-Test-User-Id');

        $transactions = FundingTransactionModel::where('customer_id', $actorId)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => FundingTransactionResource::collection($transactions)->toArray($request),
            'meta' => [
                'total'    => $transactions->total(),
                'page'     => $transactions->currentPage(),
                'per_page' => $transactions->perPage(),
                'pages'    => $transactions->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/v1/funding/transactions/{id}/confirm
     *
     * FR-E08-020 — FR-E08-026: Confirm the transaction (PIN verification + fee acceptance).
     * Returns 402 if the fee has changed since initiation (FEE_CHANGED).
     */
    public function confirm(ConfirmFundingRequest $request, string $id): JsonResponse
    {
        $actorId       = $request->user()?->id ?? $request->header('X-Test-User-Id');
        $correlationId = $request->header('X-Correlation-Id') ?? (string) \Ramsey\Uuid\Uuid::uuid7();
        $idempotencyKey = $request->header('X-Idempotency-Key');

        if ($idempotencyKey) {
            $cached = $this->idempotency->findCompletedResponse('funding:confirm:' . $idempotencyKey, $actorId);
            if ($cached !== null) {
                return response()->json($cached['body'], $cached['status_code']);
            }
        }

        try {
            $tx = $this->confirmationService->confirm([
                'transaction_id' => $id,
                'customer_id'    => $actorId,
                'pin'            => $request->input('transaction_pin'),
                'accepted_fee'   => (int) $request->input('accepted_fee_amount'),
                'correlation_id' => $correlationId,
            ]);

            return response()->json(['data' => (new FundingTransactionResource($tx))->toArray($request)]);
        } catch (\App\Modules\Funding\Domain\Exceptions\FeeChangedException $e) {
            return response()->json([
                'error' => [
                    'code'    => 'FEE_CHANGED',
                    'message' => 'The fee has changed since initiation. Review the new fee and re-confirm.',
                    'details' => [
                        'recalculated_fee_amount' => $e->recalculatedFeeAmount,
                        'recalculated_net_amount' => $e->recalculatedNetAmount,
                    ],
                ],
            ], 402);
        } catch (\DomainException $e) {
            return response()->json(['error' => ['code' => 'FUNDING_CONFIRMATION_REJECTED', 'message' => $e->getMessage()]], 422);
        }
    }

    /**
     * POST /api/v1/funding/transactions/{id}/cancel
     */
    public function cancel(CancelFundingRequest $request, string $id): JsonResponse
    {
        $actorId       = $request->user()?->id ?? $request->header('X-Test-User-Id');
        $correlationId = $request->header('X-Correlation-Id') ?? (string) \Ramsey\Uuid\Uuid::uuid7();

        $tx = $this->cancellationService->cancel([
            'transaction_id' => $id,
            'customer_id'    => $actorId,
            'correlation_id' => $correlationId,
        ]);

        return response()->json(['data' => (new FundingTransactionResource($tx))->toArray($request)]);
    }
}
