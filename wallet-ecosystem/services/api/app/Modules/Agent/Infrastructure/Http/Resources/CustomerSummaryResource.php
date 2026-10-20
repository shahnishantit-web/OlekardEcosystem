<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class CustomerSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        $nationalId = $this->resource->national_id_number ?? '';
        $masked = $nationalId !== ''
            ? '****' . substr($nationalId, -4)
            : null;

        return [
            'id'                 => $this->resource->id,
            'first_name'         => $this->resource->first_name,
            'last_name'          => $this->resource->last_name,
            'masked_national_id' => $masked,
            'phone'              => $this->resource->phone ?? $this->resource->mobile_number,
            'status'             => $this->resource->status ?? $this->resource->customer_status,
        ];
    }
}
