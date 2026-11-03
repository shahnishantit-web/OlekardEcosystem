<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Contracts\PaymentRefundAdmissionEligibilityContract;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use Illuminate\Support\Facades\DB;

/** Reads Payment-owned refund states without exposing refund data to Events. */
final class PaymentRefundAdmissionEligibilityService implements PaymentRefundAdmissionEligibilityContract
{
    public function hasAdmissionBlockingRefund(string $paymentIntentId): bool
    {
        $refunds = DB::connection('payment')->table('payment.payment_refunds')
            ->where('payment_intent_id', $paymentIntentId)->get(['status']);
        foreach ($refunds as $refund) {
            $status = PaymentRefundStatus::tryFrom((string) $refund->status);
            if ($status === null || $status->reservesFunds() || $status->fundsReturned()) {
                return true;
            }
        }

        return false;
    }
}
