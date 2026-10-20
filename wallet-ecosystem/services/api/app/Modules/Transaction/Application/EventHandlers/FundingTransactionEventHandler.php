<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Application\EventHandlers;

use App\Modules\Funding\Domain\Events\FundingTransactionCancelled;
use App\Modules\Funding\Domain\Events\FundingTransactionCompleted;
use App\Modules\Funding\Domain\Events\FundingTransactionFailed;
use App\Modules\Funding\Domain\Events\FundingTransactionInitiated;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use App\Modules\Transaction\Application\Services\TransactionProjectionService;
use Illuminate\Support\Facades\Log;

final class FundingTransactionEventHandler
{
    public function __construct(
        private readonly TransactionProjectionService $projectionService,
    ) {}

    public function handleInitiated(FundingTransactionInitiated $event): void
    {
        Log::withContext(['correlation_id' => $event->correlationId]);

        $source = FundingTransactionModel::find($event->fundingTransactionId);

        if ($source === null) {
            Log::warning('transaction.projection.funding.source_not_found', [
                'funding_transaction_id' => $event->fundingTransactionId,
            ]);
            return;
        }

        $this->projectionService->upsert([
            'reference'        => $source->transaction_reference,
            'type'             => 'funding',
            'status'           => $event->status,
            'amount'           => $event->amount,
            'currency_code'    => $event->currencyCode,
            'direction'        => 'credit',
            'wallet_id'        => $event->walletId,
            'customer_id'      => $source->customer_id,
            'description'      => "Wallet funding via {$event->fundingMethod}",
            'source_module'    => 'e08_funding',
            'correlation_id'   => $event->correlationId,
            'idempotency_key'  => $source->idempotency_key,
            'created_at'       => $event->occurredAt,
            'updated_at'       => $event->occurredAt,
        ], $event->fundingTransactionId);
    }

    public function handleCompleted(FundingTransactionCompleted $event): void
    {
        Log::withContext(['correlation_id' => $event->correlationId]);

        $source = FundingTransactionModel::find($event->fundingTransactionId);

        $metadata = [
            'reversal_journal_template' => [
                'amount'        => $event->amount,
                'currency_code' => $event->currencyCode,
            ],
        ];

        $attributes = [
            'status'            => $event->status,
            'ledger_journal_id' => $event->ledgerJournalId,
            'completed_at'      => $event->occurredAt,
            'metadata'          => $metadata,
            'updated_at'        => $event->occurredAt,
        ];

        if ($source !== null) {
            $attributes['reference']     = $source->transaction_reference;
            $attributes['type']          = 'funding';
            $attributes['amount']        = $event->amount;
            $attributes['currency_code'] = $event->currencyCode;
            $attributes['direction']     = 'credit';
            $attributes['wallet_id']     = $event->walletId;
            $attributes['customer_id']   = $source->customer_id;
            $attributes['description']   = 'Wallet funding completed';
            $attributes['source_module'] = 'e08_funding';
            $attributes['correlation_id'] = $event->correlationId;
            $attributes['created_at']    = $event->occurredAt;
        }

        $this->projectionService->upsert($attributes, $event->fundingTransactionId);
    }

    public function handleFailed(FundingTransactionFailed $event): void
    {
        $source = FundingTransactionModel::find($event->fundingTransactionId);

        $this->projectionService->upsert([
            'status'        => $event->status,
            'failed_at'     => $event->occurredAt,
            'updated_at'    => $event->occurredAt,
            'reference'     => $source?->transaction_reference ?? '',
            'type'          => 'funding',
            'amount'        => $event->amount,
            'currency_code' => $event->currencyCode,
            'direction'     => 'credit',
            'wallet_id'     => $event->walletId,
            'customer_id'   => $source?->customer_id ?? '',
            'source_module' => 'e08_funding',
            'created_at'    => $event->occurredAt,
        ], $event->fundingTransactionId);
    }

    public function handleCancelled(FundingTransactionCancelled $event): void
    {
        $source = FundingTransactionModel::find($event->fundingTransactionId);

        $this->projectionService->upsert([
            'status'        => $event->status,
            'updated_at'    => $event->occurredAt,
            'reference'     => $source?->transaction_reference ?? '',
            'type'          => 'funding',
            'amount'        => $event->amount,
            'currency_code' => $event->currencyCode,
            'direction'     => 'credit',
            'wallet_id'     => $event->walletId,
            'customer_id'   => $source?->customer_id ?? '',
            'source_module' => 'e08_funding',
            'created_at'    => $event->occurredAt,
        ], $event->fundingTransactionId);
    }
}
