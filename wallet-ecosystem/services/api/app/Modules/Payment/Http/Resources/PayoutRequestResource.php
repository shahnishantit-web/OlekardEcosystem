<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Resources;

use App\Modules\Payment\Infrastructure\Models\PayoutRequestModel;
use Illuminate\Http\Resources\Json\JsonResource;

final class PayoutRequestResource extends JsonResource
{
    /** @var PayoutRequestModel */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        $data = [
            'id'                     => $this->resource->id,
            'idempotency_key'        => $this->resource->idempotency_key,
            'fund_reference_id'      => $this->resource->fund_reference_id,
            'purpose_type'           => $this->resource->purpose_type,
            'organisation_id'        => $this->resource->organisation_id,
            'beneficiary_id'         => $this->resource->beneficiary_id,
            'currency_code'          => $this->resource->currency_code,
            'requested_amount_minor' => $this->resource->requested_amount_minor,
            'computed_payable_minor' => $this->resource->computed_payable_minor,
            'custody_wallet_id'      => $this->resource->custody_wallet_id,
            'status'                 => $this->resource->status->value,
            'operation_id'           => $this->resource->operation_id,
            'created_by'             => $this->resource->created_by,
            'settled_at'             => $this->resource->settled_at?->toIso8601String(),
            'created_at'             => $this->resource->created_at->toIso8601String(),
            'updated_at'             => $this->resource->updated_at->toIso8601String(),
        ];

        if ($this->resource->relationLoaded('reservation')) {
            $data['reservation'] = $this->resource->reservation
                ? new PayoutReservationResource($this->resource->reservation)
                : null;
        }

        return $data;
    }
}
