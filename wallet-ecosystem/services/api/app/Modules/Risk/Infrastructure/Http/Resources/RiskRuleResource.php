<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class RiskRuleResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'description'       => $this->description,
            'rule_type'         => $this->rule_type instanceof \BackedEnum ? $this->rule_type->value : $this->rule_type,
            'applies_to'        => $this->applies_to,
            'subject_id'        => $this->subject_id,
            'threshold_amount'  => $this->threshold_amount,
            'threshold_count'   => $this->threshold_count,
            'window_seconds'    => $this->window_seconds,
            'risk_score_weight' => $this->risk_score_weight,
            'action'            => $this->action instanceof \BackedEnum ? $this->action->value : $this->action,
            'is_active'         => $this->is_active,
            'effective_from'    => $this->effective_from?->toIso8601String(),
            'effective_until'   => $this->effective_until?->toIso8601String(),
        ];
    }
}
