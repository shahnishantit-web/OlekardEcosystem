<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Infrastructure\Http\Controllers;

use App\Modules\Wallet\Domain\Exceptions\InsufficientBalanceException as WalletInsufficientBalanceException;
use App\Modules\Wallet\Domain\Exceptions\WalletNotTransactableException;
use App\Modules\Withdrawal\Application\Services\WithdrawalCancellationService;
use App\Modules\Withdrawal\Application\Services\WithdrawalConfirmationService;
use App\Modules\Withdrawal\Application\Services\WithdrawalInitiationService;
use App\Modules\Withdrawal\Application\Services\WithdrawalLimitService;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalChannel;
use App\Modules\Withdrawal\Domain\Exceptions\FeeMismatchException;
use App\Modules\Withdrawal\Domain\Exceptions\InsufficientBalanceException;
use App\Modules\Withdrawal\Domain\Exceptions\InvalidPinException;
use App\Modules\Withdrawal\Domain\Exceptions\WithdrawalLimitViolationException;
use App\Modules\Withdrawal\Domain\Exceptions\WithdrawalNotCancellableException;
use App\Modules\Withdrawal\Domain\Exceptions\WithdrawalNotModifiableException;
use App\Modules\Withdrawal\Domain\Exceptions\WithdrawalRiskRejectedException;
use App\Modules\Withdrawal\Infrastructure\Http\Requests\WithdrawalConfirmRequest;
use App\Modules\Withdrawal\Infrastructure\Http\Requests\WithdrawalInitiateRequest;
use App\Modules\Withdrawal\Infrastructure\Http\Resources\WithdrawalTransactionResource;
use App\Modules\Withdrawal\Infrastructure\Models\WithdrawalTransactionModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ramsey\Uuid\Uuid;

final class WithdrawalController extends Controller
{
    public function __construct(
        private readonly WithdrawalInitiationService   $initiationService,
        private readonly WithdrawalConfirmationService $confirmationService,
        private readonly WithdrawalCancellationService $cancellationService,
        private readonly WithdrawalLimitService        $limitService,
    ) {}

