<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

use RuntimeException;

final class RefundEligibilityDeniedException extends RuntimeException
{
    public function __construct(
        private readonly string $paymentIntentId,
        private readonly string $reason,
        private readonly string $purposeType,
    ) {
        parent::__construct(
            "Refund eligibility denied for intent {$paymentIntentId} (purpose={$purposeType}): {$reason}"
        );
    }

    public function paymentIntentId(): string
    {
        return $this->paymentIntentId;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function purposeType(): string
    {
        return $this->purposeType;
    }
}
