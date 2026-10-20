<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Application\EventHandlers;

use App\Modules\Transaction\Application\Services\TransactionProjectionService;
use App\Modules\Transfer\Infrastructure\Models\TransferTransactionModel;
use App\Modules\Transfer\Domain\Events\TransferCancelled;
use App\Modules\Transfer\Domain\Events\TransferCompleted;
use App\Modules\Transfer\Domain\Events\TransferFailed;
use App\Modules\Transfer\Domain\Events\TransferInitiated;
use Illuminate\Support\Facades\Log;

final class TransferTransactionEventHandler
{
    public function __construct(
        private readonly TransactionProjectionService $projectionService,
    ) {}

    public function handleInitiated(TransferInitiated $event): void
    {
        Log::withContext(['correlation_id' => $event->correlationId]);

        $source = TransferTransactionModel::find($event->transferId);

        if ($source === null) {
            Log::warning('transaction.projection.transfer.source_not_found', [
                'transfer_id' => $event->transferId,
            ]);
            return;
        }

        $baseAttributes = [
            'type'           => 'transfer',
            'status'         => 'pending',
            'amount'         => $event->grossAmount,
            'currency_code'  => $event->currencyCode,
            'source_module'  => 'e09_transfer',
            'correlation_id' => $event->correlationId,
            'created_at'     => $event->occurredAt,
            'updated_at'     => $event->occurredAt,
        ];

        $this->projectionService->upsert(array_merge($baseAttributes, [
            'reference'   => $source->transfer_reference . '-S',
            'direction'   => 'debit',
            'wallet_id'   => $event->senderWalletId,
            'customer_id' => $source->sender_customer_id,
            'description' => 'Transfer to recipient',
        ]), $event->transferId . '-sender');

        $this->projectionService->upsert(array_merge($baseAttributes, [
            'reference'   => $source->transfer_reference . '-R',
            'direction'   => 'credit',
            'wallet_id'   => $event->recipientWalletId,
            'customer_id' => $source->recipient_customer_id,
            'description' => 'Transfer received',
        ]), $event->transferId . '-recipient');
    }

    public function handleCompleted(TransferCompleted $event): void
    {
        Log::withContext(['correlation_id' => $event->correlationId]);

        $source = TransferTransactionModel::find($event->transferId);

        $sharedAttributes = [
            'status'            => 'completed',
            'ledger_journal_id' => $source?->ledger_journal_id,
            'completed_at'      => $event->occurredAt,
            'updated_at'        => $event->occurredAt,
        ];

        $this->projectionService->upsert(array_merge($sharedAttributes, [
            'reference'     => ($source?->transfer_reference ?? $event->transferId) . '-S',
            'type'          => 'transfer',
            'amount'        => $event->grossAmount,
            'currency_code' => $event->currencyCode,
            'direction'     => 'debit',
            'wallet_id'     => $event->senderWalletId,
            'customer_id'   => $source?->sender_customer_id ?? '',
            'source_module' => 'e09_transfer',
            'created_at'    => $event->occurredAt,
        ]), $event->transferId . '-sender');

        $this->projectionService->upsert(array_merge($sharedAttributes, [
            'reference'     => ($source?->transfer_reference ?? $event->transferId) . '-R',
            'type'          => 'transfer',
            'amount'        => $event->grossAmount,
            'currency_code' => $event->currencyCode,
            'direction'     => 'credit',
            'wallet_id'     => $event->recipientWalletId,
            'customer_id'   => $source?->recipient_customer_id ?? '',
            'source_module' => 'e09_transfer',
            'created_at'    => $event->occurredAt,
        ]), $event->transferId . '-recipient');
    }

    public function handleFailed(TransferFailed $event): void
    {
        $source = TransferTransactionModel::find($event->transferId);

        $this->projectionService->upsert([
            'status'        => 'failed',
            'failed_at'     => $event->occurredAt,
            'updated_at'    => $event->occurredAt,
            'reference'     => ($source?->transfer_reference ?? $event->transferId) . '-S',
            'type'          => 'transfer',
            'amount'        => $event->grossAmount,
            'currency_code' => $event->currencyCode,
            'direction'     => 'debit',
            'wallet_id'     => $event->senderWalletId,
            'customer_id'   => $source?->sender_customer_id ?? '',
            'source_module' => 'e09_transfer',
            'created_at'    => $event->occurredAt,
        ], $event->transferId . '-sender');
    }

    public function handleCancelled(TransferCancelled $event): void
    {
        $source = TransferTransactionModel::find($event->transferId);

        $this->projectionService->upsert([
            'status'        => 'cancelled',
            'updated_at'    => $event->occurredAt,
            'reference'     => ($source?->transfer_reference ?? $event->transferId) . '-S',
            'type'          => 'transfer',
            'amount'        => $event->grossAmount,
            'currency_code' => $event->currencyCode,
            'direction'     => 'debit',
            'wallet_id'     => $event->senderWalletId,
            'customer_id'   => $source?->sender_customer_id ?? '',
            'source_module' => 'e09_transfer',
            'created_at'    => $event->occurredAt,
        ], $event->transferId . '-sender');
    }
}
