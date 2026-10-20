<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalFailureReason;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalStatus;
use App\Modules\Withdrawal\Domain\Events\WithdrawalExpired;
use App\Modules\Withdrawal\Infrastructure\Models\WithdrawalTransactionModel;
use Illuminate\Support\Facades\DB;

final class WithdrawalExpiryService
{
    public function __construct(
        private readonly WalletModuleContract    $wallet,
        private readonly WithdrawalMetricsService $metrics,
        private readonly AuditServiceInterface   $audit,
        private readonly EventPublisherInterface $events,
    ) {}

    /**
     * Expire all stale processing withdrawals whose hold TTL has elapsed.
     *
     * @return int Number of transactions expired (or that would be expired in dry-run)
     */
    public function expireStale(bool $dryRun = false): int
    {
        $ttlMinutes = (int) (config('withdrawal.hold_ttl_minutes') ?? 60);
        $stale = WithdrawalTransactionModel::processingAndStale($ttlMinutes)->get();

        if ($dryRun) {
            return $stale->count();
        }

        $expired = 0;

        foreach ($stale as $tx) {
            DB::connection('platform')->transaction(function () use ($tx, &$expired): void {
                $tx->refresh();

                // Guard: only expire if still in processing
                if ($tx->status !== WithdrawalStatus::Processing) {
                    return;
                }

                if ($tx->wallet_hold_id !== null) {
                    try {
                        $this->wallet->releaseHold($tx->wallet_hold_id, $tx->correlation_id);
                        $this->metrics->recordHoldReleased('expired');
                    } catch (\Throwable) {
                        // Best-effort: log via audit but do not block expiry transition
                    }
                }

                $tx->status         = WithdrawalStatus::Failed;
                $tx->failure_reason = WithdrawalFailureReason::ProcessingTimeout;
                $tx->failed_at      = now();
                $tx->save();

                $this->events->publish(new WithdrawalExpired(
                    withdrawalTransactionId: $tx->id,
                    walletId:                $tx->wallet_id,
                    currencyCode:            $tx->pocket_currency_code,
                    grossAmount:             $tx->gross_amount,
                    channel:                 $tx->channel->value,
                    occurredAt:              now()->toIso8601String(),
                    correlationId:           $tx->correlation_id,
                ));

                $this->audit->record(new AuditEventData(
                    action:        'withdrawal.transaction.expired',
                    actorId:       'SYSTEM',
                    actorType:     'SYSTEM',
                    resourceType:  'withdrawal_transaction',
                    resourceId:    $tx->id,
                    correlationId: $tx->correlation_id,
                    outcome:       'success',
                    afterState:    ['status' => WithdrawalStatus::Failed->value, 'failure_reason' => WithdrawalFailureReason::ProcessingTimeout->value],
                ));

                $this->metrics->recordExpired();
                $expired++;
            });
        }

        return $expired;
    }
}
