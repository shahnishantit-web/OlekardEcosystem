<?php

declare(strict_types=1);

namespace App\Modules\Qr\Infrastructure\Http\Resources;

use App\Modules\Qr\Domain\DTO\QrPaymentResult;
use App\Modules\Qr\Infrastructure\Models\QrPaymentTransactionModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QrPaymentTransactionModel|QrPaymentResult
 */
final class QrPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $resource = $this->resource;

        if ($resource instanceof QrPaymentResult) {
            return [
                'id'                   => $resource->id,
                'status'               => $resource->status->value,
                'amount'               => $resource->amount,
                'fee'                  => $resource->fee,
                'total_debited'        => $resource->totalDebited,
                'currency'             => $resource->currency,
                'merchant_display_name' => $resource->merchantDisplayName,
                'receipt_ref'          => $resource->receiptRef,
                'created_at'           => $resource->createdAt?->toIso8601String(),
            ];
        }

        /** @var QrPaymentTransactionModel $tx */
        $tx = $resource;

        return [
            'id'             => $tx->id,
            'status'         => $tx->status->value,
            'amount'         => $tx->amount,
            'fee'            => $tx->fee,
            'total_debited'  => $tx->amount + $tx->fee,
            'currency'       => $tx->currency,
            'receipt_ref'    => $tx->receipt?->receipt_ref,
            'created_at'     => $tx->created_at?->toIso8601String(),
        ];
    }
}
