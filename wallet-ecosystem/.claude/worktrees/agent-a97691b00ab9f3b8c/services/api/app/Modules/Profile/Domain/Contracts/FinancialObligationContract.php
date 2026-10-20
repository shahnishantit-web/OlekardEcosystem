<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Contracts;

interface FinancialObligationContract
{
    /**
     * Check whether a user has any unresolved financial obligations.
     * Called before any account closure operation (self-service and admin).
     *
     * Obligation categories: wallet_balance, pending_transactions, active_loan, open_dispute
     */
    public function checkObligations(string $userId): ObligationCheckResult;
}
