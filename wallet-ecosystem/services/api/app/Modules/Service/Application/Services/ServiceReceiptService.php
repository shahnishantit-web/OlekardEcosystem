<?php

declare(strict_types=1);

namespace App\Modules\Service\Application\Services;

use App\Modules\Service\Domain\Enums\ServiceTransactionStatus;
use App\Modules\Service\Domain\Exceptions\ServiceTransactionNotFoundException;
use App\Modules\Service\Infrastructure\Models\ServiceReceiptModel;
use App\Modules\Service\Infrastructure\Models\ServiceTransactionModel;
use Illuminate\Support\Str;

final class ServiceReceiptService
{
    public function createReceipt(ServiceTransactionModel $tx): ServiceReceiptModel
    {
        // Idempotent: return existing receipt if already created
        $existing = ServiceReceiptModel::where('transaction_id', $tx->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $receipt = ServiceReceiptModel::create([
            'id'                               => Str::orderedUuid()->toString(),
            'transaction_id'                   => $tx->id,
            'receipt_reference'                => Str::orderedUuid()->toString(),
            'service_name'                     => $tx->service?->name ?? '',
            'customer_reference'               => $tx->customer_reference,
            'currency_code'                    => $tx->currency_code,
            'amount'                           => $tx->amount,
            'fee_amount'                       => $tx->fee_amount,
            'total_amount'                     => $tx->total_amount,
            'provider_confirmation_reference'  => $tx->provider_reference ?? null,
            'issued_at'                        => now(),
        ]);

        return $receipt;
    }

    public function getReceipt(string $txId, string $requestorId): ServiceReceiptModel
    {
        $tx = ServiceTransactionModel::find($txId);
        if ($tx === null) {
            throw new ServiceTransactionNotFoundException($txId);
        }

        if ($tx->customer_id !== $requestorId) {
            abort(403, 'Access denied.');
        }

        if ($tx->status !== ServiceTransactionStatus::Completed) {
            abort(422, 'RECEIPT_NOT_AVAILABLE');
        }

        $receipt = ServiceReceiptModel::where('transaction_id', $txId)->first();
        if ($receipt === null) {
            abort(422, 'RECEIPT_NOT_AVAILABLE');
        }

        return $receipt;
    }
}
