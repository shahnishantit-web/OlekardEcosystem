<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Http\Resources;

use App\Modules\Ledger\Infrastructure\Models\LedgerAccountModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class JournalLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var LedgerAccountModel|null $account */
        $account = LedgerAccountModel::find($this->ledger_account_id);

        return [
            'id'                        => $this->id,
            'direction'                 => $this->direction instanceof \BackedEnum ? $this->direction->value : $this->direction,
            'amount'                    => $this->amount,
            'currency_code'             => $this->currency_code,
            'ledger_account_reference'  => $account?->ledger_account_reference,
            'created_at'                => $this->created_at?->toIso8601String(),
        ];
    }
}
