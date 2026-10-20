<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class CustomerWalletViewResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'wallet_id'          => $this->resource['wallet_id'],
            'status'             => $this->resource['status'],
            'tier'               => $this->resource['tier'],
            'is_frozen'          => $this->resource['is_frozen'],
            'balance_disclosure' => null,
        ];
    }
}
