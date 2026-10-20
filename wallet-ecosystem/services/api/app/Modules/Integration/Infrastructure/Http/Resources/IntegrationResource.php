<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Resources;

use App\Modules\Integration\Infrastructure\Models\IntegrationModel;
use Illuminate\Http\Resources\Json\JsonResource;

final class IntegrationResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var IntegrationModel $integration */
        $integration = $this->resource;

        return [
            'id'                     => $integration->id,
            'provider_type'          => $integration->provider_type->value,
            'provider_slug'          => $integration->provider_slug,
            'display_name'           => $integration->display_name,
            'credentials_reference'  => $integration->credentials_reference,
            'endpoint_override'      => $integration->endpoint_override,
            'status'                 => $integration->status->value,
            'is_default'             => $integration->is_default,
            'configuration'          => $integration->configuration,
            'created_at'             => $integration->created_at?->toIso8601String(),
            'updated_at'             => $integration->updated_at?->toIso8601String(),
        ];
    }
}
