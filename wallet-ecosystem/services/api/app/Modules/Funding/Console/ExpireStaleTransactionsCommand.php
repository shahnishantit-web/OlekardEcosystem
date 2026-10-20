<?php

declare(strict_types=1);

namespace App\Modules\Funding\Console;

use App\Modules\Funding\Domain\Enums\FundingFailureReason;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Domain\Events\FundingTransactionExpired;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Artisan command: funding:expire-stale
 *
 * Transitions:
 * 1. `processing` → `failed` (reason: processing_timeout) when older than N minutes
 * 2. `awaiting_match` → `failed` (reason: bank_reference_expired) when reference is past expiry
 *
 * Designed for cron execution (every minute per FundingServiceProvider::scheduleExpiry).
 * Each batch is processed in a DB transaction. Emits FundingTransactionExpired event per row.
 */
final class ExpireStaleTransactionsCommand extends Command
{
    protected $signature   = 'funding:expire-stale';
    protected $description = 'Expire stale processing and awaiting_match funding transactions';

    public function __construct(
        private readonly EventPublisherInterface   $events,
        private readonly AuditServiceInterface     $audit,
        private readonly MetricsCollectorInterface $metrics,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $correlationId    = (string) Uuid::uuid7();
        $processingCutoff = Carbon::now()->subMinutes((int) config('funding.processing_timeout_minutes', 30));

        $expiredProcessing = FundingTransactionModel::scopeStaleProcessing(
            FundingTransactionModel::query(),
            $processingCutoff,
        )->get();

        $expiredBankRef = FundingTransactionModel::scopeExpiredBankRef(
            FundingTransactionModel::query(),
        )->get();

        $this->expireGroup($expiredProcessing, FundingFailureReason::ProcessingTimeout, $correlationId);
        $this->expireGroup($expiredBankRef, FundingFailureReason::BankReferenceExpired, $correlationId);

        $totalExpired = $expiredProcessing->count() + $expiredBankRef->count();

        if ($totalExpired > 0) {
            $this->info("Expired {$totalExpired} stale funding transaction(s).");
            $this->metrics->gauge('funding.expiry.batch_size', (float) $totalExpired, []);
        }

        return Command::SUCCESS;
    }

    private function expireGroup(
        \Illuminate\Support\Collection $transactions,
        FundingFailureReason $reason,
        string $correlationId,
    ): void {
        foreach ($transactions as $tx) {
            DB::transaction(function () use ($tx, $reason, $correlationId): void {
                $tx->refresh();

                if ($tx->status->isTerminal()) {
                    return; // Already terminated — skip
                }

                $tx->status         = FundingStatus::Failed;
                $tx->failure_reason = $reason;
                $tx->save();

                $this->events->publish(new FundingTransactionExpired(
                    fundingTransactionId: $tx->id,
                    customerId:           $tx->customer_id,
                    failureReason:        $reason->value,
                    correlationId:        $correlationId,
                ));

                $this->audit->record(new AuditEventData(
                    action:       'funding.transaction.expired',
                    actorType:    'SYSTEM',
                    resourceType: 'funding_transaction',
                    correlationId: $correlationId,
                    outcome:      'success',
                    resourceId:   $tx->id,
                    afterState:   ['failure_reason' => $reason->value],
                ));
            });
        }
    }
}
