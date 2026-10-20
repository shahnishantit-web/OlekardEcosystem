<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\DTO;

final readonly class SmsDeliveryResult
{
    public function __construct(
        public bool    $success,
        public ?string $providerRef = null,
        public ?string $errorCode = null,
    ) {}
}
