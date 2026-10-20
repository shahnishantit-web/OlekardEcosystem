<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Http\Resources;

use App\Modules\Transaction\Infrastructure\Models\TransactionProjectionModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TransactionProjectionModel */
final class TransactionProjectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'reference'             => $this->reference,
            'type'                  => $this->type->value,
            'status'                => $this->status,
            'amount'                => $this->amount,
            'currency_code'         => $this->currency_code,
            'direction'             => $this->direction->value,
            'wallet_id'             => $this->wallet_id,
            'customer_id'           => $this->customer_id,
            'description'           => $this->description,
            'source_module'         => $this->source_module,
            'ledger_journal_id'     => $this->ledger_journal_id,
            'parent_transaction_id' => $this->parent_transaction_id,
            'completed_at'          => $this->completed_at?->toIso8601String(),
            'failed_at'             => $this->failed_at?->toIso8601String(),
            'created_at'            => $this->created_at->toIso8601String(),
            'updated_at'            => $this->updated_at->toIso8601String(),
        ];
    }
}
