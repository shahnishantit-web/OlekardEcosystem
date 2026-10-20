<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Application\Services;

use App\Modules\Withdrawal\Infrastructure\Models\WithdrawalReceiptModel;
use App\Modules\Withdrawal\Infrastructure\Models\WithdrawalTransactionModel;
use Ramsey\Uuid\Uuid;

final class WithdrawalReceiptService
{
    public function createReceipt(
        WithdrawalTransactionModel $tx,
        string $journalId,
        ?string $providerReferenceId,
    ): WithdrawalReceiptModel {
        $receipt                            = new WithdrawalReceiptModel();
        $receipt->id                        = (string) Uuid::uuid7();
        $receipt->receipt_reference         = (string) Uuid::uuid7();
        $receipt->withdrawal_transaction_id = $tx->id;
        $receipt->gross_amount              = $tx->gross_amount;
        $receipt->fee_amount                = $tx->fee_amount;
        $receipt->net_payout_amount         = $tx->net_payout_amount;
        $receipt->currency_code             = $tx->pocket_currency_code;
        $receipt->channel                   = $tx->channel->value;
        $receipt->payout_destination_masked = $this->maskDestination($tx->payout_destination ?? []);
        $receipt->provider_reference_id     = $providerReferenceId;
        $receipt->customer_id               = $tx->customer_id;
        $receipt->wallet_id                 = $tx->wallet_id;
        $receipt->ledger_journal_id         = $journalId;
        $receipt->completed_at              = now();
        $receipt->save();

        return $receipt;
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
