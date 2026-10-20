<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use Carbon\Carbon;

interface OrphanDetectableInterface
{
    /**
     * Returns IDs of transactions stuck past the given threshold.
     *
     * @return string[]
     */
    public function findOrphanedTransactionIds(Carbon $threshold): array;

    /**
     * Attempts recovery of a single orphaned transaction.
     *
     * @return 'recovered'|'reversed'|'skipped'
     */
    public function recoverOrphanedTransaction(string $transactionId, string $correlationId): string;
}
