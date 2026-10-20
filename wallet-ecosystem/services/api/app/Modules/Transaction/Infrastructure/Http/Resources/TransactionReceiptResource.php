<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Http\Resources;

use App\Modules\Transaction\Infrastructure\Models\TransactionReceiptModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TransactionReceiptModel */
final class TransactionReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'transaction_id' => $this->transaction_id,
            'reference'      => $this->reference,
            'amount'         => $this->amount,
            'currency_code'  => $this->currency_code,
            'type'           => $this->type,
            'channel'        => $this->channel,
            'customer_id'    => $this->customer_id,
            'created_at'     => $this->created_at->toIso8601String(),
        ];
    }
}
