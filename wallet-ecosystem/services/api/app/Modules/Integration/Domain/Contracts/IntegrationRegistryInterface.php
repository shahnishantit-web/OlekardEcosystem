<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Contracts;

use App\Modules\Integration\Domain\Enums\IntegrationProviderType;
use App\Modules\Integration\Infrastructure\Models\IntegrationModel;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderAdapterInterface;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderWebhookContract;

interface IntegrationRegistryInterface
{
    public function resolveAdapter(IntegrationProviderType $type): ?ProviderAdapterInterface;

    public function resolveWebhookContract(string $providerSlug): ?ProviderWebhookContract;

    public function resolveIntegration(IntegrationProviderType $type): ?IntegrationModel;

    public function resolveIntegrationBySlug(string $providerSlug): ?IntegrationModel;

    public function invalidate(?IntegrationProviderType $type = null): void;
}
