<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application\DTOs;

final readonly class UpdateIntegrationData
{
    public function __construct(
        public ?string $displayName = null,
        public ?string $credentialsReference = null,
        public ?string $endpointOverride = null,
        public ?array $configuration = null,
    ) {}
}
