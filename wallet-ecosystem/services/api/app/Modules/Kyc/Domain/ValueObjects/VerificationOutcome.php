<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\ValueObjects;

use App\Modules\Kyc\Domain\Enums\KycVerificationOutcome;

final readonly class VerificationOutcome
{
    public function __construct(
        public KycVerificationOutcome $outcome,
        public string $providerReference,
        public array $rawResponse,
        public ?float $confidenceScore = null,
    ) {}
}
