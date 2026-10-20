<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Application\EventHandlers;

use App\Modules\Transaction\Application\Services\TransactionProjectionService;
use App\Modules\Withdrawal\Domain\Events\WithdrawalCancelled;
use App\Modules\Withdrawal\Domain\Events\WithdrawalCompleted;
use App\Modules\Withdrawal\Domain\Events\WithdrawalFailed;
use App\Modules\Withdrawal\Domain\Events\WithdrawalInitiated;
use App\Modules\Withdrawal\Domain\Events\WithdrawalProcessing;
use App\Modules\Withdrawal\Infrastructure\Models\WithdrawalTransactionModel;
use Illuminate\Support\Facades\Log;

final class WithdrawalTransactionEventHandler
{
    public function __construct(
        private readonly TransactionProjectionService $projectionService,
    ) {}

    public function handleInitiated(WithdrawalInitiated $event): void
    {
        Log::withContext(['correlation_id' => $event->correlationId]);

        $source = WithdrawalTransactionModel::find($event->withdrawalTransactionId);

        $this->projectionService->upsert([
            'reference'       => $event->transactionReference,
            'type'            => 'withdrawal',
            'status'          => 'pending',
            'amount'          => $event->grossAmount,
            'currency_code'   => $event->currencyCode,
            'direction'       => 'debit',
            'wallet_id'       => $event->walletId,
            'customer_id'     => $source?->customer_id ?? '',
            'description'     => "Withdrawal via {$event->channel}",
            'source_module'   => 'e10_withdrawal',
            'correlation_id'  => $event->correlationId,
            'idempotency_key' => $source?->idempotency_key,
            'created_at'      => $event->occurredAt,
            'updated_at'      => $event->occurredAt,
        ], $event->withdrawalTransactionId);
    }

    public function handleProcessing(WithdrawalProcessing $event): void
    {
        $this->projectionService->upsert([
            'status'     => 'processing',
            'updated_at' => $event->occurredAt,
        ], $event->withdrawalTransactionId);
    }

    public function handleCompleted(WithdrawalCompleted $event): void
    {
        Log::withContext(['correlation_id' => $event->correlationId]);

        $source = WithdrawalTransactionModel::find($event->withdrawalTransactionId);

        $metadata = [
            'reversal_journal_template' => [
                'amount'            => $event->grossAmount,
                'currency_code'     => $event->currencyCode,
                'ledger_journal_id' => $event->ledgerJournalId,
            ],
        ];

        $this->projectionService->upsert([
            'reference'         => $source?->transaction_reference ?? '',
            'type'              => 'withdrawal',
            'status'            => 'completed',
            'amount'            => $event->grossAmount,
            'currency_code'     => $event->currencyCode,
            'direction'         => 'debit',
            'wallet_id'         => $event->walletId,
            'customer_id'       => $source?->customer_id ?? '',
            'description'       => "Withdrawal via {$event->channel} completed",
            'source_module'     => 'e10_withdrawal',
            'ledger_journal_id' => $event->ledgerJournalId,
            'metadata'          => $metadata,
            'completed_at'      => $event->occurredAt,
            'correlation_id'    => $event->correlationId,
            'created_at'        => $event->occurredAt,
            'updated_at'        => $event->occurredAt,
        ], $event->withdrawalTransactionId);
    }

    public function handleFailed(WithdrawalFailed $event): void
    {
        $source = WithdrawalTransactionModel::find($event->withdrawalTransactionId);

        $this->projectionService->upsert([
            'status'        => 'failed',
            'failed_at'     => $event->occurredAt,
            'updated_at'    => $event->occurredAt,
            'reference'     => $source?->transaction_reference ?? '',
            'type'          => 'withdrawal',
            'amount'        => $event->grossAmount,
            'currency_code' => $event->currencyCode,
            'direction'     => 'debit',
            'wallet_id'     => $event->walletId,
            'customer_id'   => $source?->customer_id ?? '',
            'source_module' => 'e10_withdrawal',
            'created_at'    => $event->occurredAt,
        ], $event->withdrawalTransactionId);
    }

    public function handleCancelled(WithdrawalCancelled $event): void
    {
        $source = WithdrawalTransactionModel::find($event->withdrawalTransactionId);

        $this->projectionService->upsert([
            'status'        => 'cancelled',
            'updated_at'    => $event->occurredAt,
            'reference'     => $source?->transaction_reference ?? '',
            'type'          => 'withdrawal',
            'amount'        => $event->grossAmount,
            'currency_code' => $event->currencyCode,
            'direction'     => 'debit',
            'wallet_id'     => $event->walletId,
            'customer_id'   => $source?->customer_id ?? '',
            'source_module' => 'e10_withdrawal',
            'created_at'    => $event->occurredAt,
        ], $event->withdrawalTransactionId);
    }
}
