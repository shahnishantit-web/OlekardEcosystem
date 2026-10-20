<?php

declare(strict_types=1);

namespace App\Modules\Service\Application\Services;

use App\Modules\Fees\Domain\Contracts\FeesModuleContract;
use App\Modules\Service\Domain\Contracts\ServiceProviderAdapter;
use App\Modules\Service\Domain\Enums\ServiceStatus;
use App\Modules\Service\Domain\Enums\ServiceTransactionStatus;
use App\Modules\Service\Domain\Events\ServicePaymentFailedEvent;
use App\Modules\Service\Domain\Events\ServicePaymentInitiatedEvent;
use App\Modules\Service\Domain\Exceptions\InsufficientBalanceException;
use App\Modules\Service\Domain\Exceptions\ServiceNotAvailableException;
use App\Modules\Service\Domain\Exceptions\ServiceNotFoundException;
use App\Modules\Service\Domain\Exceptions\ServiceTransactionNotFoundException;
use App\Modules\Service\Domain\Exceptions\StepUpRequiredException;
use App\Modules\Service\Infrastructure\Models\ServiceModel;
use App\Modules\Service\Infrastructure\Models\ServiceTransactionModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class ServicePaymentService
{
    public function __construct(
        private readonly ServiceFeeService           $feeService,
        private readonly ServiceProviderAdapter      $adapter,
        private readonly LedgerModuleContract        $ledger,
        private readonly WalletModuleContract        $wallet,
        private readonly ConfigurationServiceInterface $config,
        private readonly AuditServiceInterface       $audit,
        private readonly EventPublisherInterface     $events,
        private readonly ?FeesModuleContract         $fees = null,
    ) {}

    public function initiate(
        array  $data,
        string $customerId,
        string $correlationId,
    ): ServiceTransactionModel {
        $idempotencyKey = $data['idempotency_key'];
        if ($this->wallet->ownerActorId($data['wallet_id']) !== $customerId) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Wallet is not available to this customer.');
        }
        if (! $this->wallet->isActive($data['wallet_id'])) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Wallet must be active to make a payment.');
        }

        // Idempotency replay
        $existing = ServiceTransactionModel::where('idempotency_key', $idempotencyKey)
            ->where('customer_id', $customerId)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $service = ServiceModel::where('id', (string) $data['service_id'])->first();
        if ($service === null) {
            throw new ServiceNotFoundException((string) $data['service_id']);
        }
        if ($service->status !== ServiceStatus::Active) {
            throw new ServiceNotAvailableException($data['service_id']);
        }

        // Recalculate fee and guard against drift
        $feeCalc   = $this->feeService->calculate($service, $data['amount']);
        $feeAmount = $feeCalc['fee_amount'];
        $total     = $feeCalc['total_amount'];

        if (isset($data['quoted_fee_amount'])) {
            $this->feeService->assertFeeUnchanged($service, (int) $data['quoted_fee_amount'], $data['amount']);
        }

        // Step-up threshold enforcement
        $threshold = (int) $this->config->getOrDefault('auth.step_up_threshold', 1000000);
        if ($total >= $threshold && empty($data['step_up_token'])) {
            throw new StepUpRequiredException();
        }
        if (! empty($data['step_up_token']) && ! app(\App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract::class)
            ->verifyStepUpForActor($data['step_up_token'], 'service_payment', $customerId, $data['session_id'] ?? '')) {
            throw new StepUpRequiredException();
        }

        $walletId     = $data['wallet_id'];
        $currencyCode = $service->currency_code;

        // Create initiated record before wallet/ledger operations
        $tx = DB::connection('platform')->transaction(function () use (
            $data, $customerId, $correlationId, $idempotencyKey,
            $service, $feeAmount, $total, $walletId, $currencyCode
        ) {
            return ServiceTransactionModel::create([
                'id'                 => Str::orderedUuid()->toString(),
                'service_id'         => $service->id,
                'customer_id'        => $customerId,
                'wallet_id'          => $walletId,
                'idempotency_key'    => $idempotencyKey,
                'currency_code'      => $currencyCode,
                'amount'             => $data['amount'],
                'fee_amount'         => $feeAmount,
                'total_amount'       => $total,
                'provider_code'      => $service->provider_code,
                'customer_reference' => $data['customer_reference'],
                'account_name'       => $data['account_name'] ?? '',
                'status'             => ServiceTransactionStatus::Initiated->value,
                'requery_count'      => 0,
                'review_flag'        => false,
                'correlation_id'     => $correlationId,
            ]);
        });

        Log::info('Service payment initiated', [
            'transaction_id' => $tx->id,
            'service_id'     => $service->id,
            'customer_id'    => $customerId,
            'correlation_id' => $correlationId,
            'amount'         => $data['amount'],
            'fee_amount'     => $feeAmount,
        ]);

        try {
            // Audit and event publish are inside the transaction so that the outbox
            // record is written atomically with the ledger entry and status transition.
            // OutboxEventPublisher::publish() throws LogicException if called outside
            // an active DB transaction.
            DB::connection('platform')->transaction(function () use (
                $tx, $service, $walletId, $currencyCode, $total, $correlationId,
                $feeAmount, $idempotencyKey
            ) {
                // Verify balance (optimistic — ledger enforces final guard)
                $available = $this->wallet->availableBalance($walletId, $currencyCode) ?? 0;
                if ($available < $total) {
                    throw new InsufficientBalanceException($total, $available);
                }

                // Ensure service_payable ledger account exists (idempotent)
                $servicePayableRef = $this->ensureServicePayableAccount($currencyCode);

                // Debit wallet pocket; credit service_payable (balanced journal)
                $walletPocketRef = $this->wallet->getPocketLedgerAccountRef($walletId, $currencyCode);

                $journalId = $this->ledger->postEntry([
                    'idempotency_key'       => 'service_payment:'.$tx->id,
                    'entry_type'            => 'debit',
                    'currency_code'         => $currencyCode,
                    'transaction_reference' => $tx->id,
                    'correlation_id'        => $correlationId,
                    'description'           => 'Service payment — '.$tx->id,
                    'lines'                 => [
                        [
                            'ledger_account_reference' => $walletPocketRef,
                            'direction'                => 'debit',
                            'amount'                   => $total,
                        ],
                        [
                            'ledger_account_reference' => $servicePayableRef,
                            'direction'                => 'credit',
                            'amount'                   => $total,
                        ],
                    ],
                ]);

                // Submit to provider
                $submission = $this->adapter->submitPayment(
                    providerCode:      $service->provider_code,
                    transactionId:     $tx->id,
                    customerReference: $tx->customer_reference,
                    amount:            $tx->amount,
                    currencyCode:      $currencyCode,
                    idempotencyKey:    $tx->idempotency_key,
                );

                $tx->status             = ServiceTransactionStatus::PendingProvider;
                $tx->provider_reference = $submission->providerReference;
                $tx->save();

                // E21 fee tracking — move fee portion from service_payable to E21 revenue tracking
                if ($this->fees !== null && $feeAmount > 0) {
                    $feeResult = $this->fees->calculateFee('bill_payment', $tx->amount, $currencyCode, 'global', null);
                    $this->fees->recordFee(
                        transactionId:   $tx->id,
                        amountMinor:     $feeAmount,
                        feeRuleId:       $feeResult->feeRuleId,
                        currency:        $currencyCode,
                        debitAccountRef: $servicePayableRef,
                        creditAccountRef:'revenue:fees:' . $currencyCode,
                        idempotencyKey:  Uuid::uuid5(Uuid::NAMESPACE_URL, $tx->id . ':fee')->toString(),
                        correlationId:   $correlationId,
                    );
                }

                $this->audit->record(new AuditEventData(
                    action:        'service.payment.initiated',
                    actorType:     'customer',
                    resourceType:  'service_transaction',
                    correlationId: $correlationId,
                    outcome:       'success',
                    actorId:       $tx->customer_id,
                    resourceId:    $tx->id,
                    afterState:    ['status' => $tx->status->value, 'journal_id' => $journalId],
                ));

                $this->events->publish(new ServicePaymentInitiatedEvent(
                    transactionId:     $tx->id,
                    serviceId:         $service->id,
                    customerId:        $tx->customer_id,
                    walletId:          $walletId,
                    currencyCode:      $currencyCode,
                    amount:            $tx->amount,
                    feeAmount:         $feeAmount,
                    customerReference: $tx->customer_reference,
                    idempotencyKey:    $idempotencyKey,
                    occurredAt:        now()->toIso8601String(),
                    correlationId:     $correlationId,
                ));
                if ($submission->status === 'completed') {
                    app(ServiceOutcomeService::class)->handleSuccess($tx->id, $submission->providerReference, $correlationId);
                }
            });

        } catch (InsufficientBalanceException $e) {
            Log::warning('Service payment insufficient balance', [
                'transaction_id' => $tx->id,
                'correlation_id' => $correlationId,
            ]);

            $this->audit->record(new AuditEventData(
                action:        'service.payment.insufficient_balance',
                actorType:     'customer',
                resourceType:  'service_transaction',
                correlationId: $correlationId,
                outcome:       'failure',
                actorId:       $customerId,
                resourceId:    $tx->id,
            ));

            throw $e;
        }

        $tx->refresh();
        return $tx;
    }

    public function findTransaction(string $txId, string $requestorId): ServiceTransactionModel
    {
        $tx = ServiceTransactionModel::where('id', $txId)->first();
        if ($tx === null) {
            throw new ServiceTransactionNotFoundException($txId);
        }
        if ($tx->customer_id !== $requestorId) {
            abort(403, 'Access denied.');
        }
        return $tx;
    }

    private function ensureServicePayableAccount(string $currencyCode): string
    {
        $configKey = "service.ledger.payable_account.{$currencyCode}";
        $ref       = $this->config->getOrDefault($configKey, Uuid::uuid5(Uuid::NAMESPACE_URL, "service.payable.{$currencyCode}")->toString());

        // registerAccount is idempotent — safe to call on every payment
        $this->ledger->registerAccount(
            ledgerAccountReference: (string) $ref,
            accountType:            'liability',
            currencyCode:           $currencyCode,
            ownerReference:         Uuid::uuid5(Uuid::NAMESPACE_URL, 'service.payable')->toString(),
            ownerType:              'system_revenue',
        );

        return (string) $ref;
    }
}
