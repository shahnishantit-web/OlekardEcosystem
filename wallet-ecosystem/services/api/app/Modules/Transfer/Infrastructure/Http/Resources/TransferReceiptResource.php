<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Infrastructure\Http\Resources;

use App\Modules\Transfer\Infrastructure\Models\TransferReceiptModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TransferReceiptModel
 */
final class TransferReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'transfer_transaction_id' => $this->transfer_transaction_id,
            'receipt_reference'       => $this->receipt_reference,
            'currency_code'           => $this->currency_code,
            'gross_amount'            => $this->gross_amount,
            'fee_amount'              => $this->fee_amount,
            'net_amount'              => $this->net_amount,
            'issued_at'               => $this->issued_at->toIso8601String(),
        ];
    }
}
