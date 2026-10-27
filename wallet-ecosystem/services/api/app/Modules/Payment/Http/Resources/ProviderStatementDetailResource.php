<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Resources;

use App\Modules\Payment\Infrastructure\Models\ProviderStatementModel;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProviderStatementDetailResource extends JsonResource
{
    /** @var ProviderStatementModel */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        $lines = $this->resource->lines ?? collect();

        return [
            'id'                     => $this->resource->id,
            'provider_code'          => $this->resource->provider_code,
            'account_id'             => $this->resource->account_id,
            'currency_code'          => $this->resource->currency_code,
            'period_start'           => $this->resource->period_start->toIso8601String(),
            'period_end'             => $this->resource->period_end->toIso8601String(),
            'status'                 => $this->resource->status->value,
            'total_lines'            => $this->resource->total_lines,
            'matched_lines'          => $this->resource->matched_lines,
            'exception_lines'        => $this->resource->exception_lines,
            'source_hash'            => $this->resource->source_hash,
            'ingested_at'            => $this->resource->ingested_at->toIso8601String(),
            'matching_completed_at'  => $this->resource->matching_completed_at?->toIso8601String(),
            'lines'                  => $lines->map(fn ($line): array => [
                'id'                   => $line->id,
                'line_type'            => $line->line_type->value,
                'provider_reference_id' => $line->provider_reference_id,
                'amount_minor'         => $line->amount_minor,
                'currency_code'        => $line->currency_code,
                'direction'            => $line->direction,
                'settled_at'           => $line->settled_at->toIso8601String(),
                'match_status'         => $line->match_status->value,
                'matched_entity_type'  => $line->matched_entity_type,
                'matched_entity_id'    => $line->matched_entity_id,
                'clearing_record_id'   => $line->clearing_record_id,
                'exception_id'         => $line->exception_id,
            ])->values()->toArray(),
        ];
    }
}
