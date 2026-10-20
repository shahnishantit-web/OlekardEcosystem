<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Resources;

use App\Modules\Integration\Infrastructure\Models\WebhookEventModel;
use Illuminate\Http\Resources\Json\JsonResource;

final class WebhookEventResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var WebhookEventModel $event */
        $event = $this->resource;

        $data = [
            'id'                   => $event->id,
            'provider_type'        => $event->provider_type,
            'provider_slug'        => $event->provider_slug,
            'idempotency_key'      => $event->idempotency_key,
            'event_type'           => $event->event_type,
            'status'               => $event->status->value,
            'retry_count'          => $event->retry_count,
            'max_retry_count'      => $event->max_retry_count,
            'last_error'           => $event->last_error,
            'platform_entity_id'   => $event->platform_entity_id,
            'platform_entity_type' => $event->platform_entity_type,
            'received_at'          => $event->received_at?->toIso8601String(),
            'processed_at'         => $event->processed_at?->toIso8601String(),
        ];

        if ($event->relationLoaded('processingAttempts')) {
            $data['processing_attempts'] = $event->processingAttempts->map(fn ($a) => [
                'id'             => $a->id,
                'attempt_number' => $a->attempt_number,
                'outcome'        => $a->outcome,
                'error_message'  => $a->error_message,
                'attempted_at'   => $a->attempted_at?->toIso8601String(),
            ])->values()->all();
        }

        return $data;
    }
}
