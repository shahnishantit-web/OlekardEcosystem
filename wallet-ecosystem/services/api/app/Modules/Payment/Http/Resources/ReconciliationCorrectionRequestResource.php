<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Resources;

use App\Modules\Payment\Infrastructure\Models\ReconciliationCorrectionRequestModel;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReconciliationCorrectionRequestResource extends JsonResource
{
    /** @var ReconciliationCorrectionRequestModel */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'id'                 => $this->resource->id,
            'exception_id'       => $this->resource->exception_id,
            'idempotency_key'    => $this->resource->idempotency_key,
            'correction_type'    => $this->resource->correction_type,
            'description'        => $this->resource->description,
            'evidence_reference' => $this->resource->evidence_reference,
            'amount_minor'       => $this->resource->amount_minor,
            'currency_code'      => $this->resource->currency_code,
            'status'             => $this->resource->status->value,
            'requested_by'       => $this->resource->requested_by,
            'reviewed_by'        => $this->resource->reviewed_by,
            'reviewed_at'        => $this->resource->reviewed_at?->toIso8601String(),
            'rejection_reason'   => $this->resource->rejection_reason,
            'ledger_entry_id'    => $this->resource->ledger_entry_id,
            'posted_at'          => $this->resource->posted_at?->toIso8601String(),
            'created_at'         => $this->resource->created_at->toIso8601String(),
        ];
    }
}
