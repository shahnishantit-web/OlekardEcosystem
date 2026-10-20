<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TransactionSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_object($this->resource) ? (array) $this->resource : $this->resource;

        return [
            'id'             => $data['id'] ?? null,
            'reference'      => $data['reference'] ?? null,
            'type'           => $data['type'] ?? null,
            'status'         => $data['status'] ?? null,
            'amount'         => $data['amount'] ?? null,
            'currency_code'  => $data['currency_code'] ?? null,
            'direction'      => $data['direction'] ?? null,
            'wallet_id'      => $data['wallet_id'] ?? null,
            'customer_id'    => $data['customer_id'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
            'source_module'  => $data['source_module'] ?? null,
            'created_at'     => $data['created_at'] ?? null,
            'completed_at'   => $data['completed_at'] ?? null,
            'failed_at'      => $data['failed_at'] ?? null,
        ];
    }
}
