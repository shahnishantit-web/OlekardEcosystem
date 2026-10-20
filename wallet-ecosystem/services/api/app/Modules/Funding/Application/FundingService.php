<?php

declare(strict_types=1);

namespace App\Modules\Funding\Application;

use App\Modules\Funding\Domain\Contracts\FundingModuleContract;
use App\Modules\Funding\Domain\Exceptions\FundingTransactionNotFoundException;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;

final class FundingService implements FundingModuleContract
{
    public function getFundingTransaction(string $fundingTransactionId): array
    {
        $tx = FundingTransactionModel::find($fundingTransactionId);

        if ($tx === null) {
            throw FundingTransactionNotFoundException::forId($fundingTransactionId);
        }

        return [
            'id'             => $tx->id,
            'status'         => $tx->status instanceof FundingStatus ? $tx->status->value : $tx->status,
            'amount'         => $tx->amount,
            'currency_code'  => $tx->pocket_currency_code,
            'funding_method' => $tx->funding_method instanceof \BackedEnum ? $tx->funding_method->value : $tx->funding_method,
            'completed_at'   => $tx->completed_at?->toIso8601String(),
        ];
    }

    public function isFundingCompleted(string $transactionReference): bool
    {
        return FundingTransactionModel::where('transaction_reference', $transactionReference)
            ->where('status', FundingStatus::Completed->value)
            ->exists();
    }
}
