<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Resources;

use App\Modules\Payment\Infrastructure\Models\ProviderStatementModel;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProviderStatementListResource extends JsonResource
{
    /** @var ProviderStatementModel */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'id'               => $this->resource->id,
            'provider_code'    => $this->resource->provider_code,
            'account_id'       => $this->resource->account_id,
            'currency_code'    => $this->resource->currency_code,
            'period_start'     => $this->resource->period_start->toIso8601String(),
            'period_end'       => $this->resource->period_end->toIso8601String(),
            'status'           => $this->resource->status->value,
            'total_lines'      => $this->resource->total_lines,
            'matched_lines'    => $this->resource->matched_lines,
            'exception_lines'  => $this->resource->exception_lines,
            'ingested_at'      => $this->resource->ingested_at->toIso8601String(),
        ];
    }
}
