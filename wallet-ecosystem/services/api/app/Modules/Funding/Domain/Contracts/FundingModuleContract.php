<?php

declare(strict_types=1);

namespace App\Modules\Funding\Domain\Contracts;

interface FundingModuleContract
{
    /**
     * Returns a summary array for the given funding transaction ID.
     * Returns: ['id', 'status', 'amount', 'currency_code', 'funding_method', 'completed_at']
     *
     * @throws \App\Modules\Funding\Domain\Exceptions\FundingTransactionNotFoundException
     */
    public function getFundingTransaction(string $fundingTransactionId): array;

    /**
     * Returns true if any funding transaction with the given transaction_reference
     * has status = completed. Used by downstream modules (E11, history).
     */
    public function isFundingCompleted(string $transactionReference): bool;
}
