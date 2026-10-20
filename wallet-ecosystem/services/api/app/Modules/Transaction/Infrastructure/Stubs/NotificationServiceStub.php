<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Stubs;

use App\Modules\Transaction\Domain\Contracts\NotificationServiceContract;
use App\Modules\Transaction\Infrastructure\Models\TransactionReceiptModel;
use Illuminate\Support\Facades\Log;

final class NotificationServiceStub implements NotificationServiceContract
{
    public function sendReceiptShare(
        string $channel,
        string $recipient,
        TransactionReceiptModel $receipt,
    ): void {
        Log::info('transaction.receipt.share.stub', [
            'channel'        => $channel,
            'recipient'      => $recipient,
            'transaction_id' => $receipt->transaction_id,
            'reference'      => $receipt->reference,
        ]);
    }
}
