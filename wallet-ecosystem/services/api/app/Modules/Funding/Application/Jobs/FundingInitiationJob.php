<?php

declare(strict_types=1);

namespace App\Modules\Funding\Application\Jobs;

use App\Modules\Funding\Domain\Contracts\FundingProviderAdapter;
use App\Modules\Funding\Domain\Enums\FundingFailureReason;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Domain\Events\FundingTransactionFailed;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use App\Modules\Platform\Concerns\HasCorrelationId;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Asynchronous job that calls FundingProviderAdapter::initiate() after the customer confirms.
 *
 * Resolves analyze finding C2: plan.md was missing a Jobs/ directory.
 *
 * - Runs on the `funding` queue
 * - Up to 3 attempts before marking the transaction as failed (JobDispatchFailed)
 * - On success: updates provider_reference_id; status stays `processing` (webhook completes it)
 * - On failure: transitions to `failed` with reason `job_dispatch_failed`
 */
final class FundingInitiationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use HasCorrelationId;

    public int   $tries   = 3;
    public int   $timeout = 30;
    public array $retryHistory = [];

    public function __construct(
        public readonly string $fundingTransactionId,
        string $correlationId,
    ) {
        $this->correlationId = $correlationId;
        $this->tries         = (int) config('reliability.max_retry_attempts', 3);
        $this->onQueue('funding');
    }

    public function backoff(): array
    {
        $base  = (int) config('reliability.retry_base_delay_seconds', 5);
        $steps = array_map(fn (int $i) => $base * (2 ** $i), range(0, $this->tries - 1));
        return array_slice($steps, 0, $this->tries);
    }

    public function handle(
        FundingProviderAdapter    $adapter,
        EventPublisherInterface   $events,
        MetricsCollectorInterface $metrics,
    ): void {
        $this->injectCorrelationContext();

        $tx = FundingTransactionModel::find($this->fundingTransactionId);

        if ($tx === null) {
            Log::warning('FundingInitiationJob: transaction not found', [
                'id'             => $this->fundingTransactionId,
                'correlation_id' => $this->correlationId,
            ]);
            return;
        }

        // If the transaction was already cancelled/failed between job dispatch and execution, skip
        if ($tx->status !== FundingStatus::Processing) {
            Log::info('FundingInitiationJob: skipped — status not processing', [
                'id'     => $tx->id,
                'status' => $tx->status->value,
            ]);
            return;
        }

        $result = $adapter->initiate([
            'id'                    => $tx->id,
            'amount'                => $tx->gross_amount,
            'currency_code'         => $tx->pocket_currency_code,
            'funding_method'        => $tx->funding_method->value,
            'transaction_reference' => $tx->transaction_reference,
            'correlation_id'        => $this->correlationId,
        ]);

        if ($result['status'] === 'initiated' && $result['provider_reference_id'] !== null) {
            $tx->provider_reference_id = $result['provider_reference_id'];
            $tx->save();

            $metrics->increment('funding.provider.initiation.success', [
                'method' => $tx->funding_method->value,
            ]);
            return;
        }

        // Provider declined — mark failed
        $this->markFailed($tx, $events, $metrics);
    }

    public function failed(\Throwable $exception): void
    {
        $this->retryHistory[] = [
            'attempt'   => $this->attempts(),
            'failed_at' => now()->toIso8601String(),
            'reason'    => $exception->getMessage(),
        ];

        Log::error('FundingInitiationJob failed permanently', [
            'id'             => $this->fundingTransactionId,
            'correlation_id' => $this->correlationId,
            'error'          => $exception->getMessage(),
        ]);

        $tx = FundingTransactionModel::find($this->fundingTransactionId);
        if ($tx !== null && $tx->status === FundingStatus::Processing) {
            $tx->status         = FundingStatus::Failed;
            $tx->failure_reason = FundingFailureReason::JobDispatchFailed;
            $tx->save();
        }
    }

    private function markFailed(
        FundingTransactionModel $tx,
        EventPublisherInterface $events,
        MetricsCollectorInterface $metrics,
    ): void {
        $tx->status         = FundingStatus::Failed;
        $tx->failure_reason = FundingFailureReason::ProviderDeclined;
        $tx->save();

        $events->publish(new FundingTransactionFailed(
            fundingTransactionId: $tx->id,
            customerId:           $tx->customer_id,
            failureReason:        FundingFailureReason::ProviderDeclined->value,
            correlationId:        $this->correlationId,
        ));

        $metrics->increment('funding.provider.initiation.failed', [
            'method' => $tx->funding_method->value,
        ]);
    }
}
