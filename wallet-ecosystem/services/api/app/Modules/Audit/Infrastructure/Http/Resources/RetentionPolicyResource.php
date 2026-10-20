<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RetentionPolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                              => $this->id,
            'data_category'                   => $this->data_category,
            'minimum_retention_days'          => $this->minimum_retention_days,
            'previous_minimum_retention_days' => $this->previous_minimum_retention_days,
            'post_retention_action'           => $this->post_retention_action,
            'effective_from'                  => $this->effective_from?->toDateString(),
            'enforcement_hold_until'          => $this->enforcement_hold_until?->toDateString(),
            'configured_by_actor_id'          => $this->configured_by_actor_id,
            'created_at'                      => $this->created_at?->toIso8601String(),
            'updated_at'                      => $this->updated_at?->toIso8601String(),
        ];
    }
}
