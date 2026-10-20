<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class AccountHoldResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'subject_type' => $this->subject_type,
            'subject_id'   => $this->subject_id,
            'wallet_id'    => $this->wallet_id,
            'hold_type'    => $this->hold_type instanceof \BackedEnum ? $this->hold_type->value : $this->hold_type,
            'status'       => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'reason'       => $this->reason,
            'alert_id'     => $this->alert_id,
            'decision_id'  => $this->decision_id,
            'placed_by'    => $this->placed_by,
            'lifted_by'    => $this->lifted_by,
            'placed_at'    => $this->placed_at?->toIso8601String(),
            'expires_at'   => $this->expires_at?->toIso8601String(),
            'lifted_at'    => $this->lifted_at?->toIso8601String(),
            'lift_reason'  => $this->lift_reason,
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
