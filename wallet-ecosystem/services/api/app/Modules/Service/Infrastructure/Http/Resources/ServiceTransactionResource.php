<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ServiceTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'service_id'         => $this->service_id,
            'wallet_id'          => $this->wallet_id,
            'customer_reference' => $this->customer_reference,
            'account_name'       => $this->account_name,
            'amount'             => $this->amount,
            'fee_amount'         => $this->fee_amount,
            'total_amount'       => $this->amount + $this->fee_amount,
            'currency_code'      => $this->currency_code,
            'status'             => $this->status->value,
            'provider_reference' => $this->provider_reference,
            'failure_reason'     => $this->failure_reason,
            'review_flag'        => $this->review_flag,
            'requery_count'      => $this->requery_count,
            'correlation_id'     => $this->correlation_id,
            'created_at'         => $this->created_at?->toIso8601String(),
            'updated_at'         => $this->updated_at?->toIso8601String(),
        ];
    }
}
