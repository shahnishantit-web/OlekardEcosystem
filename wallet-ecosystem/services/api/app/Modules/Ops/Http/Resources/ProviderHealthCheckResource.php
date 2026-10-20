<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Resources;

use App\Modules\Ops\Infrastructure\Models\ProviderHealthCheckModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProviderHealthCheckModel */
final class ProviderHealthCheckResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'provider_type'    => $this->provider_type,
            'status'           => $this->status->value,
            'response_time_ms' => $this->response_time_ms,
            'error_message'    => $this->error_message,
            'checked_at'       => $this->checked_at?->toAtomString(),
        ];
    }
}
