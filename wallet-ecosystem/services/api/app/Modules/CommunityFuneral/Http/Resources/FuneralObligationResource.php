<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FuneralObligationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = (array) $this->resource;

        return [
            'id' => $data['id'] ?? null,
            'collection_id' => $data['collection_id'] ?? null,
            'organisation_id' => $data['organisation_id'] ?? null,
            'organisation_name' => $data['organisation_name'] ?? 'Organisation',
            'community_name' => $data['community_name'] ?? 'Community',
            'collection_purpose' => $data['collection_purpose'] ?? 'Community funeral assistance contribution',
            'amount_minor' => (int) ($data['amount_minor'] ?? 2000),
            'currency_code' => $data['currency_code'] ?? 'BWP',
            'status' => $data['status'] ?? 'unpaid',
            'fulfilment_status' => $data['fulfilment_status'] ?? 'pending',
            'collection_status' => $data['collection_status'] ?? null,
        ];
    }
}
