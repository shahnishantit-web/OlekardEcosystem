<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ServiceReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'receipt_reference'   => $this->receipt_reference,
            'transaction_id'      => $this->transaction_id,
            'service_id'          => $this->service_id,
            'service_name'        => $this->service_name,
            'customer_id'         => $this->customer_id,
            'customer_reference'  => $this->customer_reference,
            'account_name'        => $this->account_name,
            'currency_code'       => $this->currency_code,
            'amount'              => $this->amount,
            'fee_amount'          => $this->fee_amount,
            'total_amount'        => $this->amount + $this->fee_amount,
            'provider_reference'  => $this->provider_reference,
            'issued_at'           => $this->created_at?->toIso8601String(),
            'created_at'          => $this->created_at?->toIso8601String(),
        ];
    }
}
