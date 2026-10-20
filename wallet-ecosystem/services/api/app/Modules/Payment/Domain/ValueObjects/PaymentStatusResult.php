<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;

final readonly class PaymentStatusResult
{
    public function __construct(
        public PaymentAttemptStatus $status,
        public ?string              $providerReferenceId = null,
        public ?string              $failureReason = null,
        public ?int                 $confirmedAmountMinor = null,
    ) {
    }
}
