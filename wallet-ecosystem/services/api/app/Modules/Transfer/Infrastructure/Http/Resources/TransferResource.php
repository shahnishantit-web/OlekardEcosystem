<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Infrastructure\Http\Resources;

use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TransferTransactionModel
 */
final class TransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'transfer_reference' => $this->transfer_reference,
            'sender_wallet_id'   => $this->sender_wallet_id,
            'recipient_wallet_id' => $this->recipient_wallet_id,
            'currency_code'      => $this->currency_code,
            'gross_amount'       => $this->gross_amount,
            'fee_amount'         => $this->fee_amount,
            'net_amount'         => $this->net_amount,
            'fee_rate_bps'       => $this->fee_rate_bps,
            'status'             => $this->status->value,
            'failure_reason'     => $this->failure_reason?->value,
            'completed_at'       => $this->completed_at?->toIso8601String(),
            'cancelled_at'       => $this->cancelled_at?->toIso8601String(),
            'created_at'         => $this->created_at->toIso8601String(),
        ];
    }
}
