<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Application\Services;

use App\Modules\Reconciliation\Domain\Contracts\ReconciliationModuleContract;
use App\Modules\Reconciliation\Domain\Models\ReconciliationBatch;
use App\Modules\Reconciliation\Domain\Models\SettlementBatch;

final class ReconciliationQueryService implements ReconciliationModuleContract
{
    public function getBatchStatus(string $batchId): ?array
    {
        $batch = ReconciliationBatch::on('reconciliation')->find($batchId);

        if ($batch === null) {
            return null;
        }

        return [
            'id'              => (string) $batch->id,
            'status'          => (string) $batch->status->value,
            'matched_count'   => (int) $batch->matched_count,
            'unmatched_count' => (int) $batch->unmatched_count,
        ];
    }

    public function getSettlementStatus(string $settlementId): ?array
    {
        $settlement = SettlementBatch::on('reconciliation')->find($settlementId);

        if ($settlement === null) {
            return null;
        }

        return [
            'id'            => (string) $settlement->id,
            'status'        => (string) $settlement->status->value,
            'net_amount'    => (string) $settlement->net_amount,
            'currency_code' => (string) $settlement->currency_code,
        ];
    }
}
