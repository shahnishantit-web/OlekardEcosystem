<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;

final readonly class PaymentRefundResult
{
    public function __construct(
        public PaymentRefundStatus $status,
        public ?string             $providerRefundReference = null,
        public ?string             $failureReason = null,
    ) {
    }
}
