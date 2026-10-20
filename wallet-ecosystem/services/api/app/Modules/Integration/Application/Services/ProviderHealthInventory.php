<?php

declare(strict_types=1);

namespace App\Modules\Integration\Application\Services;

use App\Modules\Integration\Domain\Contracts\ProviderHealthInventoryInterface;
use App\Modules\Integration\Infrastructure\Models\IntegrationModel;

final class ProviderHealthInventory implements ProviderHealthInventoryInterface
{
    public function targets(): array
    {
        $targets = [];
        foreach (IntegrationModel::where('status', 'active')->orderBy('id')->get() as $integration) {
            $abstract = 'integration.adapter.'.$integration->provider_slug;
            try {
                $adapter = app()->bound($abstract) ? app($abstract) : null;
            } catch (\Throwable) {
                $adapter = null;
            }
            $targets[] = ['id' => $integration->id, 'type' => $integration->provider_type->value, 'is_default' => $integration->is_default, 'adapter' => $adapter];
        }

return $targets;
    }
}
