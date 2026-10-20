<?php

declare(strict_types=1);

namespace App\Modules\Service\Application\Services;

use App\Modules\Service\Domain\Enums\ServiceTransactionStatus;
use App\Modules\Service\Domain\Events\ServicePaymentCompletedEvent;
use App\Modules\Service\Domain\Events\ServicePaymentFailedEvent;
use App\Modules\Service\Domain\Exceptions\ServiceTransactionNotFoundException;
use App\Modules\Service\Infrastructure\Models\ServiceTransactionModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ServiceOutcomeService
{
    public function __construct(
        private readonly ServiceReceiptService       $receiptService,
        private readonly LedgerModuleContract        $ledger,
        private readonly WalletModuleContract        $wallet,
        private readonly ConfigurationServiceInterface $config,
        private readonly AuditServiceInterface       $audit,
        private readonly EventPublisherInterface     $events,
    ) {}

    public function handleSuccess(string $txId, string $providerRef, string $correlationId): void
    {
        DB::connection('platform')->transaction(function () use ($txId, $providerRef, $correlationId) {
            $tx = $this->requireResolvable($txId);
            if ($tx === null) {
                return; // already in terminal state — idempotent no-op
            }

            $tx->status             = ServiceTransactionStatus::Completed;
            $tx->provider_reference = $providerRef;
            $tx->save();

            $this->receiptService->createReceipt($tx);

            $this->audit->record(new AuditEventData(
                action:        'service.payment.completed',
                actorType:     'system',
                resourceType:  'service_transaction',
                correlationId: $correlationId,
                outcome:       'success',
                resourceId:    $tx->id,
                afterState:    ['status' => 'completed', 'provider_reference' => $providerRef],
            ));

            $this->events->publish(new ServicePaymentCompletedEvent(
                transactionId:    $tx->id,
                serviceId:        $tx->service_id,
                customerId:       $tx->customer_id,
                walletId:         $tx->wallet_id,
                currencyCode:     $tx->currency_code,
                amount:           $tx->amount,
                feeAmount:        $tx->fee_amount,
                receiptReference: $tx->receipt?->receipt_reference ?? '',
                providerReference: $providerRef,
                occurredAt:       now()->toIso8601String(),
                correlationId:    $correlationId,
            ));
        });

        Log::info('Service payment outcome: success', ['tx_id' => $txId, 'correlation_id' => $correlationId]);
    }

    public function handleFailure(string $txId, string $reason, string $correlationId): void
    {
        DB::connection('platform')->transaction(function () use ($txId, $reason, $correlationId) {
            $tx = $this->requireResolvable($txId);
            if ($tx === null) {
                return;
            }

            // Post compensating reversal: credit wallet pocket, debit service_payable
            $this->postCompensatingEntry($tx, $correlationId, 'failure_reversal:'.$txId);

            $tx->status         = ServiceTransactionStatus::Failed;
            $tx->failure_reason = $reason;
            $tx->save();

            $this->audit->record(new AuditEventData(
                action:        'service.payment.failed',
                actorType:     'system',
                resourceType:  'service_transaction',
                correlationId: $correlationId,
                outcome:       'failure',
                resourceId:    $tx->id,
                afterState:    ['status' => 'failed', 'failure_reason' => $reason],
            ));

            $this->events->publish(new ServicePaymentFailedEvent(
                transactionId: $tx->id,
                serviceId:     $tx->service_id,
                customerId:    $tx->customer_id,
                walletId:      $tx->wallet_id,
                currencyCode:  $tx->currency_code,
                amount:        $tx->amount,
                failureReason: $reason,
                finalStatus:   'failed',
                occurredAt:    now()->toIso8601String(),
                correlationId: $correlationId,
            ));
        });

        Log::info('Service payment outcome: failure', ['tx_id' => $txId, 'reason' => $reason]);
    }

    public function handleAmbiguous(string $txId, string $correlationId): void
    {
        DB::connection('platform')->transaction(function () use ($txId, $correlationId) {
            $tx = $this->requireResolvable($txId);
            if ($tx === null) {
                return;
            }

            // Post compensating entry and flag for manual review
            $this->postCompensatingEntry($tx, $correlationId, 'ambiguous_reversal:'.$txId);

            $tx->status      = ServiceTransactionStatus::Failed;
            $tx->review_flag = true;
            $tx->save();

            $this->audit->record(new AuditEventData(
                action:        'service.payment.ambiguous',
                actorType:     'system',
                resourceType:  'service_transaction',
                correlationId: $correlationId,
                outcome:       'partial',
                resourceId:    $tx->id,
                afterState:    ['status' => 'failed', 'review_flag' => true],
            ));
        });

        Log::warning('Service payment outcome: ambiguous (review_flag set)', ['tx_id' => $txId]);
    }

    private function requireResolvable(string $txId): ?ServiceTransactionModel
    {
        $tx = ServiceTransactionModel::find($txId);
        if ($tx === null) {
            throw new ServiceTransactionNotFoundException($txId);
        }
        // Duplicate callback on already-terminal status — idempotent no-op
        if ($tx->status->isTerminal()) {
            return null;
        }
        return $tx;
    }

    private function postCompensatingEntry(
        ServiceTransactionModel $tx,
        string $correlationId,
        string $idempotencyKeySuffix,
    ): void {
        $total             = $tx->amount + $tx->fee_amount;
        $currencyCode      = $tx->currency_code;
        $walletPocketRef   = $this->wallet->getPocketLedgerAccountRef($tx->wallet_id, $currencyCode);
        $servicePayableRef = $this->config->getOrDefault(
            "service.ledger.payable_account.{$currencyCode}",
            \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, "service.payable.{$currencyCode}")->toString()
        );

        $this->ledger->postEntry([
            'idempotency_key'       => $idempotencyKeySuffix,
            'entry_type'            => 'credit',
            'currency_code'         => $currencyCode,
            'transaction_reference' => $tx->id.'_reversal',
            'correlation_id'        => $correlationId,
            'description'           => 'Service payment reversal — '.$tx->id,
            'lines'                 => [
                [
                    'ledger_account_reference' => (string) $servicePayableRef,
                    'direction'                => 'debit',
                    'amount'                   => $total,
                ],
                [
                    'ledger_account_reference' => $walletPocketRef,
                    'direction'                => 'credit',
                    'amount'                   => $total,
                ],
            ],
        ]);
    }
}
