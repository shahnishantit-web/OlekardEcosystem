<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Resources;

use App\Modules\Payment\Infrastructure\Models\PayoutBeneficiaryModel;
use Illuminate\Http\Resources\Json\JsonResource;

final class PayoutBeneficiaryResource extends JsonResource
{
    /** @var PayoutBeneficiaryModel */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'id'                     => $this->resource->id,
            'organisation_id'        => $this->resource->organisation_id,
            'account_type'           => $this->resource->account_type,
            'account_holder_name'    => $this->resource->account_holder_name,
            'bank_code'              => $this->resource->bank_code,
            'bank_name'              => $this->resource->bank_name,
            'currency_code'          => $this->resource->currency_code,
            'status'                 => $this->resource->status->value,
            'verified_at'            => $this->resource->verified_at?->toIso8601String(),
            'verification_reference' => $this->resource->verification_reference,
            'created_by'             => $this->resource->created_by,
            'created_at'             => $this->resource->created_at->toIso8601String(),
            'updated_at'             => $this->resource->updated_at->toIso8601String(),
            // account_number is NEVER exposed in API responses
        ];
    }
}
