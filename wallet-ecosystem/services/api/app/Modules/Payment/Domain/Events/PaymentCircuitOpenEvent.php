<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

final readonly class PaymentCircuitOpenEvent
{
    public function __construct(
        public string $providerCode,
        public int    $consecutiveFailures,
        public int    $resetMinutes,
        public string $correlationId,
    ) {
    }
}
