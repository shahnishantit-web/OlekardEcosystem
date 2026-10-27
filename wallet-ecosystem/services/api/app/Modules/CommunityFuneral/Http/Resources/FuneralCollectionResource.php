<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FuneralCollectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = (array) $this->resource;

        return [
            'id' => $data['id'] ?? null,
            'community_id' => $data['community_id'] ?? null,
            'status' => $data['status'] ?? 'under_review',
            'beneficiary_id' => $data['beneficiary_id'] ?? null,
            'evidence_reference' => $data['evidence_reference'] ?? null,
            'amount_minor' => (int) ($data['amount_minor'] ?? 2000),
            'currency_code' => $data['currency_code'] ?? 'BWP',
            'terms_version' => $data['terms_version'] ?? 'lc06-v1.1',
            'created_at' => $data['created_at'] ?? null,
        ];
    }
}
