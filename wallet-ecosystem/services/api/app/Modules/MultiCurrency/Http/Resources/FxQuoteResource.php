<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Http\Resources;

use App\Modules\MultiCurrency\Infrastructure\Models\FxQuoteModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FxQuoteModel
 */
final class FxQuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'from_currency_code' => $this->from_currency_code,
            'to_currency_code'   => $this->to_currency_code,
            'from_amount'        => $this->from_amount,
            'to_amount'          => $this->to_amount,
            'fee_amount'         => $this->fee_amount,
            'rate'               => $this->rate,
            'spread'             => $this->spread,
            'status'             => $this->status->value,
            'expires_at'         => $this->expires_at?->toIso8601String(),
            'fx_transaction_id'  => $this->fx_transaction_id,
            'created_at'         => $this->created_at?->toIso8601String(),
        ];
    }
}
