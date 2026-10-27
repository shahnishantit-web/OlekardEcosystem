<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Payment\Application\Services\ReconciliationExceptionService;
use App\Modules\Payment\Domain\Enums\ReconciliationExceptionType;
use App\Modules\Payment\Domain\Events\AgeingExceptionRaisedEvent;
use App\Modules\Payment\Infrastructure\Models\ClearingRecordModel;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SettlementAgeingCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(
        ConfigurationServiceInterface  $config,
        ReconciliationExceptionService $exceptionService,
        OutboxEventPublisher           $outbox,
    ): void {
        $thresholdDays = (int) ($config->getOrDefault(
            'payment.reconciliation.settlement_ageing_days',
            7
        ) ?? 7);

        $staleRecords = ClearingRecordModel::query()
            ->whereNull('bank_settled_at')
            ->whereNull('aged_at')
            ->where('cleared_at', '<', now()->subDays($thresholdDays))
            ->get();

        $count = 0;
        foreach ($staleRecords as $record) {
            $exceptionService->create(ReconciliationExceptionType::PartialSettlement, [
                'provider_code'   => $record->provider_code,
                'currency_code'   => $record->currency_code,
                'description'     => "Clearing record has not received bank settlement within {$thresholdDays} days",
                'owner'           => 'finance_ops',
            ]);

            ClearingRecordModel::query()->where('id', $record->id)->update([
                'aged_at'    => now(),
                'updated_at' => now(),
            ]);

            DB::connection('platform')->transaction(function () use ($record, $outbox, $thresholdDays): void {
                $outbox->publish(new AgeingExceptionRaisedEvent(
                    clearingRecordId: $record->id,
                    providerCode:     $record->provider_code,
                    currencyCode:     $record->currency_code,
                    thresholdDays:    $thresholdDays,
                    occurredAt:       now()->toIso8601String(),
                ));
            });

            $count++;
        }

        Log::info('settlement.ageing_check.completed', [
            'stale_records_found' => $staleRecords->count(),
            'exceptions_raised'   => $count,
            'threshold_days'      => $thresholdDays,
        ]);
    }
}
