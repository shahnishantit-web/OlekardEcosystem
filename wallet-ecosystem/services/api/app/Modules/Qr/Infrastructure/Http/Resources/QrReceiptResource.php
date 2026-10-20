<?php

declare(strict_types=1);

namespace App\Modules\Qr\Infrastructure\Http\Resources;

use App\Modules\Qr\Infrastructure\Models\QrPaymentReceiptModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QrPaymentReceiptModel
 */
final class QrReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'receipt_ref'              => $this->receipt_ref,
            'qr_payment_transaction_id' => $this->qr_payment_transaction_id,
            'merchant_display_name'    => $this->merchant_display_name,
            'merchant_ref'             => $this->merchant_ref,
            'amount'                   => $this->amount,
            'fee'                      => $this->fee,
            'total_debited'            => $this->total_debited,
            'currency'                 => $this->currency,
            'issued_at'                => $this->issued_at?->toIso8601String(),
            'created_at'               => $this->created_at?->toIso8601String(),
        ];
    }
}
