<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application;

use App\Modules\Fees\Domain\Contracts\FeesModuleContract;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Payment\Application\Services\BusinessPurposeResolverRegistry;
use App\Modules\Payment\Application\Services\QuoteService;
use App\Modules\Payment\Domain\Contracts\PaymentProviderRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentQuoteRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Application\Services\ReconciliationExceptionService;
use App\Modules\Payment\Domain\Enums\PaymentAccountingStatus;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Enums\PaymentFulfilmentStatus;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use App\Modules\Payment\Domain\Enums\ReconciliationExceptionType;
use App\Modules\Payment\Domain\Events\PaymentCircuitOpenEvent;
use App\Modules\Payment\Domain\Events\PaymentConfirmedEvent;
use App\Modules\Payment\Domain\Events\PaymentFailedEvent;
use App\Modules\Payment\Domain\Events\PaymentReversedEvent;
use App\Modules\Payment\Domain\Exceptions\ConcurrentModificationException;
use App\Modules\Payment\Domain\Exceptions\DuplicateAttemptException;
use App\Modules\Payment\Domain\Exceptions\ProviderCircuitOpenException;
use App\Modules\Payment\Domain\Exceptions\InvalidStateTransitionException;
use App\Modules\Payment\Domain\Traits\DeadlockRetryTrait;
use App\Modules\Payment\Domain\Exceptions\PaymentIntentNotFoundException;
use App\Modules\Payment\Domain\Exceptions\PurposeNoLongerPayableException;
use App\Modules\Payment\Domain\Exceptions\PurposeNotPayableException;
use App\Modules\Payment\Domain\Exceptions\QuoteStaleConfirmRequiredException;
use App\Modules\Payment\Domain\Exceptions\UnsafeProviderSwitchException;
use App\Modules\Payment\Domain\StateMachines\PaymentAttemptStateMachine;
use App\Modules\Payment\Domain\StateMachines\PaymentIntentStateMachine;
use App\Modules\Payment\Domain\ValueObjects\PaymentAttemptResult;
use App\Modules\Payment\Domain\ValueObjects\PaymentInitiateRequest;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Payment\Infrastructure\Models\PaymentQuoteModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class PaymentOrchestrationService implements \App\Modules\Payment\Domain\Contracts\PaymentOutcomeProcessor
{
    use DeadlockRetryTrait;

    public function __construct(
        private readonly PaymentIntentRepositoryInterface    $intentRepository,
        private readonly PaymentAttemptRepositoryInterface   $attemptRepository,
        private readonly BusinessPurposeResolverRegistry     $purposeRegistry,
        private readonly WalletModuleContract                $wallet,
        private readonly LedgerModuleContract                $ledger,
        private readonly ConfigurationServiceInterface       $config,
        private readonly AuditServiceInterface               $audit,
        private readonly EventPublisherInterface             $events,
        private readonly Container                           $container,
        private readonly ?QuoteService                       $quoteService          = null,
        private readonly ?FeesModuleContract                 $fees                  = null,
        private readonly ?PaymentQuoteRepositoryInterface    $quoteRepository       = null,
        private readonly ?PaymentProviderRepositoryInterface $providerRepository    = null,
        private readonly ?ReconciliationExceptionService     $reconciliationService = null,
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
     * @return array{id: string, status: string, accounting_status: ?string, fulfilment_status: ?string, amount_minor: int, currency_code: string, expires_at: string, quote?: array<string, mixed>}
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

        // Validate enriched obligation: expiry and actor ownership (FR-03, FR-04)
        if ($obligation->expiresAt !== null && $obligation->expiresAt < now()) {
            throw new PurposeNotPayableException($purposeType, $purposeRef, 'Purpose has expired.');
        }
        if ($obligation->payerActorId !== $payerActorId) {
            throw new PurposeNotPayableException($purposeType, $purposeRef, 'Actor does not own this purpose.');
        }

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
        $intent->accounting_status  = PaymentAccountingStatus::PendingLedger;
        $intent->fulfilment_status  = PaymentFulfilmentStatus::Pending;
        $intent->idempotency_key  = $idempotencyKey;
        $intent->correlation_id   = $correlationId;
        $intent->metadata         = $request['metadata'] ?? [];
        $intent->expires_at       = $expiresAt;

        // Compute fee and build quote (P04)
        $quote = null;
        $providerCode = $request['provider_code'] ?? 'wallet';
        if ($this->fees !== null && $this->quoteService !== null) {
            $feeResult      = $this->fees->calculateFee('payment', $obligation->amountMinor, $currencyCode, 'customer', $payerActorId);
            $feeRuleDetails = $this->quoteService->fetchFeeRuleDetails($feeResult);
        }

        DB::connection('payment')->transaction(function () use ($intent, $correlationId, $purposeType, $purposeRef, $obligation, $providerCode, &$quote): void {
            $this->intentRepository->save($intent);

            // Create immutable quote with fee snapshot (P04 FR-05)
            if ($this->quoteService !== null && $this->fees !== null) {
                $feeResult      = $this->fees->calculateFee('payment', $obligation->amountMinor, $intent->currency_code, 'customer', $intent->payer_actor_id);
                $feeRuleDetails = $this->quoteService->fetchFeeRuleDetails($feeResult);
                $quote = $this->quoteService->createQuote($intent, $obligation, $feeResult, $feeRuleDetails, $providerCode, $correlationId);

                // Denormalize onto intent for fast reads
                $intent->active_quote_id        = $quote->id;
                $intent->fee_quote_minor         = $quote->fee_amount_minor;
                $intent->fee_quote_provider_code = $quote->provider_code;
                $intent->version                 = 1;
                $this->intentRepository->save($intent);
            }

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

        return $this->intentShape($intent, $quote);
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
        $intentId       = $request['payment_intent_id'];
        $providerCode   = $request['provider_code'];
        $payerActorId   = $request['payer_actor_id'];
        $idempotencyKey = $request['idempotency_key'];
        $correlationId  = $request['correlation_id'];

        // Idempotency replay — return existing attempt if same key used before
        $existingAttempt = $this->attemptRepository->findByIdempotencyKey($idempotencyKey);
        if ($existingAttempt !== null) {
            return $this->attemptShape($existingAttempt);
        }

        $intent = $this->intentRepository->findById($intentId, lock: true);
        if ($intent === null) {
            throw new PaymentIntentNotFoundException($intentId);
        }

        // Guard: block if an in-flight attempt (submitting/processing/awaiting_action) already exists
        // Note: unknown/timed_out are excluded from findActiveForIntent — see T016 for those
        $activeAttempt = $this->attemptRepository->findActiveForIntent($intentId);
        if ($activeAttempt !== null) {
            throw new DuplicateAttemptException($intentId);
        }

        // T016: UNSAFE_PROVIDER_SWITCH guard — a different provider must not be started while a
        // prior attempt may have already charged (status UNKNOWN or timed_out)
        $unknownAttempt = $this->attemptRepository->findUnknownForIntent($intentId);
        if ($unknownAttempt !== null && $unknownAttempt->provider_code !== $providerCode) {
            throw new UnsafeProviderSwitchException($intentId, $unknownAttempt->provider_code, $providerCode);
        }
        if ($unknownAttempt !== null) {
            // A new key would create a new operation and could charge twice even
            // with the same provider. Replay/query the original operation only.
            throw new DuplicateAttemptException($intentId);
        }

        // Validate intent state can accept a new attempt
        PaymentIntentStateMachine::assertValidTransition($intent->status, PaymentIntentStatus::Processing);

        // P04: stale-quote revalidation — capture quote snapshot BEFORE consuming
        $quoteId      = null;
        $quoteVersion = null;
        if ($this->quoteService !== null && $this->quoteRepository !== null && $this->fees !== null) {
            $activeQuote = $this->quoteRepository->findActiveForIntent($intentId);
            if ($activeQuote !== null) {
                $ttlSeconds   = (int) ($this->config->getOrDefault('payment.quote_ttl_seconds', 300) ?? 300);
                $quoteExpired = $ttlSeconds === 0 || ($activeQuote->expires_at !== null && $activeQuote->expires_at < now());

                if ($quoteExpired) {
                    $this->quoteService->revalidateQuote(
                        $intent,
                        $activeQuote,
                        $this->purposeRegistry,
                        $this->fees,
                        $correlationId,
                    );
                    $activeQuote = $this->quoteRepository->findActiveForIntent($intentId);
                }

                if ($activeQuote !== null) {
                    // Snapshot quote identity before consuming it
                    $quoteId      = $activeQuote->id;
                    $quoteVersion = $activeQuote->version;
                    $this->quoteService->consumeQuote($activeQuote, $correlationId);
                }
            }
        }

        $account = $this->resolveProviderAccount($intent, $providerCode, $request['provider_account_id'] ?? null);
        $adapter = $this->resolveAdapter($providerCode);

        // T151: Circuit breaker — block if provider has too many consecutive failures
        $this->assertCircuitClosed($providerCode, $correlationId);

        $attemptId      = (string) Uuid::uuid7();
        $operationId    = (string) Uuid::uuid7(); // P06: server-generated deduplication ID for provider
        $timeoutMinutes = (int) ($this->config->getOrDefault('payment.attempt_timeout_minutes', 15) ?? 15);

        // P05: Capture provider account config_version at initiation time
        $providerAccountVersion = $account->config_version;
        $providerAccountId = $account->id;



        // ── Transaction 1: persist SUBMITTING attempt to durable storage BEFORE any network call ──
        // The attempt record survives regardless of what happens to the adapter call.
        // DeadlockRetryTrait retries on SQLSTATE 40P01; lock order: intent → attempt.
        $this->withDeadlockRetry(function () use (
            $intent, $intentId, $attemptId, $operationId, $providerCode, $idempotencyKey,
            $correlationId, $timeoutMinutes, $providerAccountVersion, $quoteId, $quoteVersion,
            $payerActorId, $providerAccountId
        ): void {
        DB::connection('payment')->transaction(function () use (
            $intent, $intentId, $attemptId, $operationId, $providerCode, $idempotencyKey,
            $correlationId, $timeoutMinutes, $providerAccountVersion, $quoteId, $quoteVersion,
            $payerActorId, $providerAccountId
        ): void {
            // Serialize eligibility-to-submit on the same connection as payment
            // persistence. Preflight reads above cannot protect concurrent callers.
            $intent = $this->intentRepository->findById($intentId, lock: true);
            if ($intent === null) throw new PaymentIntentNotFoundException($intentId);
            if ($this->attemptRepository->findActiveForIntent($intentId) !== null
                || $this->attemptRepository->findUnknownForIntent($intentId) !== null) {
                throw new DuplicateAttemptException($intentId);
            }
            PaymentIntentStateMachine::assertValidTransition($intent->status, PaymentIntentStatus::Processing);
            $intent->status = PaymentIntentStatus::Processing;
            $this->intentRepository->save($intent);

            $attempt = new PaymentAttemptModel();
            $attempt->id                      = $attemptId;
            $attempt->payment_intent_id       = $intentId;
            $attempt->provider_code           = $providerCode;
            $attempt->provider_account_id     = $providerAccountId;
            $attempt->status                  = PaymentAttemptStatus::Submitting;
            $attempt->idempotency_key         = $idempotencyKey;
            $attempt->operation_id            = $operationId;
            $attempt->quote_id                = $quoteId;
            $attempt->quote_version           = $quoteVersion;
            $attempt->provider_account_version = $providerAccountVersion;
            $attempt->initiated_at            = now();
            $attempt->timeout_at              = now()->addMinutes($timeoutMinutes);
            $this->attemptRepository->save($attempt);

            $this->audit->record(new AuditEventData(
                action:        'payment.attempt.submitting',
                actorId:       $payerActorId,
                actorType:     'customer',
                resourceType:  'payment_attempt',
                resourceId:    $attemptId,
                correlationId: $correlationId,
                outcome:       'success',
                afterState:    [
                    'status'        => PaymentAttemptStatus::Submitting->value,
                    'operation_id'  => $operationId,
                    'provider_code' => $providerCode,
                ],
            ));
        });
        }, 'payment_intent:'.$intentId);
        // T1 committed — attempt exists in SUBMITTING state, intent in PROCESSING

        $initiateRequest = new PaymentInitiateRequest(
            paymentIntentId:  $intentId,
            paymentAttemptId: $attemptId,
            amountMinor:      $intent->amount_minor,
            currencyCode:     $intent->currency_code,
            payerActorId:     $payerActorId,
            correlationId:    $correlationId,
            idempotencyKey:   $idempotencyKey,
            operationId:      $operationId,
            walletId:         $request['wallet_id'] ?? null,
            stepUpToken:      $request['step_up_token'] ?? null,
            sessionId:        $request['session_id'] ?? null,
        );

        // ── Adapter call — OUTSIDE any DB lock; hold and network calls happen here ──
        try {
            $result = $adapter->initiatePayment($initiateRequest);
            $this->recordCircuitSuccess($providerCode);
        } catch (\Throwable $e) {
            $this->recordCircuitFailure($providerCode, $correlationId);

            // An exception is not proof of non-execution. Preserve SUBMITTING,
            // the original operation and any hold for query/manual recovery.
            // The scheduled crash-recovery scanner resolves this durable attempt.
            throw $e;
        }

        // ── Transaction 2 (success): update attempt from adapter result ──
        DB::connection('payment')->transaction(function () use (
            $attemptId, $correlationId, $payerActorId, $result, $providerCode
        ): void {
            $attempt = $this->attemptRepository->findById($attemptId, lock: true);
            if ($attempt === null || $attempt->status !== PaymentAttemptStatus::Submitting) {
                return;
            }

            PaymentAttemptStateMachine::assertValidTransition($attempt->status, $result->status);

            $attempt->status                = $result->status;
            $attempt->provider_reference_id = $result->providerReferenceId;
            $attempt->wallet_hold_id        = $result->walletHoldId;
            $attempt->ledger_journal_id     = $result->ledgerJournalId;
            $attempt->next_action_type      = $result->nextActionType;
            $attempt->next_action_data      = $result->nextActionData;
            $attempt->fee_minor             = $result->feeMinor;
            $this->attemptRepository->save($attempt);

            $this->audit->record(new AuditEventData(
                action:        'payment.attempt.initiated',
                actorId:       $payerActorId,
                actorType:     'customer',
                resourceType:  'payment_attempt',
                resourceId:    $attemptId,
                correlationId: $correlationId,
                outcome:       'success',
                afterState:    ['status' => $result->status->value, 'provider_code' => $providerCode],
            ));
        });

        // Schedule timeout job after T2 commits
        \App\Modules\Payment\Application\Jobs\PaymentAttemptTimeoutJob::dispatch($attemptId)
            ->delay(now()->addMinutes($timeoutMinutes));

        return $this->attemptShape(
            $this->attemptRepository->findByIdempotencyKey($idempotencyKey)
                ?? new PaymentAttemptModel(['id' => $attemptId, 'status' => $result->status])
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

        // P08: capture intent/attempt after the capture transaction for post-commit ledger posting
        $capturedIntent  = null;
        $capturedAttempt = null;

        // DeadlockRetryTrait retries on SQLSTATE 40P01; lock order: intent row → attempt row.
        $this->withDeadlockRetry(function () use (
            $intentId, $attemptId, $outcome, $correlationId, $feeMinor, $request,
            &$capturedIntent, &$capturedAttempt
        ): void {
        DB::connection('payment')->transaction(function () use (
            $intentId, $attemptId, $outcome, $correlationId, $feeMinor, $request,
            &$capturedIntent, &$capturedAttempt
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

            $incomingProviderRef = $request['provider_reference_id'] ?? null;

            // T030 Guard A: Idempotent success replay — same succeeded callback arriving twice
            if (
                $attempt->status === PaymentAttemptStatus::Succeeded
                && $newAttemptStatus === PaymentAttemptStatus::Succeeded
                && ($incomingProviderRef === null || $incomingProviderRef === $attempt->provider_reference_id)
            ) {
                return; // idempotent replay — no-op
            }

            // P07 Guard D: DUPLICATE_CAPTURE — attempt already Succeeded, incoming is also succeeded,
            // but with a different provider reference → provider charged twice; record and return silently.
            if (
                $attempt->status === PaymentAttemptStatus::Succeeded
                && $newAttemptStatus === PaymentAttemptStatus::Succeeded
                && $incomingProviderRef !== null
                && $incomingProviderRef !== $attempt->provider_reference_id
            ) {
                $this->reconciliationService?->create(ReconciliationExceptionType::DuplicateCapture, [
                    'payment_intent_id'     => $intentId,
                    'payment_attempt_id'    => $attemptId,
                    'provider_code'         => $attempt->provider_code,
                    'provider_reference_id' => $incomingProviderRef,
                    'description'           => sprintf(
                        'Duplicate capture: attempt already succeeded with ref %s; duplicate ref %s received',
                        $attempt->provider_reference_id,
                        $incomingProviderRef,
                    ),
                ]);
                return;
            }

            // T031 Guard B: CONTRADICTORY_EVIDENCE — terminal attempt receiving a conflicting outcome
            if ($attempt->status->isTerminal() && $attempt->status !== $newAttemptStatus) {
                $this->reconciliationService?->create(ReconciliationExceptionType::ContradictoryEvidence, [
                    'payment_intent_id'    => $intentId,
                    'payment_attempt_id'   => $attemptId,
                    'provider_code'        => $attempt->provider_code,
                    'provider_reference_id' => $incomingProviderRef,
                    'description'          => sprintf(
                        'Contradictory evidence: attempt already terminal as %s, received %s outcome',
                        $attempt->status->value,
                        $outcome,
                    ),
                ]);
                throw new InvalidStateTransitionException(
                    'payment_attempt',
                    $attempt->status->value,
                    $newAttemptStatus->value,
                );
            }

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
            $attempt->provider_reference_id = $incomingProviderRef ?? $attempt->provider_reference_id;

            if ($outcome === 'succeeded') {
                $attempt->confirmed_at = now();

                // T032 Guard C: EXCESS_CAPTURE — non-blocking; creates exception but allows completion
                $capturedMinor  = isset($request['captured_amount_minor']) ? (int) $request['captured_amount_minor'] : $intent->amount_minor;
                $toleranceMinor = (int) ($this->config->getOrDefault('payment.excess_capture_tolerance_minor', 0) ?? 0);
                if ($capturedMinor > $intent->amount_minor + $toleranceMinor) {
                    $this->reconciliationService?->create(ReconciliationExceptionType::ExcessCapture, [
                        'payment_intent_id'     => $intentId,
                        'payment_attempt_id'    => $attemptId,
                        'provider_code'         => $attempt->provider_code,
                        'provider_reference_id' => $attempt->provider_reference_id ?? $incomingProviderRef,
                        'detected_amount_minor' => $capturedMinor,
                        'expected_amount_minor' => $intent->amount_minor,
                        'currency_code'         => $intent->currency_code,
                        'description'           => "Excess capture: captured {$capturedMinor}, expected {$intent->amount_minor} (tolerance {$toleranceMinor})",
                    ]);
                }
            } elseif ($outcome === 'failed') {
                $attempt->failed_at     = now();
                $attempt->failure_reason = $request['failure_reason'] ?? 'unknown';
            }

            $this->attemptRepository->save($attempt);

            // Update intent
            $intent->status = $newIntentStatus;
            if ($outcome === 'succeeded') {
                $intent->succeeded_at      = now();
                // P08: mark pending_ledger — postLedgerAcknowledgement() will advance to ledger_posted
                $intent->accounting_status = PaymentAccountingStatus::PendingLedger;
                $intent->fulfilment_status = PaymentFulfilmentStatus::Pending;
            } elseif ($outcome === 'failed') {
                $intent->failed_at         = now();
                $intent->accounting_status = PaymentAccountingStatus::LedgerFailed;
                $intent->fulfilment_status = PaymentFulfilmentStatus::FulfilmentFailed;
            } elseif ($outcome === 'cancelled') {
                $intent->cancelled_at      = now();
                $intent->accounting_status = PaymentAccountingStatus::LedgerFailed;
                $intent->fulfilment_status = PaymentFulfilmentStatus::FulfilmentFailed;
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
                    'intent_status'      => $newIntentStatus->value,
                    'attempt_status'     => $newAttemptStatus->value,
                    'accounting_status'  => $intent->accounting_status?->value,
                    'fulfilment_status'  => $intent->fulfilment_status?->value,
                    'fee_minor'          => $feeMinor,
                ],
            ));

            // P08: PaymentConfirmedEvent is published AFTER the ledger journal is durable.
            // Capture references for postLedgerAcknowledgement() which runs after this transaction.
            if ($outcome === 'succeeded') {
                $capturedIntent  = $intent;
                $capturedAttempt = $attempt;
            } else {
                $failureReason = ($outcome === 'failed')
                    ? ($request['failure_reason'] ?? 'unknown')
                    : 'payment_cancelled';

                $this->publishEvent(new PaymentFailedEvent(
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
        }, 'payment_intent:'.$intentId);

        // P08: post ledger acknowledgement AFTER capture transaction commits
        if ($capturedIntent !== null && $capturedAttempt !== null) {
            try {
                $this->postLedgerAcknowledgement($capturedIntent, $capturedAttempt, $correlationId);
            } catch (\Throwable $e) {
                // Capture committed — intent is pending_ledger; repair job will retry accounting
                \Illuminate\Support\Facades\Log::warning('payment.ledger_ack_deferred', [
                    'payment_intent_id'  => $capturedIntent->id,
                    'payment_attempt_id' => $capturedAttempt->id,
                    'error'              => $e->getMessage(),
                    'correlation_id'     => $correlationId,
                ]);
            }
        }
    }

    /**
     * T082: Expire an intent that has passed its TTL.
     */
    public function expireIntent(string $intentId, string $correlationId): void
    {
        DB::connection('payment')->transaction(function () use ($intentId, $correlationId): void {
            $intent = $this->intentRepository->findById($intentId, lock: true);
            if ($intent === null || $intent->status->isTerminal()) {
                return;
            }

            // T044: Deferral guard — do NOT expire if an UNKNOWN attempt may have charged.
            // Releasing the hold on an unresolved charge would cause a double-spend.
            $unknownAttempt = $this->attemptRepository->findUnknownForIntent($intentId);
            if ($unknownAttempt !== null) {
                DB::connection('payment')->table('payment.reconciliation_exceptions')->insertOrIgnore([
                    'id'                 => (string) \Ramsey\Uuid\Uuid::uuid7(),
                    'exception_type'     => 'unknown_outcome',
                    'status'             => 'open',
                    'payment_intent_id'  => $intentId,
                    'payment_attempt_id' => $unknownAttempt->id,
                    'provider_code'      => $unknownAttempt->provider_code,
                    'description'        => "Intent expiry deferred — UNKNOWN attempt {$unknownAttempt->id} must be resolved first",
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);

                $this->audit->record(new AuditEventData(
                    action:        'payment.intent.expiry_deferred',
                    actorId:       $intent->payer_actor_id,
                    actorType:     'system',
                    resourceType:  'payment_intent',
                    resourceId:    $intentId,
                    correlationId: $correlationId,
                    outcome:       'deferred',
                    afterState:    [
                        'reason'              => 'unknown_attempt_exists',
                        'unknown_attempt_id'  => $unknownAttempt->id,
                    ],
                ));
                return; // expiry deferred — intent and hold untouched
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

            // Cancel any active quotes for this intent
            $this->quoteService?->cancelQuotesForIntent($intentId, $correlationId);

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

            $this->publishEvent(new PaymentFailedEvent(
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

        DB::connection('payment')->transaction(function () use (
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
                $this->publishEvent(new PaymentReversedEvent(
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

            // Cancel any active quotes for this intent
            $this->quoteService?->cancelQuotesForIntent($intentId, $correlationId);

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

    /**
     * P08 US2: Repair ledger acknowledgement for a single intent.
     * Called by RepairSingleAccountingJob. Does NOT re-charge — only posts the ledger journal.
     * Increments accounting_repair_attempts; marks ledger_failed + creates reconciliation
     * exception when max retries is exhausted.
     */
    public function repairAccountingForIntent(string $intentId, string $correlationId): void
    {
        $maxRetries = (int) ($this->config->getOrDefault('payment.accounting_max_repair_attempts', 5) ?? 5);

        DB::connection('payment')->transaction(function () use ($intentId, $correlationId, $maxRetries): void {
            $intent = $this->intentRepository->findById($intentId, lock: true);
            if ($intent === null || $intent->accounting_status !== PaymentAccountingStatus::PendingLedger) {
                return; // Already resolved or not found — idempotent no-op
            }

            if ($intent->accounting_repair_attempts >= $maxRetries) {
                $intent->accounting_status = PaymentAccountingStatus::LedgerFailed;
                $this->intentRepository->save($intent);

                $this->reconciliationService?->create(ReconciliationExceptionType::AccountingFailure, [
                    'payment_intent_id' => $intentId,
                    'description'       => "Repair exhausted {$maxRetries} retries without posting ledger",
                ]);

                $this->audit->record(new AuditEventData(
                    action:        'payment.ledger.repair_exhausted',
                    actorId:       $intent->payer_actor_id,
                    actorType:     'system',
                    resourceType:  'payment_intent',
                    resourceId:    $intentId,
                    correlationId: $correlationId,
                    outcome:       'failure',
                    afterState:    [
                        'accounting_status'         => PaymentAccountingStatus::LedgerFailed->value,
                        'accounting_repair_attempts' => $intent->accounting_repair_attempts,
                    ],
                ));
                return;
            }

            $intent->accounting_repair_attempts += 1;
            $intent->ledger_repair_at            = now()->addMinutes(5);
            $this->intentRepository->save($intent);
        });

        // Re-fetch after the attempt-increment transaction — attempt ledger posting outside transaction
        $intent = $this->intentRepository->findById($intentId);
        if ($intent === null || $intent->accounting_status !== PaymentAccountingStatus::PendingLedger) {
            return;
        }

        $attempt = $this->attemptRepository->findSucceededForIntent($intentId);
        if ($attempt === null) {
            \Illuminate\Support\Facades\Log::warning('payment.ledger_repair_no_attempt', [
                'payment_intent_id' => $intentId,
                'correlation_id'    => $correlationId,
            ]);
            return;
        }

        $this->postLedgerAcknowledgement($intent, $attempt, $correlationId);
    }

    /**
     * P08 US1: Post balanced double-entry ledger journal after capture commits.
     * Runs outside the capture DB transaction; idempotent via payment:{intentId}:ack key.
     * Publishes PaymentConfirmedEvent atomically with the accounting_status update.
     */
    private function postLedgerAcknowledgement(
        PaymentIntentModel  $intent,
        PaymentAttemptModel $attempt,
        string              $correlationId
    ): void {
        // AD-09 guard: wallet holds only — bank/external payments remain pending_ledger until AD-09 resolved
        if ($attempt->wallet_hold_id === null) {
            \Illuminate\Support\Facades\Log::info('payment.ledger_ack_skipped', [
                'payment_intent_id'  => $intent->id,
                'payment_attempt_id' => $attempt->id,
                'reason'             => 'no_wallet_hold_ad09_pending',
                'correlation_id'     => $correlationId,
            ]);
            return;
        }

        // AD-08: resolve consumer pocket debit account via WalletModuleContract
        $walletId = $this->wallet->findPrimaryWalletId($intent->payer_actor_id);
        if ($walletId === null) {
            throw new \RuntimeException(
                "No primary wallet for payer {$intent->payer_actor_id} — intent {$intent->id}"
            );
        }
        $debitAccountRef = $this->wallet->getPocketLedgerAccountRef($walletId, $intent->currency_code);
        if ($debitAccountRef === null) {
            throw new \RuntimeException(
                "No pocket ledger account for wallet {$walletId} / {$intent->currency_code} — intent {$intent->id}"
            );
        }

        // AD-08: resolve organisation credit account from config (placeholder until AD-08 finalised)
        $creditAccountRef = $this->config->getOrDefault(
            'payment.credit_account_'.$intent->currency_code,
            null
        );
        if ($creditAccountRef === null) {
            throw new \RuntimeException(
                "Config payment.credit_account_{$intent->currency_code} not set (AD-08 pending) — intent {$intent->id}"
            );
        }

        // Post balanced two-line double-entry journal — idempotent via payment:{intentId}:ack
        $journalId = $this->ledger->postEntry([
            'idempotency_key'       => 'payment:'.$intent->id.':ack',
            'entry_type'            => 'debit',
            'currency_code'         => $intent->currency_code,
            'transaction_reference' => $intent->id,
            'correlation_id'        => $correlationId,
            'description'           => 'Payment ack — '.$intent->purpose_type.':'.$intent->purpose_reference,
            'lines' => [
                [
                    'ledger_account_reference' => $debitAccountRef,
                    'direction'                => 'debit',
                    'amount'                   => $intent->amount_minor,
                ],
                [
                    'ledger_account_reference' => $creditAccountRef,
                    'direction'                => 'credit',
                    'amount'                   => $intent->amount_minor,
                ],
            ],
        ]);

        // Atomically update accounting_status and publish PaymentConfirmedEvent after journal is durable
        DB::connection('payment')->transaction(function () use (
            $intent, $attempt, $journalId, $correlationId
        ): void {
            $fresh = $this->intentRepository->findById($intent->id, lock: true);
            if ($fresh === null || $fresh->accounting_status !== PaymentAccountingStatus::PendingLedger) {
                return; // Already transitioned — idempotent replay
            }

            $fresh->accounting_status          = PaymentAccountingStatus::LedgerPosted;
            $fresh->ledger_repair_at           = null;
            $fresh->accounting_repair_attempts = 0;
            $this->intentRepository->save($fresh);

            $this->audit->record(new AuditEventData(
                action:        'payment.ledger.acknowledged',
                actorId:       $fresh->payer_actor_id,
                actorType:     'system',
                resourceType:  'payment_intent',
                resourceId:    $fresh->id,
                correlationId: $correlationId,
                outcome:       'success',
                afterState:    [
                    'accounting_status' => PaymentAccountingStatus::LedgerPosted->value,
                    'ledger_journal_id' => $journalId,
                ],
            ));

            // Published here — atomically with status update, AFTER journal is durable
            $this->publishEvent(new PaymentConfirmedEvent(
                paymentIntentId:  $fresh->id,
                paymentAttemptId: $attempt->id,
                purposeType:      $fresh->purpose_type,
                purposeReference: $fresh->purpose_reference,
                organisationId:   $fresh->organisation_id,
                payerActorId:     $fresh->payer_actor_id,
                amountMinor:      $fresh->amount_minor,
                currencyCode:     $fresh->currency_code,
                providerCode:     $attempt->provider_code,
                feeMinor:         $attempt->fee_minor ?? 0,
                correlationId:    $correlationId,
                occurredAt:       now()->toIso8601String(),
            ));
        });

        \Illuminate\Support\Facades\Log::info('payment.ledger_ack_complete', [
            'payment_intent_id' => $intent->id,
            'journal_id'        => $journalId,
            'correlation_id'    => $correlationId,
        ]);
    }

    /** @return array{id: string, status: string, accounting_status: ?string, fulfilment_status: ?string, amount_minor: int, currency_code: string, expires_at: string, quote?: array<string, mixed>} */
    private function intentShape(PaymentIntentModel $intent, ?PaymentQuoteModel $quote = null): array
    {
        $shape = [
            'id'                 => $intent->id,
            'status'             => $intent->status->value,
            'accounting_status'  => $intent->accounting_status?->value,
            'fulfilment_status'  => $intent->fulfilment_status?->value,
            'amount_minor'       => $intent->amount_minor,
            'currency_code'      => $intent->currency_code,
            'expires_at'         => $intent->expires_at?->toIso8601String() ?? '',
        ];

        if ($quote !== null) {
            $shape['quote'] = [
                'quote_id'            => $quote->id,
                'version'             => $quote->version,
                'base_amount_minor'   => $quote->base_amount_minor,
                'fee_amount_minor'    => $quote->fee_amount_minor,
                'total_amount_minor'  => $quote->total_amount_minor,
                'currency_code'       => $quote->currency_code,
                'provider_code'       => $quote->provider_code,
                'expires_at'          => $quote->expires_at?->toIso8601String(),
                'quote_status'        => $quote->quote_status->value,
            ];
        }

        return $shape;
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

    private function resolveAdapter(string $providerCode): \App\Modules\Payment\Domain\Contracts\InitiatesPayment
    {
        $key = 'payment.adapter.'.$providerCode;

        if (! $this->container->bound($key)) {
            throw new \DomainException("No payment adapter registered for provider '{$providerCode}'.");
        }

        return \App\Modules\Payment\Domain\ValueObjects\PaymentCapabilities::initiate($this->container->make($key));
    }

    private function publishEvent(\App\Modules\Platform\Domain\Contracts\IntegrationEventInterface $event): void
    {
        if ($this->events instanceof \App\Modules\Platform\Domain\Contracts\ConnectionAwareEventPublisherInterface) {
            if (DB::connection('payment')->transactionLevel() > 0) {
                $this->events->publishOnConnection($event, 'payment');
            } else {
                DB::connection('payment')->transaction(fn () => $this->events->publishOnConnection($event, 'payment'));
            }
            return;
        }
        $this->events->publish($event);
    }

    private function resolveProviderAccount(PaymentIntentModel $intent, string $providerCode, ?string $requestedId): \App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel
    {
        $repository = $this->providerRepository ?? app(PaymentProviderRepositoryInterface::class);
        $query = new \App\Modules\Payment\Domain\ValueObjects\OptionsQuery(
            $intent->purpose_type, $intent->purpose_reference, $intent->currency_code,
            $intent->amount_minor, $intent->organisation_id, $intent->payer_actor_id,
        );
        $candidates = $requestedId !== null
            ? array_filter([$repository->findProviderAccount($requestedId)])
            : $repository->findActiveForContext($query);
        $eligible = [];
        $pipeline = new \App\Modules\Payment\Application\Services\EligibilityPipeline();
        foreach ($candidates as $account) {
            $provider = $account->provider;
            if ($provider === null || $provider->code !== $providerCode) continue;
            if ($provider->isMock()) \App\Modules\Payment\Infrastructure\Adapters\DevelopmentPaymentGuard::check();
            // A restricted context cannot be guessed from client metadata.
            if (($account->enabled_modules ?? []) !== [] || ($account->country_restrictions ?? []) !== []) continue;
            if ($pipeline->evaluate($account, $query)->isEligible) $eligible[] = $account;
        }
        if (count($eligible) !== 1) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'provider_account_id' => 'Select one eligible provider account for this payment context.',
            ]);
        }
        return $eligible[0];
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
            $this->publishEvent(new PaymentCircuitOpenEvent(
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
