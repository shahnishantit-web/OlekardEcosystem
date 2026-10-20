<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class AlertDetailResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'alert_number'   => $this->alert_number,
            'alert_type'     => $this->alert_type instanceof \BackedEnum ? $this->alert_type->value : $this->alert_type,
            'status'         => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'severity'       => $this->severity instanceof \BackedEnum ? $this->severity->value : $this->severity,
            'subject_type'   => $this->subject_type,
            'subject_id'     => $this->subject_id,
            'title'          => $this->title,
            'description'    => $this->description,
            'correlation_id' => $this->correlation_id,
            'assigned_to'    => $this->assigned_to,
            'sla_due_at'     => $this->sla_due_at?->toIso8601String(),
            'resolved_at'    => $this->resolved_at?->toIso8601String(),
            'version'        => $this->version,
            'created_at'     => $this->created_at?->toIso8601String(),
            'updated_at'     => $this->updated_at?->toIso8601String(),
            'decisions'      => $this->whenLoaded('decisions', fn () =>
                $this->decisions->map(fn ($d) => [
                    'id'         => $d->id,
                    'decision'   => $d->decision,
                    'rationale'  => $d->rationale,
                    'decided_by' => $d->decided_by,
                    'decided_at' => $d->decided_at?->toIso8601String(),
                ])
            ),
        ];
    }
}
