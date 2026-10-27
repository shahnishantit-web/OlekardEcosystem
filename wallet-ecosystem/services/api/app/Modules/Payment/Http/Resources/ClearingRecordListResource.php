<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Resources;

use App\Modules\Payment\Infrastructure\Models\ClearingRecordModel;
use Illuminate\Http\Resources\Json\JsonResource;

final class ClearingRecordListResource extends JsonResource
{
    /** @var ClearingRecordModel */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'id'                         => $this->resource->id,
            'payment_attempt_id'         => $this->resource->payment_attempt_id,
            'statement_id'               => $this->resource->statement_id,
            'provider_code'              => $this->resource->provider_code,
            'currency_code'              => $this->resource->currency_code,
            'cleared_amount_minor'       => $this->resource->cleared_amount_minor,
            'attempt_amount_minor'       => $this->resource->attempt_amount_minor,
            'clearing_status'            => $this->resource->clearing_status,
            'accounting_status'          => $this->resource->accounting_status,
            'bank_settlement_batch_ref'  => $this->resource->bank_settlement_batch_ref,
            'bank_settled_at'            => $this->resource->bank_settled_at?->toIso8601String(),
            'aged_at'                    => $this->resource->aged_at?->toIso8601String(),
            'cleared_at'                 => $this->resource->cleared_at->toIso8601String(),
        ];
    }
}
