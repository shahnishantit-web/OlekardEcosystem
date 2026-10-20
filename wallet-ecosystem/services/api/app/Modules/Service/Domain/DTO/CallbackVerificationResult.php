<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\DTO;

final readonly class CallbackVerificationResult
{
    public function __construct(
        public bool   $verified,
        public string $providerReference,
        public string $transactionId,
        public string $status,
        public bool   $isTerminal,
    ) {}
}
