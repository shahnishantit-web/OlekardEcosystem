<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Resources;

use App\Modules\Ops\Infrastructure\Models\ProviderStatusModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProviderStatusModel */
final class ProviderStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'provider_type'        => $this->provider_type,
            'status'               => $this->status->value,
            'consecutive_failures' => $this->consecutive_failures,
            'last_checked_at'      => $this->last_checked_at?->toAtomString(),
            'degraded_since'       => $this->degraded_since?->toAtomString(),
            'down_since'           => $this->down_since?->toAtomString(),
            'updated_at'           => $this->updated_at?->toAtomString(),
        ];
    }
}
