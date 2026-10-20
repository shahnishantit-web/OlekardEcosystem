<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\OrphanDetectableInterface;
use App\Modules\Platform\Domain\Events\TransactionReversedEvent;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use App\Modules\Withdrawal\Domain\Contracts\PayoutProviderAdapter;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalFailureReason;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalStatus;
use App\Modules\Withdrawal\Domain\Events\WithdrawalFailed;
use App\Modules\Withdrawal\Infrastructure\Models\WithdrawalTransactionModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * E27 T074/T075 — Withdrawal module orphan detection and auto-recovery.
 *
 * Provider re-query via PayoutProviderAdapter::queryStatus():
 * - completed → leave for provider callback to complete the ledger (set back to Processing)
 * - failed    → release wallet hold and mark Failed
 * - ambiguous → leave in timeout_pending_review for manual operator review
 */
final class WithdrawalOrphanRecoveryService implements OrphanDetectableInterface
{
    public function __construct(
        private readonly PayoutProviderAdapter   $adapter,
        private readonly WalletModuleContract    $wallet,
        private readonly AuditServiceInterface   $audit,
        private readonly EventPublisherInterface $events,
    ) {}

    public function findOrphanedTransactionIds(Carbon $threshold): array
    {
        return WithdrawalTransactionModel::query()
            ->whereIn('status', [
                WithdrawalStatus::PendingProvider->value,
                WithdrawalStatus::TimeoutPendingReview->value,
            ])
            ->where('updated_at', '<', $threshold)
            ->pluck('id')
            ->toArray();
    }

    public function recoverOrphanedTransaction(string $transactionId, string $correlationId): string
    {
        $tx = WithdrawalTransactionModel::find($transactionId);

        if ($tx === null) {
            return 'skipped';
        }

        if (!in_array($tx->status, [WithdrawalStatus::PendingProvider, WithdrawalStatus::TimeoutPendingReview], true)) {
            return 'skipped';
        }

        if ($tx->provider_reference_id === null) {
            // Never reached provider — release hold and fail
            return $this->reverseOrphan($tx, $correlationId);
        }

        try {
            $result = $this->adapter->queryStatus($tx->provider_reference_id);
        } catch (\Throwable $e) {
            Log::warning('withdrawal.orphan.provider_query_failed', [
                'transaction_id' => $transactionId,
                'error'          => $e->getMessage(),
                'correlation_id' => $correlationId,
            ]);
            return 'skipped';
        }

        $providerStatus = $result['status'] ?? 'ambiguous';

        if ($providerStatus === 'completed') {
            // Money has left the wallet — revert to Processing so real callback can finalize ledger
            DB::connection('platform')->transaction(function () use ($tx): void {
                $locked = WithdrawalTransactionModel::where('id', $tx->id)->lockForUpdate()->first();
                if ($locked !== null && $locked->status === WithdrawalStatus::TimeoutPendingReview) {
                    $locked->status = WithdrawalStatus::Processing;
                    $locked->save();
                }
            });

            Log::info('withdrawal.orphan.reset_to_processing', [
                'transaction_id' => $transactionId,
                'correlation_id' => $correlationId,
            ]);
            return 'recovered';
        }

        if ($providerStatus === 'failed') {
            return $this->reverseOrphan($tx, $correlationId);
        }

        // ambiguous — leave for manual review
        Log::info('withdrawal.orphan.ambiguous_left_for_review', [
            'transaction_id' => $transactionId,
            'correlation_id' => $correlationId,
        ]);
        return 'skipped';
    }

    private function reverseOrphan(WithdrawalTransactionModel $tx, string $correlationId): string
    {
        DB::connection('platform')->transaction(function () use ($tx, $correlationId): void {
            $locked = WithdrawalTransactionModel::where('id', $tx->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $holdId = $locked->wallet_hold_id;

            if ($holdId !== null) {
                try {
                    $this->wallet->releaseHold($holdId, $correlationId);
                } catch (\Throwable $e) {
                    Log::warning('withdrawal.orphan.hold_release_failed', [
                        'transaction_id' => $tx->id,
                        'hold_id'        => $holdId,
                        'error'          => $e->getMessage(),
                        'correlation_id' => $correlationId,
                    ]);
                }
            }

            $locked->status         = WithdrawalStatus::Failed;
            $locked->failure_reason = WithdrawalFailureReason::JobDispatchFailed;
            $locked->failed_at      = now();
            $locked->save();

            $this->events->publish(new WithdrawalFailed(
                withdrawalTransactionId: $tx->id,
                walletId:                $tx->wallet_id,
                currencyCode:            $tx->pocket_currency_code,
                grossAmount:             $tx->gross_amount,
                channel:                 $tx->channel->value,
                failureReason:           WithdrawalFailureReason::JobDispatchFailed->value,
                occurredAt:              now()->toIso8601String(),
                correlationId:           $correlationId,
            ));

            $this->events->publish(new TransactionReversedEvent(
                transactionId: $tx->id,
                moduleType:    'withdrawal',
                walletId:      $tx->wallet_id,
                amount:        $tx->gross_amount,
                currency:      $tx->pocket_currency_code,
                correlationId: $correlationId,
                reversedAt:    now()->toIso8601String(),
            ));

            $this->audit->record(new AuditEventData(
                action:        'withdrawal.transaction.orphan_reversed',
                actorId:       $tx->initiated_by,
                actorType:     'SYSTEM',
                resourceType:  'withdrawal_transaction',
                resourceId:    $tx->id,
                correlationId: $correlationId,
                outcome:       'failure',
                afterState:    ['status' => WithdrawalStatus::Failed->value, 'recovery' => 'orphan_auto_reverse'],
            ));
        });

        Log::info('withdrawal.orphan.reversed', [
            'transaction_id' => $tx->id,
            'correlation_id' => $correlationId,
        ]);
        return 'reversed';
    }
}
