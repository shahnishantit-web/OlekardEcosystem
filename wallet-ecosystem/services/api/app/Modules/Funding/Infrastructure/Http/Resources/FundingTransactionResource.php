<?php

declare(strict_types=1);

namespace App\Modules\Funding\Infrastructure\Http\Resources;

use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FundingTransactionModel
 */
final class FundingTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->id,
            'customer_id'               => $this->customer_id,
            'wallet_id'                 => $this->wallet_id,
            'currency_code'             => $this->pocket_currency_code,
            'funding_method'            => $this->funding_method?->value ?? $this->funding_method,
            'status'                    => $this->status?->value ?? $this->status,
            'gross_amount'              => $this->gross_amount,
            'fee_amount'                => $this->fee_amount,
            'net_amount'                => $this->net_amount,
            'transaction_reference'     => $this->transaction_reference,
            'provider_reference_id'     => $this->provider_reference_id,
            'bank_reference_code'       => $this->bank_reference_code,
            'bank_reference_expires_at' => $this->bank_reference_expires_at?->toIso8601String(),
            'failure_reason'            => $this->failure_reason?->value ?? $this->failure_reason,
            'completed_at'              => $this->completed_at?->toIso8601String(),
            'created_at'                => $this->created_at?->toIso8601String(),
            'updated_at'                => $this->updated_at?->toIso8601String(),
        ];
    }
}
