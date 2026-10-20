<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Contracts;

interface ReconciliationModuleContract
{
    /**
     * @return array{id: string, status: string, matched_count: int, unmatched_count: int}|null
     */
    public function getBatchStatus(string $batchId): ?array;

    /**
     * @return array{id: string, status: string, net_amount: string, currency_code: string}|null
     */
    public function getSettlementStatus(string $settlementId): ?array;
}
