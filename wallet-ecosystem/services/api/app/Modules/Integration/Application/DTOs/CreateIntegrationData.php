<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application\DTOs;

use App\Modules\Integration\Domain\Enums\IntegrationProviderType;

final readonly class CreateIntegrationData
{
    public function __construct(
        public IntegrationProviderType $providerType,
        public string $providerSlug,
        public string $displayName,
        public string $credentialsReference,
        public bool $isDefault = false,
        public ?string $endpointOverride = null,
        public ?array $configuration = null,
    ) {}
}
