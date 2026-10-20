<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Http\Resources;

use App\Modules\Commission\Infrastructure\Models\CommissionPayoutModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CommissionPayoutModel */
final class CommissionPayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'agent_id'       => $this->agent_id,
            'approved_by'    => $this->approved_by,
            'total_amount'   => $this->total_amount,
            'currency_code'  => $this->currency_code,
            'status'         => $this->status->value,
            'journal_id'     => $this->journal_id,
            'executed_at'    => $this->executed_at?->toIso8601String(),
            'cancelled_by'   => $this->cancelled_by,
            'cancelled_at'   => $this->cancelled_at?->toIso8601String(),
            'commission_ids' => $this->whenLoaded('commissions', fn () => $this->commissions->pluck('id')->all()),
            'created_at'     => $this->created_at?->toIso8601String(),
            'updated_at'     => $this->updated_at?->toIso8601String(),
        ];
    }
}
