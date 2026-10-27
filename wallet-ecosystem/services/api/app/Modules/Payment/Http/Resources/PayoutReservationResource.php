<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Resources;

use App\Modules\Payment\Infrastructure\Models\PayoutReservationModel;
use Illuminate\Http\Resources\Json\JsonResource;

final class PayoutReservationResource extends JsonResource
{
    /** @var PayoutReservationModel */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'id'                    => $this->resource->id,
            'payout_request_id'     => $this->resource->payout_request_id,
            'reserved_amount_minor' => $this->resource->reserved_amount_minor,
            'currency_code'         => $this->resource->currency_code,
            'status'                => $this->resource->status->value,
            'maker_id'              => $this->resource->maker_id,
            'approver_id'           => $this->resource->approver_id,
            'approved_at'           => $this->resource->approved_at?->toIso8601String(),
            'rejection_reason'      => $this->resource->rejection_reason,
            'expires_at'            => $this->resource->expires_at->toIso8601String(),
            'created_at'            => $this->resource->created_at->toIso8601String(),
            'updated_at'            => $this->resource->updated_at->toIso8601String(),
        ];
    }
}
