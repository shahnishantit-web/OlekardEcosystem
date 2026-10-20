<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Domain\Contracts;

use App\Modules\Transaction\Infrastructure\Models\TransactionReceiptModel;

interface NotificationServiceContract
{
    public function sendReceiptShare(
        string $channel,
        string $recipient,
        TransactionReceiptModel $receipt,
    ): void;
}
