<?php

declare(strict_types=1);

namespace App\Modules\Qr\Application\Services;

use App\Modules\Qr\Domain\Enums\QrPaymentStatus;
use App\Modules\Qr\Domain\Exceptions\ReceiptNotAvailableException;
use App\Modules\Qr\Infrastructure\Models\QrPaymentReceiptModel;
use App\Modules\Qr\Infrastructure\Models\QrPaymentTransactionModel;
use Illuminate\Support\Str;

class QrReceiptService
{
    public function createForTransaction(
        QrPaymentTransactionModel $tx,
        string $merchantDisplayName,
    ): QrPaymentReceiptModel {
        $maxAttempts = 5;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $receiptRef = 'QRPMT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(5));

            try {
                $receipt                          = new QrPaymentReceiptModel();
                $receipt->id                      = Str::orderedUuid()->toString();
                $receipt->receipt_ref             = $receiptRef;
                $receipt->qr_payment_transaction_id = $tx->id;
                $receipt->merchant_display_name   = $merchantDisplayName;
                $receipt->merchant_ref            = null;
                $receipt->amount                  = $tx->amount;
                $receipt->fee                     = $tx->fee;
                $receipt->total_debited           = $tx->amount + $tx->fee;
                $receipt->currency                = $tx->currency;
                $receipt->issued_at               = now();
                $receipt->save();

                return $receipt;
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // Retry with new ref
                continue;
            }
        }

        throw new \RuntimeException('Failed to generate unique receipt_ref after ' . $maxAttempts . ' attempts.');
    }

    public function findForCustomer(string $transactionId, string $customerId): QrPaymentReceiptModel
    {
        /** @var QrPaymentTransactionModel|null $tx */
        $tx = QrPaymentTransactionModel::where('id', $transactionId)
            ->where('customer_wallet_id', $customerId)
            ->first();

        if ($tx === null) {
            throw new ReceiptNotAvailableException();
        }

        if ($tx->status !== QrPaymentStatus::Completed) {
            throw new ReceiptNotAvailableException();
        }

        /** @var QrPaymentReceiptModel|null $receipt */
        $receipt = QrPaymentReceiptModel::where('qr_payment_transaction_id', $tx->id)->first();

        if ($receipt === null) {
            throw new ReceiptNotAvailableException();
        }

        return $receipt;
    }
}
