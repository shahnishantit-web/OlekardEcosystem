<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\DTO;

final readonly class ReferenceValidationResult
{
    public function __construct(
        public bool   $valid,
        public string $accountName,
        public ?string $providerMessage = null,
    ) {}
}
