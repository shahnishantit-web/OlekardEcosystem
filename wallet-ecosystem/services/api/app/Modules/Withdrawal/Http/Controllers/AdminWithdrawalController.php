<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Http\Controllers;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalFailureReason;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalStatus;
use App\Modules\Withdrawal\Domain\Events\WithdrawalFailed;
use App\Modules\Withdrawal\Domain\Exceptions\WithdrawalAlreadyTerminalException;
use App\Modules\Withdrawal\Infrastructure\Http\Resources\WithdrawalTransactionResource;
use App\Modules\Withdrawal\Infrastructure\Models\WithdrawalTransactionModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;

final class AdminWithdrawalController extends Controller
{
    public function __construct(
        private readonly WalletModuleContract   $wallet,
        private readonly AuditServiceInterface  $audit,
        private readonly EventPublisherInterface $events,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = WithdrawalTransactionModel::query()
            ->orderBy('created_at', 'desc');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($customerId = $request->query('customer_id')) {
            $query->where('customer_id', $customerId);
        }

        if ($walletId = $request->query('wallet_id')) {
            $query->where('wallet_id', $walletId);
        }

        if ($channel = $request->query('channel')) {
            $query->where('channel', $channel);
        }

        $page = max(1, (int) $request->query('page', '1'));
        $perPage = min(100, max(10, (int) $request->query('per_page', '20')));

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => WithdrawalTransactionResource::collection($paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tx = WithdrawalTransactionModel::find($id);

        if ($tx === null) {
            return response()->json(['message' => 'Withdrawal transaction not found.'], 404);
        }

        return response()->json(new WithdrawalTransactionResource($tx));
    }

    public function forceFail(Request $request, string $id): JsonResponse
    {
        $adminId       = $request->user()?->id ?? $request->header('X-Test-User-Id');
        $rawCorr       = $request->header('X-Correlation-Id');
        $correlationId = is_string($rawCorr) ? $rawCorr : (string) \Ramsey\Uuid\Uuid::uuid7();

        $tx = WithdrawalTransactionModel::find($id);

        if ($tx === null) {
            return response()->json(['message' => 'Withdrawal transaction not found.'], 404);
        }

        if ($tx->isTerminal()) {
            return response()->json([
                'error'   => 'ALREADY_TERMINAL',
                'message' => "Withdrawal [{$id}] is already in a terminal state: {$tx->status->value}.",
            ], 422);
        }

        try {
            DB::connection('platform')->transaction(function () use ($tx, $adminId, $correlationId): void {
                $tx->refresh();

                if ($tx->isTerminal()) {
                    throw new WithdrawalAlreadyTerminalException($tx->status->value);
                }

                if ($tx->wallet_hold_id !== null) {
                    try {
                        $this->wallet->releaseHold($tx->wallet_hold_id, $correlationId);
                    } catch (\Throwable) {
                        // Best-effort release; admin force-fail proceeds regardless
                    }
                }

                $tx->status         = WithdrawalStatus::Failed;
                $tx->failure_reason = WithdrawalFailureReason::AdminForceFailed;
                $tx->failed_at      = now();
                $tx->save();

                $this->events->publish(new WithdrawalFailed(
                    withdrawalTransactionId: $tx->id,
                    walletId:                $tx->wallet_id,
                    currencyCode:            $tx->pocket_currency_code,
                    grossAmount:             $tx->gross_amount,
                    channel:                 $tx->channel->value,
                    failureReason:           WithdrawalFailureReason::AdminForceFailed->value,
                    occurredAt:              now()->toIso8601String(),
                    correlationId:           $correlationId,
                ));

                $this->audit->record(new AuditEventData(
                    action:        'withdrawal.admin.force_fail',
                    actorId:       (string) $adminId,
                    actorType:     'ADMIN',
                    resourceType:  'withdrawal_transaction',
                    resourceId:    $tx->id,
                    correlationId: $correlationId,
                    outcome:       'success',
                    afterState:    [
                        'status'         => WithdrawalStatus::Failed->value,
                        'failure_reason' => WithdrawalFailureReason::AdminForceFailed->value,
                    ],
                ));
            });
        } catch (WithdrawalAlreadyTerminalException $e) {
            return response()->json(['error' => 'ALREADY_TERMINAL', 'message' => $e->getMessage()], 422);
        }

        $tx->refresh();
        return response()->json(new WithdrawalTransactionResource($tx));
    }
}
