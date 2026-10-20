<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Application\Jobs;

use App\Modules\Platform\Concerns\HasCorrelationId;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use App\Modules\Withdrawal\Domain\Contracts\PayoutProviderAdapter;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalFailureReason;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalStatus;
use App\Modules\Withdrawal\Domain\Events\WithdrawalFailed;
use App\Modules\Withdrawal\Infrastructure\Models\WithdrawalTransactionModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class WithdrawalPayoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use HasCorrelationId;

    public int   $tries   = 3;
    public int   $timeout = 30;
    public array $retryHistory = [];

    public function __construct(
        public readonly string $withdrawalTransactionId,
        string $correlationId = '',
    ) {
        $this->correlationId = $correlationId;
        $this->tries         = (int) config('reliability.max_retry_attempts', 3);
        $this->onQueue('withdrawal');
    }

    public function backoff(): array
    {
        $base  = (int) config('reliability.retry_base_delay_seconds', 5);
        $steps = array_map(fn (int $i) => $base * (2 ** $i), range(0, $this->tries - 1));
        return array_slice($steps, 0, $this->tries);
    }

    public function handle(
        PayoutProviderAdapter $adapter,
        WalletModuleContract  $wallet,
        EventPublisherInterface $events,
        AuditServiceInterface   $audit,
    ): void {
        $this->injectCorrelationContext();

        $tx = WithdrawalTransactionModel::find($this->withdrawalTransactionId);

        if ($tx === null || $tx->status !== WithdrawalStatus::Processing) {
            return;
        }

        $result = $adapter->initiate([
            'id'                  => $tx->id,
            'gross_amount'        => $tx->gross_amount,
            'net_payout_amount'   => $tx->net_payout_amount,
            'currency_code'       => $tx->pocket_currency_code,
            'channel'             => $tx->channel->value,
            'payout_destination'  => $tx->payout_destination,
            'transaction_reference' => $tx->transaction_reference,
            'correlation_id'      => $tx->correlation_id,
        ]);

        if (isset($result['provider_reference_id'])) {
            $tx->provider_reference_id = $result['provider_reference_id'];
            $tx->save();
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->retryHistory[] = [
            'attempt'   => $this->attempts(),
            'failed_at' => now()->toIso8601String(),
            'reason'    => $exception->getMessage(),
        ];

        DB::connection('platform')->transaction(function (): void {
            $tx = WithdrawalTransactionModel::where('id', $this->withdrawalTransactionId)
                ->lockForUpdate()
                ->first();

            if ($tx === null || $tx->status !== WithdrawalStatus::Processing) {
                return;
            }

            $holdId       = $tx->wallet_hold_id;
            $correlationId = $tx->correlation_id;

            if ($holdId !== null) {
                app(WalletModuleContract::class)->releaseHold($holdId, $correlationId);
            }

            $tx->status         = WithdrawalStatus::Failed;
            $tx->failure_reason = WithdrawalFailureReason::JobDispatchFailed;
            $tx->failed_at      = now();
            $tx->save();

            app(EventPublisherInterface::class)->publish(new WithdrawalFailed(
                withdrawalTransactionId: $tx->id,
                walletId:                $tx->wallet_id,
                currencyCode:            $tx->pocket_currency_code,
                grossAmount:             $tx->gross_amount,
                channel:                 $tx->channel->value,
                failureReason:           WithdrawalFailureReason::JobDispatchFailed->value,
                occurredAt:              now()->toIso8601String(),
                correlationId:           $correlationId,
            ));

            app(AuditServiceInterface::class)->record(new AuditEventData(
                action:        'withdrawal.transaction.failed',
                actorId:       $tx->initiated_by,
                actorType:     'CUSTOMER',
                resourceType:  'withdrawal_transaction',
                resourceId:    $tx->id,
                correlationId: $correlationId,
                outcome:       'failure',
                afterState:    ['status' => WithdrawalStatus::Failed->value, 'failure_reason' => WithdrawalFailureReason::JobDispatchFailed->value],
            ));
        });
    }
}
