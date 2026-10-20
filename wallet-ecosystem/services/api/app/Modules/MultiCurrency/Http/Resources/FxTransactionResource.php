<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Http\Resources;

use App\Modules\MultiCurrency\Infrastructure\Models\FxTransactionModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FxTransactionModel
 */
final class FxTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'quote_id'           => $this->quote_id,
            'from_currency_code' => $this->from_currency_code,
            'to_currency_code'   => $this->to_currency_code,
            'from_amount'        => $this->from_amount,
            'to_amount'          => $this->to_amount,
            'fee_amount'         => $this->fee_amount,
            'rate'               => $this->rate,
            'status'             => $this->status->value,
            'failure_reason'     => $this->failure_reason,
            'completed_at'       => $this->completed_at?->toIso8601String(),
            'created_at'         => $this->created_at?->toIso8601String(),
        ];
    }
}
