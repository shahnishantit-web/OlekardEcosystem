<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ComplianceHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->id,
            'user_id'                   => $this->user_id,
            'changed_at'                => $this->changed_at?->toIso8601String(),
            'field_changed'             => $this->field_changed,
            'before_value'              => $this->before_value,
            'after_value'               => $this->after_value,
            'source_system'             => $this->source_system,
            'triggering_audit_event_id' => $this->triggering_audit_event_id,
            'out_of_order'              => $this->out_of_order,
        ];
    }
}
