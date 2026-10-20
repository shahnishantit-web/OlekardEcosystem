<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\DTO;

final readonly class PaymentSubmissionResult
{
    public function __construct(
        public string  $providerReference,
        public string  $status,
        public ?string $providerMessage = null,
    ) {}
}
