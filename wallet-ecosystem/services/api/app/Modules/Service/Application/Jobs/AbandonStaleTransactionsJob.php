<?php

declare(strict_types=1);

namespace App\Modules\Service\Application\Jobs;

use App\Modules\Service\Domain\Enums\ServiceTransactionStatus;
use App\Modules\Service\Infrastructure\Models\ServiceTransactionModel;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class AbandonStaleTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ConfigurationServiceInterface $config): void
    {
        $timeoutMinutes = (int) $config->getOrDefault('service.payment.stale_initiated_timeout_minutes', 30);
        $cutoff         = now()->subMinutes($timeoutMinutes);

        $stale = ServiceTransactionModel::where('status', ServiceTransactionStatus::Initiated->value)
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($stale as $tx) {
            try {
                $tx->status = ServiceTransactionStatus::Abandoned;
                $tx->save();

                Log::info('Service transaction abandoned (stale initiated)', ['tx_id' => $tx->id]);
            } catch (\Throwable $e) {
                Log::error('Failed to abandon stale transaction', [
                    'tx_id' => $tx->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
