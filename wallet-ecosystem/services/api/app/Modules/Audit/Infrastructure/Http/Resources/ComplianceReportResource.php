<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ComplianceReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'report_type'           => $this->report_type,
            'status'                => $this->status,
            'requested_by_actor_id' => $this->requested_by_actor_id,
            'date_range_start'      => $this->date_range_start,
            'date_range_end'        => $this->date_range_end,
            'filters'               => $this->filters,
            'idempotency_key'       => $this->idempotency_key,
            'report_data'           => $this->report_data,
            'generated_at'          => $this->generated_at?->toIso8601String(),
            'expires_at'            => $this->expires_at?->toIso8601String(),
            'created_at'            => $this->created_at?->toIso8601String(),
        ];
    }
}
