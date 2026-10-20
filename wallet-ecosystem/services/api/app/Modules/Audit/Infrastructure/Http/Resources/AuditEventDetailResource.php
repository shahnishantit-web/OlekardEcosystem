<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AuditEventDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'event_category'     => $this->event_category,
            'event_type'         => $this->event_type,
            'actor_id'           => $this->actor_id,
            'actor_type'         => $this->actor_type,
            'entity_type'        => $this->entity_type,
            'entity_id'          => $this->entity_id,
            'action'             => $this->action,
            'outcome'            => $this->outcome,
            'correlation_id'     => $this->correlation_id,
            'occurred_at'        => $this->occurred_at?->toIso8601String(),
            'before_state'       => $this->before_state,
            'after_state'        => $this->after_state,
            'metadata'           => $this->metadata,
            'integrity_verified' => $this->resource->verifyIntegrity(),
        ];
    }
}
