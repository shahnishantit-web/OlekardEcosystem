<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Application\EventHandlers;

use App\Modules\Service\Domain\Events\ServicePaymentCompletedEvent;
use App\Modules\Service\Domain\Events\ServicePaymentFailedEvent;
use App\Modules\Service\Domain\Events\ServicePaymentInitiatedEvent;
use App\Modules\Service\Infrastructure\Models\ServiceTransactionModel;
use App\Modules\Transaction\Application\Services\TransactionProjectionService;
use Illuminate\Support\Facades\Log;

final class ServiceTransactionEventHandler
{
    public function __construct(
        private readonly TransactionProjectionService $projectionService,
    ) {}

    public function handleInitiated(ServicePaymentInitiatedEvent $event): void
    {
        $source = ServiceTransactionModel::find($event->transactionId);

        if ($source === null) {
            Log::warning('transaction.projection.service.source_not_found', [
                'service_transaction_id' => $event->transactionId,
            ]);
            return;
        }

        $this->projectionService->upsert([
            'reference'       => $source->id,
            'type'            => 'service_payment',
            'status'          => 'initiated',
            'amount'          => $event->amount + $event->feeAmount,
            'currency_code'   => $event->currencyCode,
            'direction'       => 'debit',
            'wallet_id'       => $event->walletId,
            'customer_id'     => $event->customerId,
            'description'     => "Service payment initiated — {$event->transactionId}",
            'source_module'   => 'e15_service',
            'correlation_id'  => $event->correlationId,
            'idempotency_key' => $event->idempotencyKey,
            'created_at'      => $event->occurredAt,
            'updated_at'      => $event->occurredAt,
        ], $event->transactionId);
    }

    public function handleCompleted(ServicePaymentCompletedEvent $event): void
    {
        $source = ServiceTransactionModel::find($event->transactionId);

        if ($source === null) {
            Log::warning('transaction.projection.service.source_not_found_on_complete', [
                'service_transaction_id' => $event->transactionId,
            ]);
            return;
        }

        $this->projectionService->upsert([
            'reference'       => $source->id,
            'type'            => 'service_payment',
            'status'          => 'completed',
            'amount'          => $event->amount + $event->feeAmount,
            'currency_code'   => $event->currencyCode,
            'direction'       => 'debit',
            'wallet_id'       => $event->walletId,
            'customer_id'     => $event->customerId,
            'description'     => "Service payment completed — {$event->receiptReference}",
            'source_module'   => 'e15_service',
            'correlation_id'  => $event->correlationId,
            'idempotency_key' => $source->idempotency_key,
            'created_at'      => $event->occurredAt,
            'updated_at'      => $event->occurredAt,
        ], $event->transactionId);
    }

    public function handleFailed(ServicePaymentFailedEvent $event): void
    {
        $source = ServiceTransactionModel::find($event->transactionId);

        if ($source === null) {
            Log::warning('transaction.projection.service.source_not_found_on_failure', [
                'service_transaction_id' => $event->transactionId,
            ]);
            return;
        }

        $this->projectionService->upsert([
            'reference'       => $source->id,
            'type'            => 'service_payment',
            'status'          => 'failed',
            'amount'          => $event->amount,
            'currency_code'   => $event->currencyCode,
            'direction'       => 'debit',
            'wallet_id'       => $event->walletId,
            'customer_id'     => $event->customerId,
            'description'     => "Service payment failed — {$event->failureReason}",
            'source_module'   => 'e15_service',
            'correlation_id'  => $event->correlationId,
            'idempotency_key' => $source->idempotency_key,
            'created_at'      => $event->occurredAt,
            'updated_at'      => $event->occurredAt,
        ], $event->transactionId);
    }
}
