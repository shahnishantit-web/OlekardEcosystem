<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'category'        => $this->category,
            'description'     => $this->description,
            'provider_code'   => $this->provider_code,
            'status'          => $this->status->value,
            'currency_code'   => $this->currency_code,
            'min_amount'      => $this->min_amount,
            'max_amount'      => $this->max_amount,
            'fee_type'        => $this->fee_type->value,
            'fee_value'       => $this->fee_value,
            'required_fields' => $this->required_fields ?? [],
            'metadata'        => $this->metadata,
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }
}
