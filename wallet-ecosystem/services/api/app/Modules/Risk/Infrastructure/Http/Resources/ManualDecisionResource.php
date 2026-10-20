<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class ManualDecisionResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request): array
    {
        return [
            'decision_id'    => $this->id,
            'evaluation_id'  => $this->resource->evaluation_id ?? null,
            'alert_id'       => $this->alert_id,
            'decision'       => $this->decision,
            'rationale'      => $this->rationale,
            'decided_by'     => $this->decided_by,
            'decided_at'     => $this->decided_at?->toIso8601String(),
        ];
    }
}
