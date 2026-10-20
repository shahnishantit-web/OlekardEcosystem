<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LedgerAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->id,
            'ledger_account_reference'  => $this->ledger_account_reference,
            'account_type'              => $this->account_type instanceof \BackedEnum ? $this->account_type->value : $this->account_type,
            'normal_balance'            => $this->normal_balance instanceof \BackedEnum ? $this->normal_balance->value : $this->normal_balance,
            'currency_code'             => $this->currency_code,
            'owner_reference'           => $this->owner_reference,
            'owner_type'                => $this->owner_type,
            'status'                    => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'created_at'                => $this->created_at?->toIso8601String(),
        ];
    }
}
