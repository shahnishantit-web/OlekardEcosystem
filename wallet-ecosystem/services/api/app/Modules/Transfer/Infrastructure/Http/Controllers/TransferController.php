<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Infrastructure\Http\Controllers;

use App\Modules\Transfer\Application\Services\TransferCancellationService;
use App\Modules\Transfer\Application\Services\TransferConfirmationService;
use App\Modules\Transfer\Application\Services\TransferInitiationService;
use App\Modules\Transfer\Domain\Exceptions\FeeMismatchException;
use App\Modules\Transfer\Domain\Exceptions\InsufficientBalanceException;
use App\Modules\Transfer\Domain\Exceptions\InvalidLookupTokenException;
use App\Modules\Transfer\Domain\Exceptions\InvalidPinException;
use App\Modules\Transfer\Domain\Exceptions\TransferNotCancellableException;
use App\Modules\Transfer\Domain\Exceptions\TransferNotModifiableException;
use App\Modules\Transfer\Infrastructure\Http\Requests\TransferConfirmRequest;
use App\Modules\Transfer\Infrastructure\Http\Requests\TransferInitiateRequest;
use App\Modules\Transfer\Infrastructure\Http\Resources\TransferReceiptResource;
use App\Modules\Transfer\Infrastructure\Http\Resources\TransferResource;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ramsey\Uuid\Uuid;

final class TransferController extends Controller
{
    public function __construct(
        private readonly TransferInitiationService   $initiationService,
        private readonly TransferConfirmationService $confirmationService,
        private readonly TransferCancellationService $cancellationService,
    ) {}

    /** POST /api/v1/transfers */
    public function initiate(TransferInitiateRequest $request): JsonResponse
    {
        $actorId        = $request->user()?->id ?? $request->header('X-Test-User-Id');
        $idempotencyKey = $request->header('Idempotency-Key');
        $correlationId  = $request->header('X-Correlation-Id', (string) Uuid::uuid7());

        try {
            $tx = $this->initiationService->initiate([
                'sender_customer_id' => $actorId,
                'sender_wallet_id'   => $request->input('wallet_id'),
                'lookup_token'       => $request->input('lookup_token'),
                'gross_amount'       => $request->integer('gross_amount'),
                'currency_code'      => $request->input('currency_code'),
                'idempotency_key'    => $idempotencyKey,
                'correlation_id'     => $correlationId,
            ]);
        } catch (InvalidLookupTokenException $e) {
            return response()->json([
                'error' => ['code' => 'INVALID_LOOKUP_TOKEN', 'message' => $e->getMessage()],
            ], 422);
        } catch (InsufficientBalanceException $e) {
            return response()->json([
                'error' => ['code' => 'INSUFFICIENT_BALANCE', 'message' => $e->getMessage()],
            ], 422);
        } catch (\App\Modules\Transfer\Domain\Exceptions\DailyLimitExceededException $e) {
            return response()->json([
                'error' => ['code' => 'DAILY_LIMIT_EXCEEDED', 'message' => $e->getMessage(), 'remaining' => $e->remaining],
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $e->getMessage()],
            ], 422);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => ['code' => 'TRANSFER_ERROR', 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json(['data' => new TransferResource($tx)], 201);
    }

    /** POST /api/v1/transfers/{transferId}/confirm */
    public function confirm(TransferConfirmRequest $request, string $transferId): JsonResponse
    {
        $actorId        = $request->user()?->id ?? $request->header('X-Test-User-Id');
        $idempotencyKey = $request->header('Idempotency-Key');
        $correlationId  = $request->header('X-Correlation-Id', (string) Uuid::uuid7());

        try {
            $tx = $this->confirmationService->execute(
                transferId:     $transferId,
                customerId:     $actorId,
                pin:            $request->input('transaction_pin'),
                idempotencyKey: $idempotencyKey,
                correlationId:  $correlationId,
            );
        } catch (FeeMismatchException $e) {
            return response()->json([
                'error' => [
                    'code'          => 'FEE_MISMATCH',
                    'message'       => $e->getMessage(),
                    'new_fee_amount'    => $e->newFeeAmount,
                    'new_net_amount'    => $e->newNetAmount,
                    'new_fee_rate_bps'  => $e->newFeeRateBps,
                ],
            ], 409);
        } catch (InvalidPinException $e) {
            return response()->json([
                'error' => ['code' => 'INVALID_PIN', 'message' => $e->getMessage()],
            ], 422);
        } catch (TransferNotModifiableException $e) {
            return response()->json([
                'error' => ['code' => 'TRANSFER_NOT_MODIFIABLE', 'message' => $e->getMessage()],
            ], 422);
        } catch (InsufficientBalanceException $e) {
            return response()->json([
                'error' => ['code' => 'INSUFFICIENT_BALANCE', 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json([
            'data'    => new TransferResource($tx),
            'receipt' => $tx->receipt ? new TransferReceiptResource($tx->receipt) : null,
        ], 200);
    }

    /** POST /api/v1/transfers/{transferId}/cancel */
    public function cancel(Request $request, string $transferId): JsonResponse
    {
        $actorId       = $request->user()?->id ?? $request->header('X-Test-User-Id');
        $correlationId = $request->header('X-Correlation-Id', (string) Uuid::uuid7());

        try {
            $tx = $this->cancellationService->cancel(
                transferId:    $transferId,
                customerId:    $actorId,
                correlationId: $correlationId,
            );
        } catch (TransferNotCancellableException $e) {
            return response()->json([
                'error' => ['code' => 'TRANSFER_NOT_CANCELLABLE', 'message' => $e->getMessage()],
            ], 422);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => ['code' => 'NOT_FOUND', 'message' => $e->getMessage()],
            ], 404);
        }

        return response()->json(['data' => new TransferResource($tx)], 200);
    }

    /** GET /api/v1/transfers */
    public function index(Request $request): JsonResponse
    {
        $actorId = $request->user()?->id ?? $request->header('X-Test-User-Id');

        $query = TransferTransactionModel::where(function ($q) use ($actorId): void {
            $q->where('sender_customer_id', $actorId)
              ->orWhere('recipient_customer_id', $actorId);
        });

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('from_date')) {
            $query->where('created_at', '>=', $request->input('from_date'));
        }

        if ($request->has('to_date')) {
            $query->where('created_at', '<=', $request->input('to_date'));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $results = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => TransferResource::collection($results->items()),
            'meta' => [
                'current_page' => $results->currentPage(),
                'per_page'     => $results->perPage(),
                'total'        => $results->total(),
                'last_page'    => $results->lastPage(),
            ],
        ]);
    }

    /** GET /api/v1/transfers/{transferId} */
    public function show(Request $request, string $transferId): JsonResponse
    {
        $actorId = $request->user()?->id ?? $request->header('X-Test-User-Id');

        $tx = TransferTransactionModel::find($transferId);

        if ($tx === null
            || ($tx->sender_customer_id !== $actorId && $tx->recipient_customer_id !== $actorId)) {
            return response()->json([
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Transfer not found.'],
            ], 404);
        }

        return response()->json(['data' => new TransferResource($tx)]);
    }
}
