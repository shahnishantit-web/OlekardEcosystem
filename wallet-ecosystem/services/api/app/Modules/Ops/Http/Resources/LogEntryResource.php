<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Resources;

use App\Modules\Ops\Infrastructure\Models\LogEntryModel;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LogEntryModel */
final class LogEntryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'service_name'   => $this->service_name,
            'correlation_id' => $this->correlation_id,
            'severity'       => $this->severity?->value ?? $this->severity,
            'message'        => $this->message,
            'context'        => $this->context,
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
