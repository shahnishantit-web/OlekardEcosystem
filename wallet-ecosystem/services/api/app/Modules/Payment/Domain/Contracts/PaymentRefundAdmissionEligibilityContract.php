<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

/** Payment-owned point-in-time refund eligibility read for online Events admission. */
interface PaymentRefundAdmissionEligibilityContract
{
    /** Fail closed when a refund state is active, unknown or has returned funds. */
    public function hasAdmissionBlockingRefund(string $paymentIntentId): bool;
}
