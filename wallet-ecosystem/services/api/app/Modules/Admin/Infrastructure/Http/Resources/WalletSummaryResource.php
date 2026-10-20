<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WalletSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_object($this->resource) ? (array) $this->resource : $this->resource;

        return [
            'id'           => $data['id'] ?? null,
            'owner_id'     => $data['owner_actor_id'] ?? $data['owner_id'] ?? null,
            'owner_email'  => $data['owner_email'] ?? null,
            'status'       => $data['status'] ?? null,
            'currency'     => $data['currency'] ?? null,
            'created_at'   => $data['created_at'] ?? null,
        ];
    }
}
