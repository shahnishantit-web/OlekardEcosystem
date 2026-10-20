<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

readonly class OtpDeliveryResult
{
    public function __construct(
        public bool    $delivered,
        public string  $providerReference,
        public ?string $failureReason,
    ) {}
}
