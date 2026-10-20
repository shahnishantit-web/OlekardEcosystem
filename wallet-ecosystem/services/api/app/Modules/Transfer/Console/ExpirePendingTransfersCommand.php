<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Console;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Transfer\Domain\Enums\TransferFailureReason;
use App\Modules\Transfer\Domain\Enums\TransferStatus;
use App\Modules\Transfer\Domain\Events\TransferFailed;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Artisan command: transfer:expire-pending
 *
 * Transitions pending transfers older than `transfer.pending_ttl_minutes` to
 * failed(SYSTEM_ERROR) per FR-E09-046 / FR-E09-047.
 *
 * Idempotent: re-running after a partial batch has no effect on already-failed records.
 * Scheduled every minute by TransferServiceProvider.
 */
final class ExpirePendingTransfersCommand extends Command
{
    protected $signature   = 'transfer:expire-pending';
    protected $description = 'Expire pending transfers that have exceeded the TTL';

    public function __construct(
        private readonly EventPublisherInterface   $events,
        private readonly AuditServiceInterface     $audit,
        private readonly MetricsCollectorInterface $metrics,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $ttlMinutes = (int) config('transfer.pending_ttl_minutes', 10);
        $cutoff     = Carbon::now()->subMinutes($ttlMinutes);
        $correlationId = (string) Uuid::uuid7();

        $expired = TransferTransactionModel::scopeExpiredPending(
            TransferTransactionModel::query(),
            $cutoff,
        )->get();

        $count = 0;

        foreach ($expired as $tx) {
            DB::transaction(function () use ($tx, $correlationId, &$count): void {
                $tx->refresh();

                if ($tx->status->isTerminal()) {
                    return;
                }

                $tx->status         = TransferStatus::Failed;
                $tx->failure_reason = TransferFailureReason::SystemError;
                $tx->save();

                $count++;

                $this->events->publish(new TransferFailed(
                    transferId:     $tx->id,
                    senderWalletId: $tx->sender_wallet_id,
                    currencyCode:   $tx->currency_code,
                    grossAmount:    $tx->gross_amount,
                    failureReason:  TransferFailureReason::SystemError->value,
                    occurredAt:     now()->toIso8601String(),
                    correlationId:  $correlationId,
                ));

                $this->audit->record(new AuditEventData(
                    action:        'transfer.transaction.expired',
                    actorType:     'SYSTEM',
                    resourceType:  'transfer_transaction',
                    correlationId: $correlationId,
                    outcome:       'success',
                    resourceId:    $tx->id,
                    afterState:    ['status' => TransferStatus::Failed->value, 'failure_reason' => TransferFailureReason::SystemError->value],
                ));
            });
        }

        if ($count > 0) {
            $this->info("Expired {$count} pending transfer(s).");
            $this->metrics->increment('transfer.expired', ['batch_size' => (string) $count]);
        }

        return Command::SUCCESS;
    }
}
