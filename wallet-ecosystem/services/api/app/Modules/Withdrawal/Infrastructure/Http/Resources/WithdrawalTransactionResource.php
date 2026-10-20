<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Infrastructure\Http\Resources;

use App\Modules\Withdrawal\Infrastructure\Models\WithdrawalTransactionModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WithdrawalTransactionModel */
final class WithdrawalTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'wallet_id'                => $this->wallet_id,
            'pocket_currency_code'     => $this->pocket_currency_code,
            'customer_id'              => $this->customer_id,
            'channel'                  => $this->channel->value,
            'status'                   => $this->status->value,
            'gross_amount'             => $this->gross_amount,
            'fee_amount'               => $this->fee_amount,
            'net_payout_amount'        => $this->net_payout_amount,
            'fee_rate_bps'             => $this->fee_rate_bps,
            'payout_destination_masked' => $this->when(
                $this->payout_destination !== null,
                fn () => $this->maskDestination($this->payout_destination ?? [])
            ),
            'wallet_hold_id'           => $this->wallet_hold_id,
            'provider_reference_id'    => $this->provider_reference_id,
            'failure_reason'           => $this->failure_reason?->value,
            'ledger_journal_id'        => $this->ledger_journal_id,
            'transaction_reference'    => $this->transaction_reference,
            'completed_at'             => $this->completed_at?->toIso8601String(),
            'failed_at'                => $this->failed_at?->toIso8601String(),
            'created_at'               => $this->created_at->toIso8601String(),
            'updated_at'               => $this->updated_at->toIso8601String(),

            // Included only in initiation response (when $limits is set on the resource)
            'limits' => $this->when(
                isset($this->additional['limits']),
                fn () => $this->additional['limits']
            ),
        ];
    }

    private function maskDestination(array $destination): string
    {
        $target = $destination['phone_number']
            ?? $destination['account_number']
            ?? '';

        if (strlen($target) >= 4) {
            return '****' . substr($target, -4);
        }

        return '****';
    }
}
