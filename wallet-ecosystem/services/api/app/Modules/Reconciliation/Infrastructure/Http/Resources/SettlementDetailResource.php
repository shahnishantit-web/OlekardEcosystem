<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SettlementDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'idempotency_key'        => $this->idempotency_key,
            'settlement_type'        => $this->settlement_type,
            'provider_code'          => $this->provider_code,
            'period_start'           => $this->period_start?->toDateString(),
            'period_end'             => $this->period_end?->toDateString(),
            'currency_code'          => $this->currency_code,
            'gross_amount'           => (string) $this->gross_amount,
            'fee_amount'             => (string) $this->fee_amount,
            'net_amount'             => (string) $this->net_amount,
            'status'                 => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'maker_id'               => $this->maker_id,
            'checker_id'             => $this->checker_id,
            'approved_at'            => $this->approved_at?->toIso8601String(),
            'rejected_at'            => $this->rejected_at?->toIso8601String(),
            'rejection_reason'       => $this->rejection_reason,
            'executed_at'            => $this->executed_at?->toIso8601String(),
            'ledger_transaction_id'  => $this->ledger_transaction_id,
            'external_reference'     => $this->external_reference,
            'metadata'               => $this->metadata,
            'items_count'            => $this->whenLoaded('items', fn () => $this->items->count()),
            'created_at'             => $this->created_at?->toIso8601String(),
            'updated_at'             => $this->updated_at?->toIso8601String(),
        ];
    }
}
