<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Resources;

use App\Modules\Ops\Infrastructure\Models\AlertRuleModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AlertRuleModel */
final class AlertRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'name'                  => $this->name,
            'description'           => $this->description,
            'metric_condition'      => $this->metric_condition,
            'severity'              => $this->severity->value,
            'notification_channels' => $this->notification_channels,
            'grouping_key'          => $this->grouping_key,
            'is_enabled'            => $this->is_enabled,
            'created_at'            => $this->created_at?->toAtomString(),
            'updated_at'            => $this->updated_at?->toAtomString(),
        ];
    }
}
