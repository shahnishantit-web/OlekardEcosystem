<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Adapters;

use App\Modules\Reconciliation\Domain\Models\SettlementBatch;

interface SettlementAdapterInterface
{
    /**
     * Execute a settlement payment via the external provider.
     *
     * @return string External reference ID returned by provider.
     */
    public function execute(SettlementBatch $batch): string;
}
