<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class AdminWalletResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return self::format($this->resource);
    }

    /** @return array<string, mixed> */
    public static function format(object $wallet): array
    {
        return [
            'id'                   => $wallet->wallet_id ?? $wallet->id,
            'user_id'              => $wallet->owner_user_id ?? $wallet->owner_id ?? null,
            'wallet_id'            => $wallet->wallet_id ?? $wallet->id,
            'wallet_number'        => $wallet->wallet_number,
            'owner_user_id'        => $wallet->owner_user_id,
            'owner_name'           => $wallet->owner_name ?? '',
            'wallet_type'          => $wallet->wallet_type,
            'status'               => $wallet->status,
            'balance_minor_units'  => (int) ($wallet->balance_minor_units ?? 0),
            'currency_code'        => $wallet->currency_code ?? 'BWP',
            'currency'             => $wallet->currency_code ?? 'BWP',
            'created_at'           => $wallet->created_at,
            'frozen_at'            => $wallet->frozen_at ?? null,
            'frozen_by'            => $wallet->frozen_by ?? null,
            'freeze_reason'        => $wallet->freeze_reason ?? null,
        ];
    }
}
