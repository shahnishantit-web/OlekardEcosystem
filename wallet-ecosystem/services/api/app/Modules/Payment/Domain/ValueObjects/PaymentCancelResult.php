<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

final readonly class PaymentCancelResult
{
    public function __construct(
        public bool    $cancelled,
        public ?string $failureReason = null,
    ) {
    }
}
