<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ComplianceTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'transaction_id'   => $this->resource['transaction_id'] ?? null,
            'transaction_type' => $this->resource['transaction_type'] ?? null,
            'amount_minor'     => $this->resource['amount_minor'] ?? null,
            'currency_code'    => $this->resource['currency_code'] ?? null,
            'outcome'          => $this->resource['outcome'] ?? null,
            'occurred_at'      => $this->resource['occurred_at'] ?? null,
            'user_id'          => $this->resource['user_id'] ?? null,
        ];
    }
}
