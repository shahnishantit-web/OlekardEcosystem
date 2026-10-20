<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Application\Services;

use App\Modules\Reconciliation\Domain\Contracts\ReconciliationMetricsInterface;
use Illuminate\Support\Facades\Log;

final class ReconciliationMetricsService implements ReconciliationMetricsInterface
{
    public function incrementBatchProcessed(string $sourceType, string $status): void
    {
        Log::channel('stderr')->debug('metric', [
            'metric'      => 'reconciliation.batch.processed',
            'source_type' => $sourceType,
            'status'      => $status,
        ]);
    }

    public function incrementItemMatched(string $confidenceTier): void
    {
        Log::channel('stderr')->debug('metric', [
            'metric'               => 'reconciliation.item.matched',
            'match_confidence_tier' => $confidenceTier,
        ]);
    }

    public function incrementExceptionCreated(string $exceptionType): void
    {
        Log::channel('stderr')->debug('metric', [
            'metric'         => 'reconciliation.exception.created',
            'exception_type' => $exceptionType,
        ]);
    }

    public function incrementExceptionResolved(string $resolutionType): void
    {
        Log::channel('stderr')->debug('metric', [
            'metric'          => 'reconciliation.exception.resolved',
            'resolution_type' => $resolutionType,
        ]);
    }

    public function incrementSettlementAction(string $actionType): void
    {
        Log::channel('stderr')->debug('metric', [
            'metric'      => 'reconciliation.settlement.action',
            'action_type' => $actionType,
        ]);
    }

    public function recordSettlementAmount(string $currencyCode, string $amount): void
    {
        Log::channel('stderr')->debug('metric', [
            'metric'        => 'reconciliation.settlement.amount',
            'currency_code' => $currencyCode,
            'amount'        => $amount,
        ]);
    }
}
