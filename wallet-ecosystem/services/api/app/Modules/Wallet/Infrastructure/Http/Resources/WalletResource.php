<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'wallet_reference' => $this->wallet_reference,
            'owner_id'         => $this->owner_id,
            'status'           => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'pockets'          => PocketResource::collection($this->whenLoaded('pockets')),
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
