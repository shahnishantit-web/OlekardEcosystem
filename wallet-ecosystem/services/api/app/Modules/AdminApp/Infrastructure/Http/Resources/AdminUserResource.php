<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class AdminUserResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return self::format($this->resource, masked: true, walletCount: 0);
    }

    /**
     * @param  object  $user
     * @return array<string, mixed>
     */
    public static function format(object $user, bool $masked, int $walletCount): array
    {
        $phone = $user->phone_number ?? null;
        if ($masked && $phone !== null) {
            $phone = preg_replace('/(\+\d{3})\s?\d+\s?\d+\s?(\d{3})/', '$1 *** *** $2', $phone);
        }

        return [
            'id'             => $user->id,
            'user_id'        => $user->id,
            'full_name'      => $user->full_name,
            'phone_number'   => $phone,
            'email'          => $user->email ?? null,
            'status'         => $user->status,
            'account_status' => $user->status,
            'actor_type'     => $user->actor_type ?? 'CUSTOMER',
            'kyc_status'     => $user->kyc_status ?? 'not_started',
            'kyc_tier'       => $user->kyc_tier ?? null,
            'created_at'     => $user->created_at ?? null,
            'registered_at'  => isset($user->created_at) ? date('c', strtotime($user->created_at)) : null,
            'last_active_at' => isset($user->last_active_at) ? date('c', strtotime($user->last_active_at)) : null,
            'wallet_count'   => $walletCount,
        ];
    }
}
