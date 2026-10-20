<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Domain\Contracts;

interface TransactionModuleContract
{
    /**
     * Generate a platform-unique transaction reference string
     * (format defined by E11; guaranteed to be stable and sortable).
     */
    public function generateReference(): string;

    /**
     * Returns the current lifecycle status of the given transaction reference.
     * Returns null if the reference is not found.
     */
    public function status(string $transactionReference): ?string;
}
