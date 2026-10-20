<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InvestigationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'transaction_id' => $this->resource['transaction_id'] ?? null,
            'reference'      => $this->resource['reference'] ?? null,
            'status'         => $this->resource['status'] ?? null,
            'type'           => $this->resource['type'] ?? null,
            'amount'         => $this->resource['amount'] ?? null,
            'currency_code'  => $this->resource['currency_code'] ?? null,
            'failed_at'      => $this->resource['failed_at'] ?? null,
            'failure_reason' => $this->resource['failure_reason'] ?? null,
            'failure_code'   => $this->resource['failure_code'] ?? null,
            'provider_ref'   => $this->resource['provider_ref'] ?? null,
            'retry_count'    => $this->resource['retry_count'] ?? 0,
            'metadata'       => $this->resource['metadata'] ?? [],
        ];
    }
}
