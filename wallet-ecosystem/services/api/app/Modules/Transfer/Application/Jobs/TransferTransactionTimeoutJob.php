<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Application\Jobs;

use App\Modules\Platform\Application\Services\ConfigurationService;
use App\Modules\Platform\Concerns\HasCorrelationId;
use App\Modules\Transfer\Domain\Enums\TransferStatus;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * E27 US3: Detects transfer transactions stuck in pending beyond the orphan threshold
 * and transitions them to timeout_pending_review for manual review.
 */
final class TransferTransactionTimeoutJob implements ShouldQueue
{
    use Queueable;
    use HasCorrelationId;

    public int   $tries   = 3;
    public int   $timeout = 120;
    public array $retryHistory = [];

    public function __construct(string $correlationId)
    {
        $this->correlationId = $correlationId;
        $this->tries         = (int) config('reliability.max_retry_attempts', 3);
        $this->onQueue('reliability');
    }

    public function backoff(): array
    {
        $base  = (int) config('reliability.retry_base_delay_seconds', 5);
        $steps = array_map(fn (int $i) => $base * (2 ** $i), range(0, $this->tries - 1));
        return array_slice($steps, 0, $this->tries);
    }

    public function handle(ConfigurationService $config): void
    {
        $this->injectCorrelationContext();

        $thresholdMinutes = (int) ($config->getOrDefault(
            'reliability.orphan_threshold_minutes',
            config('reliability.orphan_threshold_minutes', 5)
        ));

        $threshold = now()->subMinutes($thresholdMinutes);

        $orphans = TransferTransactionModel::query()
            ->where('status', TransferStatus::Pending->value)
            ->where('updated_at', '<', $threshold)
            ->get();

        foreach ($orphans as $tx) {
            $tx->status = TransferStatus::TimeoutPendingReview;
            $tx->save();

            Log::warning('transfer.transaction.timeout_detected', [
                'transaction_id'    => $tx->id,
                'status_before'     => TransferStatus::Pending->value,
                'threshold_minutes' => $thresholdMinutes,
                'correlation_id'    => $this->correlationId,
            ]);
        }

        Log::info('transfer.transaction.timeout_scan.completed', [
            'orphans_found'  => $orphans->count(),
            'correlation_id' => $this->correlationId,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $this->retryHistory[] = [
            'attempt'   => $this->attempts(),
            'failed_at' => now()->toIso8601String(),
            'reason'    => $exception->getMessage(),
        ];

        Log::error('transfer.transaction.timeout_scan.failed', [
            'correlation_id' => $this->correlationId,
            'error'          => $exception->getMessage(),
        ]);
    }
}
