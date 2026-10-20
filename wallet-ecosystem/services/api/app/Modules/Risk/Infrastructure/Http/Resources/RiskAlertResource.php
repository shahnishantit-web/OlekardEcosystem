<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class RiskAlertResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'alert_number'   => $this->alert_number,
            'alert_type'     => $this->alert_type instanceof \BackedEnum
                                    ? $this->alert_type->value
                                    : $this->alert_type,
            'status'         => $this->status instanceof \BackedEnum
                                    ? $this->status->value
                                    : $this->status,
            'severity'       => $this->severity instanceof \BackedEnum
                                    ? $this->severity->value
                                    : $this->severity,
            'subject_type'   => $this->subject_type,
            'subject_id'     => $this->subject_id,
            'title'          => $this->title,
            'description'    => $this->description,
            'correlation_id' => $this->correlation_id,
            'sla_due_at'     => $this->sla_due_at?->toIso8601String(),
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
