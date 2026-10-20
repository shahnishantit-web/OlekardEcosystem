<?php

declare(strict_types=1);

namespace App\Modules\Service\Application\Jobs;

use App\Modules\Platform\Concerns\HasCorrelationId;
use App\Modules\Service\Application\Services\ServiceOutcomeService;
use App\Modules\Service\Domain\Contracts\ServiceProviderAdapter;
use App\Modules\Service\Domain\Enums\ServiceTransactionStatus;
use App\Modules\Service\Infrastructure\Models\ServiceTransactionModel;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class ReQueryTimeoutTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use HasCorrelationId;

    public function __construct(string $correlationId = '')
    {
        $this->correlationId = $correlationId ?: (string) Uuid::uuid7();
    }

    public function handle(
        ServiceProviderAdapter      $adapter,
        ServiceOutcomeService       $outcome,
        ConfigurationServiceInterface $config,
    ): void {
        $this->injectCorrelationContext();

        $maxAttempts = (int) $config->getOrDefault('service.payment.requery_max_attempts', 12);

        $transactions = ServiceTransactionModel::where('status', ServiceTransactionStatus::TimeoutPendingReview->value)
            ->where('requery_count', '<', $maxAttempts)
            ->get();

        foreach ($transactions as $tx) {
            try {
                $result = $adapter->queryStatus($tx->service?->provider_code ?? '', $tx->provider_reference ?? '');

                $tx->requery_count++;
                $tx->save();

                $correlationId = 'requery:'.Str::uuid();

                match ($result->status) {
                    'success', 'completed' => $outcome->handleSuccess($tx->id, $result->providerReference, $correlationId),
                    'failure', 'failed'    => $outcome->handleFailure($tx->id, 'provider_timeout_requery', $correlationId),
                    default                => null,
                };
            } catch (\Throwable $e) {
                Log::error('ReQuery job failed for transaction', [
                    'tx_id' => $tx->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
