<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Contracts;

interface ReconciliationMetricsInterface
{
    public function incrementBatchProcessed(string $sourceType, string $status): void;

    public function incrementItemMatched(string $confidenceTier): void;

    public function incrementExceptionCreated(string $exceptionType): void;

    public function incrementExceptionResolved(string $resolutionType): void;

    public function incrementSettlementAction(string $actionType): void;

    public function recordSettlementAmount(string $currencyCode, string $amount): void;
}
