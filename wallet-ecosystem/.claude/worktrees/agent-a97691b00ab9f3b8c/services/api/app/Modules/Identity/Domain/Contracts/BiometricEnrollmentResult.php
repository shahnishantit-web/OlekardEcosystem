<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

readonly class BiometricEnrollmentResult
{
    public function __construct(
        public string $devicePublicKey,
        public string $deviceIdentifier,
    ) {}
}
