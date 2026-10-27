<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\ValueObjects\PaymentRefundRequest;
use App\Modules\Payment\Domain\ValueObjects\PaymentRefundResult;
use App\Modules\Payment\Domain\ValueObjects\RefundStatusResult;

interface RefundsPayment
{
    public function initiateRefund(PaymentRefundRequest $request): PaymentRefundResult;

    /**
     * T046: Query the current status of a submitted refund from the provider.
     *
     * Called by QueryRefundStatusJob for async bank refund tracking.
     * Returns a RefundStatusResult with the provider's current view of the refund.
     */
    public function queryRefundStatus(string $refundId, string $providerSubmittedRef): RefundStatusResult;
}
