<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ComplianceStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_id'             => $this->user_id,
            'kyc_tier'            => $this->kyc_tier,
            'aml_risk_level'      => $this->aml_risk_level,
            'active_restrictions' => $this->active_restrictions ?? [],
            'last_changed_at'     => $this->last_changed_at?->toIso8601String(),
        ];
    }
}
