<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class BalanceResource extends JsonResource
{
    /**
     * @param array<string, mixed> $resource Balance array from WalletBalanceService::getBalance()
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'wallet_id'         => $data['wallet_id'],
            'currency_code'     => $data['currency_code'],
            'precision'         => $data['precision'],
            'ledger_balance'    => $data['ledger_balance'],
            'held_amount'       => $data['held_amount'],
            'available_balance' => $data['available_balance'],
            'pending_balance'   => $data['pending_balance'] ?? 0,
            'wallet_status'     => $data['wallet_status'],
            'as_of_timestamp'   => $data['as_of_timestamp'],
            'holds'             => $data['holds'] ?? [],
            'meta'              => $data['meta'] ?? null,
        ];
    }
}
