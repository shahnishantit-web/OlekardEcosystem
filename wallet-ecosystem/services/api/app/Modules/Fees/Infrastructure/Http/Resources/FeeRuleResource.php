<?php

declare(strict_types=1);

namespace App\Modules\Fees\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FeeRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'pricing_version_id' => $this->pricing_version_id,
            'transaction_type'   => $this->transaction_type instanceof \BackedEnum
                ? $this->transaction_type->value
                : $this->transaction_type,
            'fee_type'           => $this->fee_type instanceof \BackedEnum
                ? $this->fee_type->value
                : $this->fee_type,
            'currency'           => $this->currency,
            'subject_type'       => $this->subject_type instanceof \BackedEnum
                ? $this->subject_type->value
                : $this->subject_type,
            'subject_id'         => $this->subject_id,
            'rate'               => $this->rate,
            'amount_minor'       => $this->amount_minor,
            'min_fee_minor'      => $this->min_fee_minor,
            'max_fee_minor'      => $this->max_fee_minor,
            'valid_from'         => $this->valid_from?->toIso8601String(),
            'valid_to'           => $this->valid_to?->toIso8601String(),
            'status'             => $this->status instanceof \BackedEnum
                ? $this->status->value
                : $this->status,
            'created_by'         => $this->created_by,
            'created_at'         => $this->created_at?->toIso8601String(),
            'updated_at'         => $this->updated_at?->toIso8601String(),
        ];
    }
}
