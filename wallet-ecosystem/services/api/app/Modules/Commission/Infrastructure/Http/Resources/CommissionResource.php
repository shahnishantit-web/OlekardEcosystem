<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Http\Resources;

use App\Modules\Commission\Infrastructure\Models\CommissionModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CommissionModel */
final class CommissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isPaid = $this->status->value === 'paid';

        return [
            'id'               => $this->id,
            'agent_id'         => $this->agent_id,
            'transaction_id'   => $this->transaction_id,
            'transaction_type' => $this->transaction_type->value,
            'amount'           => $this->amount,
            'currency_code'    => $this->currency_code,
            'rule_id'          => $this->rule_id,
            'status'           => $this->status->value,
            'payout_id'        => $this->payout_id,
            'journal_id'          => $this->journal_id,
            'reversal_journal_id' => $this->reversal_journal_id,
            'created_at'          => $this->created_at?->toIso8601String(),
            'paid_at'             => $isPaid ? $this->updated_at?->toIso8601String() : null,
        ];
    }
}
