<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry;
use App\Modules\Payment\Domain\Contracts\PaymentAdapterInterface;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use App\Modules\Payment\Domain\Events\PaymentCircuitOpenEvent;
use App\Modules\Payment\Domain\Events\PaymentConfirmedEvent;
use App\Modules\Payment\Domain\Events\PaymentFailedEvent;
use App\Modules\Payment\Domain\Events\PaymentReversedEvent;
use App\Modules\Payment\Domain\Exceptions\DuplicateAttemptException;
use App\Modules\Payment\Domain\Exceptions\ProviderCircuitOpenException;
use App\Modules\Payment\Domain\Exceptions\InvalidStateTransitionException;
use App\Modules\Payment\Domain\Exceptions\PaymentIntentNotFoundException;
use App\Modules\Payment\Domain\StateMachines\PaymentAttemptStateMachine;
use App\Modules\Payment\Domain\StateMachines\PaymentIntentStateMachine;
use App\Modules\Payment\Domain\ValueObjects\PaymentAttemptResult;
use App\Modules\Payment\Domain\ValueObjects\PaymentInitiateRequest;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class PaymentOrchestrationService
{
    public function __construct(
        private readonly PaymentIntentRepositoryInterface  $intentRepository,
        private readonly PaymentAttemptRepositoryInterface $attemptRepository,
        private readonly BusinessPurposeResolverRegistry   $purposeRegistry,
        private readonly WalletModuleContract              $wallet,
        private readonly LedgerModuleContract              $ledger,
        private readonly ConfigurationServiceInterface     $config,
        private readonly AuditServiceInterface             $audit,
        private readonly EventPublisherInterface           $events,
        private readonly Container                         $container,
    ) {
    }

    /**
     * T079: Create a PaymentIntent from a business purpose.
     *
     * @param array{
     *   purpose_type: string,
     *   purpose_reference: string,
     *   organisation_id: string,
     *   payer_actor_id: string,
     *   currency_code: string,
     *   idempotency_key: string,
     *   correlation_id: string,
     *   metadata?: array<string, mixed>,
     * } $request
     * @return array{id: string, status: string, amount_minor: int, currency_code: string, expires_at: string}
     */
    public function createIntent(array $request): array
    {
        $idempotencyKey  = $request['idempotency_key'];
        $payerActorId    = $request['payer_actor_id'];
        $purposeType     = $request['purpose_type'];
        $purposeRef      = $request['purpose_reference'];
        $organisationId  = $request['organisation_id'];
        $currencyCode    = $request['currency_code'];
        $correlationId   = $request['correlation_id'];

        // Idempotency replay — return existing if same key+payer
        $existing = $this->intentRepository->findByIdempotencyKey($idempotencyKey, $payerActorId);
        if ($existing !== null) {
            return $this->intentShape($existing);
        }

        // Resolve server-authoritative amount — NEVER trust caller-supplied amount
        $resolver   = $this->purposeRegistry->resolverFor($purposeType);
        $obligation = $resolver->resolve($purposeRef, $payerActorId, $correlationId);

        // Guard against duplicate active intent for same purpose+org
        $active = $this->intentRepository->findActiveForPurpose($purposeType, $purposeRef);
        foreach ($active as $candidate) {
            if ($candidate->organisation_id === $organisationId) {
                return $this->intentShape($candidate);
            }
        }

        $ttlMinutes = (int) ($this->config->getOrDefault('payment.intent_ttl_minutes', 30) ?? 30);
        $expiresAt  = now()->addMinutes($ttlMinutes);
        $intentId   = (string) Uuid::uuid7();

        $intent = new PaymentIntentModel();
        $intent->id               = $intentId;
        $intent->purpose_type     = $purposeType;
        $intent->purpose_reference = $purposeRef;
        $intent->organisation_id  = $obligation->organisationId;
        $intent->payer_actor_id   = $obligation->payerActorId;
        $intent->currency_code    = $currencyCode;
        $intent->amount_minor     = $obligation->amountMinor; // always int, server-resolved
        $intent->status           = PaymentIntentStatus::Pending;
        $intent->idempotency_key  = $idempotencyKey;
        $intent->correlation_id   = $correlationId;
        $intent->metadata         = $request['metadata'] ?? [];
        $intent->expires_at       = $expiresAt;

        DB::connection('platform')->transaction(function () use ($intent, $correlationId, $purposeType, $purposeRef): void {
            $this->intentRepository->save($intent);

            $this->audit->record(new AuditEventData(
                action:        'payment.intent.created',
                actorId:       $intent->payer_actor_id,
                actorType:     'customer',
                resourceType:  'payment_intent',
                resourceId:    $intent->id,
                correlationId: $correlationId,
                outcome:       'success',
                afterState:    [
                    'status'         => $intent->status->value,
                    'amount_minor'   => $intent->amount_minor,
                    'currency_code'  => $intent->currency_code,
                    'purpose_type'   => $purposeType,
                    'purpose_reference' => $purposeRef,
                ],
            ));
        });

        return $this->intentShape($intent);
    }

    /**
     * T080: Initiate a payment attempt against a specific provider.
     *
     * @param array{
     *   payment_intent_id: string,
     *   provider_code: string,
     *   payer_actor_id: string,
     *   idempotency_key: string,
     *   correlation_id: string,
     *   wallet_id?: string,
     *   step_up_token?: string,
     * } $request
     * @return array{attempt_id: string, status: string, next_action_type: string|null, next_action_data: array<string, mixed>|null}
     */
    public function initiateAttempt(array $request): array
    {
        $intentId      = $request['payment_intent_id'];
        $providerCode  = $request['provider_code'];
        $payerActorId  = $request['payer_actor_id'];
        $idempotencyKey = $request['idempotency_key'];
        $correlationId = $request['correlation_id'];

        // Idempotency replay
        $existingAttempt = $this->attemptRepository->findByIdempotencyKey($idempotencyKey);
        if ($existingAttempt !== null) {
            return $this->attemptShape($existingAttempt);
        }

        $intent = $this->intentRepository->findById($intentId, lock: true);
        if ($intent === null) {
            throw new PaymentIntentNotFoundException($intentId);
        }

        // Guard: no duplicate active attempt
        $activeAttempt = $this->attemptRepository->findActiveForIntent($intentId);
        if ($activeAttempt !== null) {
            throw new DuplicateAttemptException($intentId);
        }

        // Validate intent state can transition to processing
        PaymentIntentStateMachine::assertValidTransition($intent->status, PaymentIntentStatus::Processing);

        $adapter = $this->resolveAdapter($providerCode);

        // T151: Circuit breaker — block if provider has too many consecutive failures
        $this->assertCircuitClosed($providerCode, $correlationId);

        $attemptId = (string) Uuid::uuid7();
        $timeoutMinutes = (int) ($this->config->getOrDefault('payment.attempt_timeout_minutes', 15) ?? 15);

        $initiateRequest = new PaymentInitiateRequest(
            paymentIntentId:    $intentId,
            paymentAttemptId:   $attemptId,
            amountMinor:        $intent->amount_minor,
            currencyCode:       $intent->currency_code,
            payerActorId:       $payerActorId,
            correlationId:      $correlationId,
            idempotencyKey:     $idempotencyKey,
            walletId:           $request['wallet_id'] ?? null,
            stepUpToken:        $request['step_up_token'] ?? null,
            sessionId:          $request['session_id'] ?? null,
        );

        // Call adapter — outside DB transaction intentionally; hold/network call first
        try {
            $result = $adapter->initiatePayment($initiateRequest);
            $this->recordCircuitSuccess($providerCode);
        } catch (\Throwable $e) {
            $this->recordCircuitFailure($providerCode, $correlationId);
            throw $e;
        }

        DB::connection('platform')->transaction(function () use (
            $intent, $attemptId, $providerCode, $idempotencyKey, $correlationId,
            $result, $timeoutMinutes, $request
        ): void {
            // Transition intent to processing
            $intent->status = PaymentIntentStatus::Processing;
            $this->intentRepository->save($intent);

            // Create attempt record
            $attempt = new PaymentAttemptModel();
            $attempt->id                  = $attemptId;
            $attempt->payment_intent_id   = $intent->id;
            $attempt->provider_code       = $providerCode;
            $attempt->provider_account_id = $request['provider_account_id'] ?? null;
            $attempt->status              = $result->status;
            $attempt->idempotency_key     = $idempotencyKey;
            $attempt->provider_reference_id = $result->providerReferenceId;
            $attempt->wallet_hold_id      = $result->walletHoldId;
            $attempt->ledger_journal_id   = $result->ledgerJournalId;
            $attempt->next_action_type    = $result->nextActionType;
            $attempt->next_action_data    = $result->nextActionData;
            $attempt->fee_minor           = $result->feeMinor;
            $attempt->initiated_at        = now();
            $attempt->timeout_at          = now()->addMinutes($timeoutMinutes);
            $this->attemptRepository->save($attempt);

            $this->audit->record(new AuditEventData(
                action:        'payment.attempt.initiated',
                actorId:       $intent->payer_actor_id,
                actorType:     'customer',
                resourceType:  'payment_attempt',
                resourceId:    $attemptId,
                correlationId: $correlationId,
                outcome:       'success',
                afterState:    ['status' => $result->status->value, 'provider_code' => $providerCode],
            ));
        });

        // Schedule timeout job after the DB write commits
        \App\Modules\Payment\Application\Jobs\PaymentAttemptTimeoutJob::dispatch($attemptId)
            ->delay(now()->addMinutes($timeoutMinutes));

        return $this->attemptShape(
            $this->attemptRepository->findByIdempotencyKey($idempotencyKey) ?? new PaymentAttemptModel(['id' => $attemptId, 'status' => $result->status])
        );
    }

    /**
     * T081: Handle the outcome of a payment attempt (from callback or polling).
     *
     * @param array{
     *   payment_intent_id: string,
     *   payment_attempt_id: string,
     *   outcome: 'succeeded'|'failed'|'cancelled',
     *   failure_reason?: string,
     *   provider_reference_id?: string,
     *   fee_minor?: int,
     *   correlation_id: string,
     * } $request
     */
    public function handleOutcome(array $request): void
    {
        $intentId    = $request['payment_intent_id'];
        $attemptId   = $request['payment_attempt_id'];
        $outcome     = $request['outcome'];
        $correlationId = $request['correlation_id'];
        $feeMinor    = (int) ($request['fee_minor'] ?? 0);

        DB::connection('platform')->transaction(function () use (
            $intentId, $attemptId, $outcome, $correlationId, $feeMinor, $request
        ): void {
            $intent  = $this->intentRepository->findById($intentId, lock: true);
            if ($intent === null) {
                throw new PaymentIntentNotFoundException($intentId);
            }

            $attempt = $this->attemptRepository->findById($attemptId);
            if ($attempt === null) {
                return;
            }

            // Map outcome to attempt status
            $newAttemptStatus = match ($outcome) {
                'succeeded' => PaymentAttemptStatus::Succeeded,
                'failed'    => PaymentAttemptStatus::Failed,
                'cancelled' => PaymentAttemptStatus::Cancelled,
                default     => PaymentAttemptStatus::Failed,
            };

            // Validate state machine transitions
            PaymentAttemptStateMachine::assertValidTransition($attempt->status, $newAttemptStatus);

            $newIntentStatus = match ($outcome) {
                'succeeded' => PaymentIntentStatus::Succeeded,
                'failed'    => PaymentIntentStatus::Failed,
                'cancelled' => PaymentIntentStatus::Cancelled,
                default     => PaymentIntentStatus::Failed,
            };

            PaymentIntentStateMachine::assertValidTransition($intent->status, $newIntentStatus);

            // Update attempt
            $attempt->status              = $newAttemptStatus;
            $attempt->fee_minor           = $feeMinor;
            $attempt->provider_reference_id = $request['provider_reference_id'] ?? $attempt->provider_reference_id;

            if ($outcome === 'succeeded') {
                $attempt->confirmed_at = now();

                // Post ledger debit via LedgerModuleContract for wallet payments (T081)
                // Wallet adapter sets up hold; orchestrator posts the debit journal
                if ($attempt->wallet_hold_id !== null && $attempt->ledger_journal_id === null) {
                    $walletId   = null; // resolved from hold reference if needed by ledger
                    $journalId  = $this->ledger->postEntry([
                        'idempotency_key'       => 'payment:'.$attemptId.':debit',
                        'entry_type'            => 'debit',
                        'currency_code'         => $intent->currency_code,
                        'transaction_reference' => $attemptId,
                        'correlation_id'        => $correlationId,
                        'description'           => 'Payment debit — '.$intent->purpose_type.':'.$intent->purpose_reference,
                        'lines'                 => [
                            [
                                'ledger_account_reference' => 'payment.payable.'.$intent->currency_code,
                                'direction'                => 'credit',
                                'amount'                   => $intent->amount_minor,
                            ],
                        ],
                    ]);
                    $attempt->ledger_journal_id = $journalId;
                }
            } elseif ($outcome === 'failed') {
                $attempt->failed_at     = now();
                $attempt->failure_reason = $request['failure_reason'] ?? 'unknown';
            }

            $this->attemptRepository->save($attempt);

            // Update intent
            $intent->status = $newIntentStatus;
            if ($outcome === 'succeeded') {
                $intent->succeeded_at = now();
            } elseif ($outcome === 'failed') {
                $intent->failed_at = now();
            } elseif ($outcome === 'cancelled') {
                $intent->cancelled_at = now();
            }
            $this->intentRepository->save($intent);

            $this->audit->record(new AuditEventData(
                action:        'payment.outcome.'.$outcome,
                actorId:       $intent->payer_actor_id,
                actorType:     'customer',
                resourceType:  'payment_intent',
                resourceId:    $intentId,
                correlationId: $correlationId,
                outcome:       $outcome,
                afterState:    [
                    'intent_status'  => $newIntentStatus->value,
                    'attempt_status' => $newAttemptStatus->value,
                    'fee_minor'      => $feeMinor,
                ],
            ));

            // Publish outbox event — must be inside this DB::connection('platform') transaction
            if ($outcome === 'succeeded') {
                $this->events->publish(new PaymentConfirmedEvent(
                    paymentIntentId:  $intentId,
                    paymentAttemptId: $attemptId,
                    purposeType:      $intent->purpose_type,
                    purposeReference: $intent->purpose_reference,
                    organisationId:   $intent->organisation_id,
                    payerActorId:     $intent->payer_actor_id,
                    amountMinor:      $intent->amount_minor,
                    currencyCode:     $intent->currency_code,
                    providerCode:     $attempt->provider_code,
                    feeMinor:         $feeMinor,
                    correlationId:    $correlationId,
                    occurredAt:       now()->toIso8601String(),
                ));
            } else {
                $failureReason = ($outcome === 'failed')
                    ? ($request['failure_reason'] ?? 'unknown')
                    : 'payment_cancelled';

                $this->events->publish(new PaymentFailedEvent(
                    paymentIntentId:  $intentId,
                    purposeType:      $intent->purpose_type,
                    purposeReference: $intent->purpose_reference,
                    organisationId:   $intent->organisation_id,
                    payerActorId:     $intent->payer_actor_id,
                    failureReason:    $failureReason,
                    correlationId:    $correlationId,
                    occurredAt:       now()->toIso8601String(),
                ));
            }
        });
    }

    /**
     * T082: Expire an intent that has passed its TTL.
     */
    public function expireIntent(string $intentId, string $correlationId): void
    {
        DB::connection('platform')->transaction(function () use ($intentId, $correlationId): void {
            $intent = $this->intentRepository->findById($intentId, lock: true);
            if ($intent === null || $intent->status->isTerminal()) {
                return;
            }

            PaymentIntentStateMachine::assertValidTransition($intent->status, PaymentIntentStatus::Expired);

            // Release wallet hold if attempt has one
            $activeAttempt = $this->attemptRepository->findActiveForIntent($intentId);
            if ($activeAttempt !== null && $activeAttempt->wallet_hold_id !== null) {
                $this->wallet->releaseHold($activeAttempt->wallet_hold_id, $correlationId);

                $activeAttempt->status   = PaymentAttemptStatus::Expired;
                $activeAttempt->failed_at = now();
                $this->attemptRepository->save($activeAttempt);
            }

            $intent->status   = PaymentIntentStatus::Expired;
            $intent->failed_at = now();
            $this->intentRepository->save($intent);

            $this->audit->record(new AuditEventData(
                action:        'payment.intent.expired',
                actorId:       $intent->payer_actor_id,
                actorType:     'system',
                resourceType:  'payment_intent',
                resourceId:    $intentId,
                correlationId: $correlationId,
                outcome:       'expired',
                afterState:    ['status' => PaymentIntentStatus::Expired->value],
            ));

            $this->events->publish(new PaymentFailedEvent(
                paymentIntentId:  $intentId,
                purposeType:      $intent->purpose_type,
                purposeReference: $intent->purpose_reference,
                organisationId:   $intent->organisation_id,
                payerActorId:     $intent->payer_actor_id,
                failureReason:    'intent_expired',
                correlationId:    $correlationId,
                occurredAt:       now()->toIso8601String(),
            ));
        });
    }

    /**
     * Cancel an active attempt (e.g., user-initiated cancellation).
     *
     * @param array{
     *   payment_intent_id: string,
     *   payment_attempt_id: string,
     *   actor_id: string,
     *   correlation_id: string,
     * } $request
     */
    public function cancelAttempt(array $request): void
    {
        $intentId    = $request['payment_intent_id'];
        $attemptId   = $request['payment_attempt_id'];
        $actorId     = $request['actor_id'];
        $correlationId = $request['correlation_id'];

        DB::connection('platform')->transaction(function () use (
            $intentId, $attemptId, $actorId, $correlationId
        ): void {
            $intent  = $this->intentRepository->findById($intentId, lock: true);
            if ($intent === null) {
                throw new PaymentIntentNotFoundException($intentId);
            }

            $attempt = $this->attemptRepository->findById($attemptId);
            if ($attempt === null || $attempt->status->isTerminal()) {
                return;
            }

            PaymentAttemptStateMachine::assertValidTransition($attempt->status, PaymentAttemptStatus::Cancelled);
            PaymentIntentStateMachine::assertValidTransition($intent->status, PaymentIntentStatus::Cancelled);

            // Release wallet hold if present
            if ($attempt->wallet_hold_id !== null) {
                $this->wallet->releaseHold($attempt->wallet_hold_id, $correlationId);

                // Publish reversal event if a hold was in place
                $this->events->publish(new PaymentReversedEvent(
                    paymentIntentId:  $intentId,
                    paymentAttemptId: $attemptId,
                    purposeType:      $intent->purpose_type,
                    purposeReference: $intent->purpose_reference,
                    organisationId:   $intent->organisation_id,
                    amountMinor:      $intent->amount_minor,
                    currencyCode:     $intent->currency_code,
                    correlationId:    $correlationId,
                    occurredAt:       now()->toIso8601String(),
                ));
            }

            $attempt->status = PaymentAttemptStatus::Cancelled;
            $this->attemptRepository->save($attempt);

            $intent->status      = PaymentIntentStatus::Cancelled;
            $intent->cancelled_at = now();
            $this->intentRepository->save($intent);

            $this->audit->record(new AuditEventData(
                action:        'payment.attempt.cancelled',
                actorId:       $actorId,
                actorType:     'customer',
                resourceType:  'payment_attempt',
                resourceId:    $attemptId,
                correlationId: $correlationId,
                outcome:       'cancelled',
                afterState:    ['status' => PaymentAttemptStatus::Cancelled->value],
            ));
        });
    }

    /** @return array{id: string, status: string, amount_minor: int, currency_code: string, expires_at: string} */
    private function intentShape(PaymentIntentModel $intent): array
    {
        return [
            'id'            => $intent->id,
            'status'        => $intent->status->value,
            'amount_minor'  => $intent->amount_minor,
            'currency_code' => $intent->currency_code,
            'expires_at'    => $intent->expires_at?->toIso8601String() ?? '',
        ];
    }

    /** @return array{attempt_id: string, status: string, next_action_type: string|null, next_action_data: array<string, mixed>|null} */
    private function attemptShape(PaymentAttemptModel $attempt): array
    {
        return [
            'attempt_id'       => $attempt->id,
            'status'           => $attempt->status instanceof PaymentAttemptStatus
                ? $attempt->status->value
                : (string) $attempt->status,
            'next_action_type' => $attempt->next_action_type,
            'next_action_data' => $attempt->next_action_data,
        ];
    }

    private function resolveAdapter(string $providerCode): PaymentAdapterInterface
    {
        $key = 'payment.adapter.'.$providerCode;

        if (! $this->container->bound($key)) {
            throw new \DomainException("No payment adapter registered for provider '{$providerCode}'.");
        }

        /** @var PaymentAdapterInterface */
        return $this->container->make($key);
    }

    // ─── T151: Circuit Breaker ────────────────────────────────────────────────

    private function assertCircuitClosed(string $providerCode, string $correlationId): void
    {
        $resetMinutes = (int) $this->config->getOrDefault('payment.circuit_breaker_reset_minutes', 10);

        if (Cache::has($this->circuitOpenKey($providerCode))) {
            throw new ProviderCircuitOpenException($providerCode, $resetMinutes);
        }
    }

    private function recordCircuitSuccess(string $providerCode): void
    {
        Cache::forget($this->circuitFailureKey($providerCode));
    }

    private function recordCircuitFailure(string $providerCode, string $correlationId): void
    {
        $threshold    = (int) $this->config->getOrDefault('payment.circuit_breaker_threshold', 5);
        $resetMinutes = (int) $this->config->getOrDefault('payment.circuit_breaker_reset_minutes', 10);
        $failureKey   = $this->circuitFailureKey($providerCode);

        $failures = (int) Cache::get($failureKey, 0) + 1;
        Cache::put($failureKey, $failures, now()->addMinutes($resetMinutes));

        if ($failures >= $threshold) {
            Cache::put($this->circuitOpenKey($providerCode), true, now()->addMinutes($resetMinutes));
            $this->events->publish(new PaymentCircuitOpenEvent(
                providerCode:        $providerCode,
                consecutiveFailures: $failures,
                resetMinutes:        $resetMinutes,
                correlationId:       $correlationId,
            ));
        }
    }

    private function circuitFailureKey(string $providerCode): string
    {
        return "payment.circuit.{$providerCode}.failures";
    }

    private function circuitOpenKey(string $providerCode): string
    {
        return "payment.circuit.{$providerCode}.open";
    }
}
