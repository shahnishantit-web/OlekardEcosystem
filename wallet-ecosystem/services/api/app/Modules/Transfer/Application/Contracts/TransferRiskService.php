<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Application\Contracts;

interface TransferRiskService
{
    /**
     * Evaluate transfer risk and return an approved or rejected result.
     *
     * @param array{
     *   sender_customer_id: string,
     *   sender_wallet_id: string,
     *   recipient_customer_id: string,
     *   recipient_wallet_id: string,
     *   currency_code: string,
     *   gross_amount: int,
     *   correlation_id: string,
     * } $context
     */
    public function evaluate(array $context): RiskResult;
}
