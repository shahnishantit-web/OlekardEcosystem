<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Providers;

use App\Modules\Integration\Domain\Contracts\IntegrationRegistryInterface;
use App\Modules\Integration\Domain\Contracts\IntegrationRepositoryInterface;
use App\Modules\Integration\Domain\Enums\IntegrationProviderType;
use App\Modules\Integration\Infrastructure\Models\IntegrationModel;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderAdapterInterface;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderWebhookContract;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;

final class IntegrationRegistry implements IntegrationRegistryInterface
{
    private const CACHE_TTL = 60;
    private const CACHE_KEY_PREFIX = 'integration_registry:';

    public function __construct(
        private readonly IntegrationRepositoryInterface $repository,
        private readonly Container $container,
        private readonly CacheRepository $cache,
    ) {}

    public function resolveAdapter(IntegrationProviderType $type): ?ProviderAdapterInterface
    {
        $integration = $this->resolveIntegration($type);

        if ($integration === null) {
            return null;
        }

        $abstract = 'integration.adapter.' . $integration->provider_slug;

        if (!$this->container->bound($abstract)) {
            return null;
        }

        return $this->container->make($abstract);
    }

    public function resolveWebhookContract(string $providerSlug): ?ProviderWebhookContract
    {
        $abstract = 'integration.webhook.' . $providerSlug;

        if (!$this->container->bound($abstract)) {
            return null;
        }

        return $this->container->make($abstract);
    }

    public function resolveIntegration(IntegrationProviderType $type): ?IntegrationModel
    {
        $key = self::CACHE_KEY_PREFIX . 'type:' . $type->value;

        return $this->cache->remember($key, self::CACHE_TTL, function () use ($type): ?IntegrationModel {
            return $this->repository->findDefault($type);
        });
    }

    public function resolveIntegrationBySlug(string $providerSlug): ?IntegrationModel
    {
        $key = self::CACHE_KEY_PREFIX . 'slug:' . $providerSlug;

        return $this->cache->remember($key, self::CACHE_TTL, function () use ($providerSlug): ?IntegrationModel {
            return \App\Modules\Integration\Infrastructure\Models\IntegrationModel::where('provider_slug', $providerSlug)
                ->where('status', \App\Modules\Integration\Domain\Enums\IntegrationStatus::Active->value)
                ->first();
        });
    }

    public function invalidate(?IntegrationProviderType $type = null): void
    {
        if ($type !== null) {
            $this->cache->forget(self::CACHE_KEY_PREFIX . 'type:' . $type->value);
            return;
        }

        // Flush all integration registry cache entries.
        foreach (IntegrationProviderType::cases() as $providerType) {
            $this->cache->forget(self::CACHE_KEY_PREFIX . 'type:' . $providerType->value);
        }
    }
}