    /** POST /api/v1/withdrawals */
    public function initiate(WithdrawalInitiateRequest $request): JsonResponse
    {
        $rawIdempotency = $request->header('Idempotency-Key');
        if (empty($rawIdempotency) || ! is_string($rawIdempotency)) {
            return response()->json([
                'error' => ['code' => 'MISSING_IDEMPOTENCY_KEY', 'message' => 'Idempotency-Key header is required.'],
            ], 400);
        }
        $idempotencyKey = $rawIdempotency;

        $customerId    = $request->user()?->id ?? $request->header('X-Test-User-Id');
        $rawCorr       = $request->header('X-Correlation-Id');
        $correlationId = is_string($rawCorr) ? $rawCorr : (string) Uuid::uuid7();
        $channel       = WithdrawalChannel::from($request->input('channel'));

        try {
            $tx = $this->initiationService->initiate([
                'customer_id'        => $customerId,
                'wallet_id'          => $request->input('wallet_id'),
                'currency_code'      => $request->input('currency_code'),
                'channel'            => $channel,
                'gross_amount'       => $request->integer('gross_amount'),
                'payout_destination' => $request->input('payout_destination'),
                'idempotency_key'    => $idempotencyKey,
                'correlation_id'     => $correlationId,
            ]);
        } catch (InsufficientBalanceException $e) {
            return response()->json([
                'error' => ['code' => 'INSUFFICIENT_BALANCE', 'message' => $e->getMessage()],
            ], 422);
        } catch (WithdrawalLimitViolationException $e) {
            return response()->json([
                'error' => ['code' => $e->violationCode, 'message' => $e->getMessage(), 'details' => $e->details],
            ], 422);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => $e->getMessage()],
            ], 422);
        }

        $limits = $this->limitService->getLimits($channel, $request->input('currency_code'), $customerId);

        return response()->json([
            'data' => (new WithdrawalTransactionResource($tx))->additional(['limits' => $limits]),
        ], 201);
    }

    /** GET /api/v1/withdrawals */
    public function index(Request $request): JsonResponse
    {
        $customerId = $request->user()?->id ?? $request->header('X-Test-User-Id');

        $query = WithdrawalTransactionModel::query()
            ->where('customer_id', $customerId);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('channel')) {
            $query->where('channel', $request->input('channel'));
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $results = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => WithdrawalTransactionResource::collection($results->items()),
            'meta' => [
                'total'        => $results->total(),
                'page'         => $results->currentPage(),
                'per_page'     => $results->perPage(),
                'pages'        => $results->lastPage(),
            ],
        ]);
    }

    /** GET /api/v1/withdrawals/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        $customerId = $request->user()?->id ?? $request->header('X-Test-User-Id');

        $tx = WithdrawalTransactionModel::where('id', $id)
            ->where('customer_id', $customerId)
            ->first();

        if ($tx === null) {
            return response()->json([
                'error' => ['code' => 'WITHDRAWAL_NOT_FOUND', 'message' => 'Withdrawal transaction not found.'],
            ], 404);
        }

        return response()->json(['data' => new WithdrawalTransactionResource($tx)]);
    }

    /** POST /api/v1/withdrawals/{id}/confirm */
    public function confirm(WithdrawalConfirmRequest $request, string $id): JsonResponse
    {
        $rawIdempotency2 = $request->header('Idempotency-Key');
        if (empty($rawIdempotency2) || ! is_string($rawIdempotency2)) {
            return response()->json([
                'error' => ['code' => 'MISSING_IDEMPOTENCY_KEY', 'message' => 'Idempotency-Key header is required.'],
            ], 400);
        }
        $idempotencyKey = $rawIdempotency2;

        $customerId    = $request->user()?->id ?? $request->header('X-Test-User-Id');
        $rawCorr2      = $request->header('X-Correlation-Id');
        $correlationId = is_string($rawCorr2) ? $rawCorr2 : (string) Uuid::uuid7();

        try {
            $tx = $this->confirmationService->confirm(
                withdrawalId:     $id,
                customerId:       $customerId,
                pin:              $request->input('transaction_pin'),
                quotedFeeAmount:  $request->integer('quoted_fee_amount'),
                idempotencyKey:   $idempotencyKey,
                correlationId:    $correlationId,
            );
        } catch (FeeMismatchException $e) {
            return response()->json([
                'error' => [
                    'code'                   => 'FEE_CHANGED',
                    'message'                => $e->getMessage(),
                    'updated_fee_amount'     => $e->updatedFeeAmount,
                    'updated_net_payout_amount' => $e->updatedNetPayoutAmount,
                ],
            ], 409);
        } catch (InvalidPinException $e) {
            return response()->json([
                'error' => ['code' => 'INVALID_PIN', 'message' => $e->getMessage()],
            ], 422);
        } catch (WithdrawalRiskRejectedException $e) {
            return response()->json([
                'error' => ['code' => 'WITHDRAWAL_RISK_REJECTED', 'message' => $e->getMessage()],
            ], 422);
        } catch (WithdrawalNotModifiableException $e) {
            return response()->json([
                'error' => ['code' => 'TRANSACTION_NOT_MODIFIABLE', 'message' => $e->getMessage()],
            ], 422);
        } catch (InsufficientBalanceException | WalletInsufficientBalanceException $e) {
            return response()->json([
                'error' => ['code' => 'INSUFFICIENT_BALANCE', 'message' => $e->getMessage()],
            ], 422);
        } catch (WalletNotTransactableException $e) {
            return response()->json([
                'error' => ['code' => 'WALLET_SUSPENDED', 'message' => $e->getMessage()],
            ], 422);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => ['code' => 'WITHDRAWAL_NOT_FOUND', 'message' => $e->getMessage()],
            ], 404);
        }

        return response()->json(['data' => new WithdrawalTransactionResource($tx)]);
    }

    /** POST /api/v1/withdrawals/{id}/cancel */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $customerId    = $request->user()?->id ?? $request->header('X-Test-User-Id');
        $rawCorr3      = $request->header('X-Correlation-Id');
        $correlationId = is_string($rawCorr3) ? $rawCorr3 : (string) Uuid::uuid7();

        try {
            $tx = $this->cancellationService->cancel(
                withdrawalId:  $id,
                customerId:    $customerId,
                correlationId: $correlationId,
            );
        } catch (WithdrawalNotCancellableException $e) {
            return response()->json([
                'error' => ['code' => 'TRANSACTION_NOT_CANCELLABLE', 'message' => $e->getMessage()],
            ], 422);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => ['code' => 'WITHDRAWAL_NOT_FOUND', 'message' => $e->getMessage()],
            ], 404);
        }

        return response()->json(['data' => new WithdrawalTransactionResource($tx)]);
    }
}
