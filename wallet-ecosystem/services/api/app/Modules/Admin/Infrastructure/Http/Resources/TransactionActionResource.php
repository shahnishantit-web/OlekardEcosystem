<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TransactionActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_object($this->resource) ? (array) $this->resource : $this->resource;

        return [
            'action_id'      => $data['id'] ?? null,
            'reference'      => $data['reference'] ?? null,
            'type'           => $data['type'] ?? $data['action_type'] ?? null,
            'status'         => $data['status'] ?? null,
            'amount'         => $data['amount'] ?? null,
            'currency_code'  => $data['currency_code'] ?? null,
            'created_at'     => $data['created_at'] ?? null,
        ];
    }
}
