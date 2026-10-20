<?php

declare(strict_types=1);

namespace App\Modules\Service\Application\Jobs;

use App\Modules\Platform\Concerns\HasCorrelationId;
use App\Modules\Service\Domain\Enums\ServiceTransactionStatus;
use App\Modules\Service\Infrastructure\Models\ServiceTransactionModel;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * Transitions pending_provider transactions to timeout_pending_review
 * when no callback has arrived within provider_timeout_seconds.
 * This feeds the ReQueryTimeoutTransactionJob which then re-queries the provider.
 */
final class TransitionTimedOutPaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use HasCorrelationId;

    public function __construct(string $correlationId = '')
    {
        $this->correlationId = $correlationId ?: (string) Uuid::uuid7();
    }

    public function handle(ConfigurationServiceInterface $config): void
    {
        $this->injectCorrelationContext();

        $timeoutSeconds = (int) $config->getOrDefault('service.payment.provider_timeout_seconds', 30);
        $cutoff         = now()->subSeconds($timeoutSeconds);

        $timedOut = ServiceTransactionModel::where('status', ServiceTransactionStatus::PendingProvider->value)
            ->where('updated_at', '<', $cutoff)
            ->get();

        foreach ($timedOut as $tx) {
            try {
                $tx->status = ServiceTransactionStatus::TimeoutPendingReview;
                $tx->save();

                Log::warning('service.payment.timeout_pending_review', [
                    'tx_id'          => $tx->id,
                    'service_id'     => $tx->service_id,
                    'customer_id'    => $tx->customer_id,
                    'pending_since'  => $tx->updated_at,
                    'timeout_secs'   => $timeoutSeconds,
                ]);
            } catch (\Throwable $e) {
                Log::error('TransitionTimedOutPaymentsJob: failed to transition transaction', [
                    'tx_id' => $tx->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
