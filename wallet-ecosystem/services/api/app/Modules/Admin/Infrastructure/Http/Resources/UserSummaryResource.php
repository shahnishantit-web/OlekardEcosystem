<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class UserSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_object($this->resource) ? (array) $this->resource : $this->resource;

        return [
            'id'         => $data['id'] ?? null,
            'email'      => $data['email'] ?? null,
            'status'     => $data['status'] ?? null,
            'kyc_tier'   => $data['kyc_tier'] ?? null,
            'created_at' => $data['created_at'] ?? null,
        ];
    }
}
