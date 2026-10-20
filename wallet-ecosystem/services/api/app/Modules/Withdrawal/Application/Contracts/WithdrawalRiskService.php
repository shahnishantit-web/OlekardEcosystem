<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Application\Contracts;

interface WithdrawalRiskService
{
    /**
     * Evaluate withdrawal risk. Context keys: customer_id, wallet_id, channel,
     * gross_amount, currency_code, correlation_id. No payout_destination PII.
     */
    public function evaluate(array $context): WithdrawalRiskResult;
}
