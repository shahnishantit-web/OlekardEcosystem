<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ItemSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'batch_id'                 => $this->batch_id,
            'item_type'                => $this->item_type,
            'provider_reference'       => $this->provider_reference,
            'internal_transaction_id'  => $this->internal_transaction_id,
            'amount'                   => (string) $this->amount,
            'currency_code'            => $this->currency_code,
            'transaction_date'         => $this->transaction_date?->toDateString(),
            'status'                   => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'match_confidence'         => $this->match_confidence,
            'matched_at'               => $this->matched_at?->toIso8601String(),
            'created_at'               => $this->created_at?->toIso8601String(),
        ];
    }
}
