<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FuneralCommunityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = (array) $this->resource;

        return [
            'id' => $data['id'] ?? null,
            'organisation_id' => $data['organisation_id'] ?? null,
            'display_name' => $data['display_name'] ?? null,
            'status' => $data['status'] ?? 'active',
            'policy_version' => $data['policy_version'] ?? 'lc06-v1.1',
            'created_at' => $data['created_at'] ?? null,
        ];
    }
}
