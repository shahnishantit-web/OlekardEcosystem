<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\OrphanDetectableInterface;
use App\Modules\Platform\Domain\Events\TransactionReversedEvent;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Transfer\Domain\Enums\TransferFailureReason;
use App\Modules\Transfer\Domain\Enums\TransferStatus;
use App\Modules\Transfer\Domain\Events\TransferCompleted;
use App\Modules\Transfer\Domain\Events\TransferFailed;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * E27 T074/T075 — Transfer module orphan detection and auto-recovery.
 *
 * Transfers are internal wallet-to-wallet operations with no external provider.
 * Recovery strategy:
 * - ledger_journal_id present → debit/credit already posted → mark Completed
 * - ledger_journal_id absent  → no ledger impact yet → mark Failed (no compensating entry needed)
 */
final class TransferOrphanRecoveryService implements OrphanDetectableInterface
{
    public function __construct(
        private readonly AuditServiceInterface   $audit,
        private readonly EventPublisherInterface $events,
    ) {}

    public function findOrphanedTransactionIds(Carbon $threshold): array
    {
        return TransferTransactionModel::query()
            ->whereIn('status', [
                TransferStatus::Pending->value,
                TransferStatus::TimeoutPendingReview->value,
            ])
            ->where('updated_at', '<', $threshold)
            ->pluck('id')
            ->toArray();
    }

    public function recoverOrphanedTransaction(string $transactionId, string $correlationId): string
    {
        $outcome = DB::connection('platform')->transaction(function () use ($transactionId, $correlationId): string {
            $tx = TransferTransactionModel::where('id', $transactionId)
                ->lockForUpdate()
                ->first();

            if ($tx === null) {
                return 'skipped';
            }

            if (!in_array($tx->status, [TransferStatus::Pending, TransferStatus::TimeoutPendingReview], true)) {
                return 'skipped';
            }

            $now = now()->toIso8601String();

            if ($tx->ledger_journal_id !== null) {
                // Ledger entries were posted — money moved; mark as completed
                $tx->status       = TransferStatus::Completed;
                $tx->completed_at = now();
                $tx->save();

                $this->events->publish(new TransferCompleted(
                    transferId:        $tx->id,
                    senderWalletId:    $tx->sender_wallet_id,
                    recipientWalletId: $tx->recipient_wallet_id,
                    currencyCode:      $tx->currency_code,
                    grossAmount:       $tx->gross_amount,
                    feeAmount:         $tx->fee_amount,
                    netAmount:         $tx->net_amount,
                    receiptReference:  $tx->transfer_reference,
                    occurredAt:        $now,
                    correlationId:     $correlationId,
                ));

                $this->audit->record(new AuditEventData(
                    action:        'transfer.transaction.orphan_completed',
                    actorId:       $tx->sender_customer_id,
                    actorType:     'SYSTEM',
                    resourceType:  'transfer_transaction',
                    resourceId:    $tx->id,
                    correlationId: $correlationId,
                    outcome:       'success',
                    afterState:    ['status' => TransferStatus::Completed->value],
                ));

                Log::info('transfer.orphan.recovered_as_completed', [
                    'transaction_id' => $transactionId,
                    'correlation_id' => $correlationId,
                ]);
                return 'recovered';
            }

            // No ledger entries — no financial impact → mark as failed
            $tx->status         = TransferStatus::Failed;
            $tx->failure_reason = TransferFailureReason::SystemError;
            $tx->save();

            $this->events->publish(new TransferFailed(
                transferId:     $tx->id,
                senderWalletId: $tx->sender_wallet_id,
                currencyCode:   $tx->currency_code,
                grossAmount:    $tx->gross_amount,
                failureReason:  TransferFailureReason::SystemError->value,
                occurredAt:     $now,
                correlationId:  $correlationId,
            ));

            $this->events->publish(new TransactionReversedEvent(
                transactionId: $tx->id,
                moduleType:    'transfer',
                walletId:      $tx->sender_wallet_id,
                amount:        $tx->gross_amount,
                currency:      $tx->currency_code,
                correlationId: $correlationId,
                reversedAt:    $now,
            ));

            $this->audit->record(new AuditEventData(
                action:        'transfer.transaction.orphan_failed',
                actorId:       $tx->sender_customer_id,
                actorType:     'SYSTEM',
                resourceType:  'transfer_transaction',
                resourceId:    $tx->id,
                correlationId: $correlationId,
                outcome:       'failure',
                afterState:    ['status' => TransferStatus::Failed->value],
            ));

            Log::info('transfer.orphan.reversed_as_failed', [
                'transaction_id' => $transactionId,
                'correlation_id' => $correlationId,
            ]);
            return 'reversed';
        });

        return $outcome;
    }
}
