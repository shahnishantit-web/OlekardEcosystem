<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class ReportJobSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'report_type'    => $this->report_type,
            'status'         => $this->status->value,
            'row_count'      => $this->row_count,
            'failure_reason' => $this->failure_reason,
            'as_of_at'       => $this->as_of_at?->toIso8601String(),
            'created_at'     => $this->created_at?->toIso8601String(),
            'started_at'     => $this->started_at?->toIso8601String(),
            'completed_at'   => $this->completed_at?->toIso8601String(),
        ];
    }
}
