<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Application\Adapters;

use App\Modules\Reconciliation\Domain\Adapters\SettlementAdapterInterface;
use App\Modules\Reconciliation\Domain\Models\SettlementBatch;

/**
 * Default no-op settlement adapter.
 * Returns a synthetic reference ID — no external call is made.
 * Replace via config('reconciliation.settlement_adapter') for production.
 */
final class NullSettlementAdapter implements SettlementAdapterInterface
{
    public function execute(SettlementBatch $batch): string
    {
        return 'NULL-ADAPTER-' . $batch->id;
    }
}
