<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Http\Resources;

use App\Modules\Commission\Infrastructure\Models\CommissionRuleModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CommissionRuleModel */
final class CommissionRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'rule_type'        => $this->rule_type->value,
            'rate'             => $this->rate,
            'applies_to'       => $this->applies_to->value,
            'agent_tier'       => $this->agent_tier,
            'volume_threshold' => $this->volume_threshold,
            'min_amount'       => $this->min_amount,
            'max_amount'       => $this->max_amount,
            'valid_from'       => $this->valid_from?->toIso8601String(),
            'valid_to'         => $this->valid_to?->toIso8601String(),
            'status'           => $this->status->value,
            'created_by'       => $this->created_by,
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
