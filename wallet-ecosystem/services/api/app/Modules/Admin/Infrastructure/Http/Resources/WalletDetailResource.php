<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WalletDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'id'           => $data['id'] ?? null,
            'owner_id'     => $data['owner_id'] ?? null,
            'owner_email'  => $data['owner_email'] ?? null,
            'status'       => $data['status'] ?? null,
            'currency'     => $data['currency'] ?? null,
            'created_at'   => $data['created_at'] ?? null,
            'pockets'      => $data['pockets'] ?? [],
            'account_hold' => $data['account_hold'] ?? null,
        ];
    }
}
