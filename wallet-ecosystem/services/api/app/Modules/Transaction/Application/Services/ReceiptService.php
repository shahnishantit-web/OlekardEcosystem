<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Application\Services;

use App\Modules\Transaction\Domain\Contracts\NotificationServiceContract;
use App\Modules\Transaction\Domain\Exceptions\TransactionNotFoundException;
use App\Modules\Transaction\Infrastructure\Models\TransactionProjectionModel;
use App\Modules\Transaction\Infrastructure\Models\TransactionReceiptModel;
use App\Modules\Transaction\Infrastructure\Repositories\TransactionProjectionRepository;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class ReceiptService
{
    public function __construct(
        private readonly TransactionProjectionRepository $transactionRepo,
        private readonly NotificationServiceContract     $notificationService,
    ) {}

    public function getOrCreate(string $transactionId): TransactionReceiptModel
    {
        $tx = $this->transactionRepo->findById($transactionId);

        if ($tx === null) {
            throw TransactionNotFoundException::forId($transactionId);
        }

        $existing = TransactionReceiptModel::where('transaction_id', $transactionId)->first();

        if ($existing !== null) {
            return $existing;
        }

        return TransactionReceiptModel::create([
            'id'             => (string) Uuid::uuid7(),
            'transaction_id' => $tx->id,
            'reference'      => $tx->reference,
            'amount'         => $tx->amount,
            'currency_code'  => $tx->currency_code,
            'type'           => $tx->type->value,
            'channel'        => $tx->metadata['channel'] ?? null,
            'customer_id'    => $tx->customer_id,
            'created_at'     => now(),
        ]);
    }

    public function share(string $transactionId, string $channel, string $recipient, string $actorId): void
    {
        $receipt = $this->getOrCreate($transactionId);

        $this->notificationService->sendReceiptShare($channel, $recipient, $receipt);

        Log::info('transaction.receipt.shared', [
            'transaction_id' => $transactionId,
            'channel'        => $channel,
            'actor_id'       => $actorId,
        ]);
    }
}
