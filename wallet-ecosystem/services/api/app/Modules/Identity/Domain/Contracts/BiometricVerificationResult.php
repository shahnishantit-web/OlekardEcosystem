<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

readonly class BiometricVerificationResult
{
    public function __construct(
        public bool    $verified,
        public ?string $failureReason,
    ) {}
}
