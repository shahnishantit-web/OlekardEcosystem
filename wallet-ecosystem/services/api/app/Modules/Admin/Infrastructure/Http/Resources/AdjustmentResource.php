<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'wallet_id'            => $this->wallet_id,
            'adjustment_type'      => $this->adjustment_type,
            'amount'               => $this->amount,
            'currency'             => $this->currency,
            'reason'               => $this->reason,
            'status'               => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'maker_id'             => $this->maker_id,
            'checker_id'           => $this->checker_id,
            'ledger_transaction_id' => $this->ledger_transaction_id,
            'rejection_reason'     => $this->rejection_reason,
            'approved_at'          => $this->approved_at?->toISOString(),
            'posted_at'            => $this->posted_at?->toISOString(),
            'rejected_at'          => $this->rejected_at?->toISOString(),
            'created_at'           => $this->created_at?->toISOString(),
        ];
    }
}
