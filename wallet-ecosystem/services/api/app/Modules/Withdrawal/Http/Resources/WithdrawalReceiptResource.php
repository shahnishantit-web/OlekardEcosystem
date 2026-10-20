<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property string      $id
 * @property string      $receipt_reference
 * @property string      $withdrawal_transaction_id
 * @property int         $gross_amount
 * @property int         $fee_amount
 * @property int         $net_payout_amount
 * @property string      $currency_code
 * @property string      $channel
 * @property string      $payout_destination_masked
 * @property string|null $provider_reference_id
 * @property string      $customer_id
 * @property string      $wallet_id
 * @property string      $ledger_journal_id
 * @property string      $completed_at
 * @property string      $created_at
 */
final class WithdrawalReceiptResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                        => $this->id,
            'receipt_reference'         => $this->receipt_reference,
            'withdrawal_transaction_id' => $this->withdrawal_transaction_id,
            'gross_amount'              => $this->gross_amount,
            'fee_amount'                => $this->fee_amount,
            'net_payout_amount'         => $this->net_payout_amount,
            'currency_code'             => $this->currency_code,
            'channel'                   => $this->channel,
            'payout_destination_masked' => $this->payout_destination_masked,
            'provider_reference_id'     => $this->provider_reference_id,
            'customer_id'               => $this->customer_id,
            'wallet_id'                 => $this->wallet_id,
            'ledger_journal_id'         => $this->ledger_journal_id,
            'completed_at'              => $this->completed_at,
            'created_at'                => $this->created_at,
        ];
    }
}
