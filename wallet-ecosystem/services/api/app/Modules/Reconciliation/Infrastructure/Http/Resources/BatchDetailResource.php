<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class BatchDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'idempotency_key'  => $this->idempotency_key,
            'source_type'      => $this->source_type instanceof \BackedEnum ? $this->source_type->value : $this->source_type,
            'source_reference' => $this->source_reference,
            'batch_date'       => $this->batch_date?->toDateString(),
            'provider_code'    => $this->provider_code,
            'status'           => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'total_count'      => $this->total_count,
            'matched_count'    => $this->matched_count,
            'unmatched_count'  => $this->unmatched_count,
            'exception_count'  => $this->exception_count,
            'file_path'        => $this->file_path,
            'submitted_by'     => $this->submitted_by,
            'processed_at'     => $this->processed_at?->toIso8601String(),
            'metadata'         => $this->metadata,
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
