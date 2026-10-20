<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class UserDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'id'           => $data['id'] ?? null,
            'email'        => $data['email'] ?? null,
            'status'       => $data['status'] ?? null,
            'created_at'   => $data['created_at'] ?? null,
            'profile'      => $data['profile'] ?? null,
            'kyc'          => $data['kyc'] ?? null,
            'wallet_count' => $data['wallet_count'] ?? 0,
        ];
    }
}
