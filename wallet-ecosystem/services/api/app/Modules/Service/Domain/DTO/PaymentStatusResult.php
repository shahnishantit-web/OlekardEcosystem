<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\DTO;

final readonly class PaymentStatusResult
{
    public function __construct(
        public string  $providerReference,
        public string  $status,
        public bool    $isTerminal,
        public ?string $providerMessage = null,
    ) {}
}
