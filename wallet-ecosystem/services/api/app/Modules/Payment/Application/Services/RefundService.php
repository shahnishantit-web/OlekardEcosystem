<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\RefundEligibilityHandlerInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Domain\Enums\ReconciliationExceptionType;
use App\Modules\Payment\Domain\Events\RefundConfirmedEvent;
use App\Modules\Payment\Domain\Exceptions\CapabilityUnsupportedException;
use App\Modules\Payment\Domain\Exceptions\InvalidStateTransitionException;
use App\Modules\Payment\Domain\Exceptions\RefundEligibilityDeniedException;
use App\Modules\Payment\Domain\Exceptions\SelfApprovalNotAllowedException;
use App\Modules\Payment\Domain\Exceptions\StepUpRequiredException;
use App\Modules\Payment\Domain\Exceptions\RefundLimitExceededException;
use App\Modules\Payment\Domain\ValueObjects\PaymentRefundRequest;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\Enums\ProviderCapabilityEnum;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * T138/T139/T140: Refund lifecycle service.
 *
 * Lifecycle: requested → (pending_approval →) processing → succeeded | failed
 *
 * Invariants:
 * - Cumulative confirmed refunds MUST NOT exceed the original payment amount (T139).
 * - Refunds above approval threshold require a second actor (T140).
 * - Financial state changes only via verified provider evidence — no manual mark-as-paid.
 * - Does NOT mutate PaymentAttempt or PaymentIntent rows.
 */
final class RefundService
{
    public function __construct(
        private readonly PaymentAttemptRepositoryInterface  $attemptRepository,
        private readonly PaymentRefundRepositoryInterface   $refundRepository,
        private readonly ConfigurationServiceInterface      $config,
        private readonly AuditServiceInterface              $audit,
        private readonly EventPublisherInterface            $events,
        private readonly Container                          $container,
        private readonly ReconciliationExceptionService     $reconciliationExceptions,
        private readonly ?ActorStepUpVerifierContract       $stepUpVerifier = null,
        private readonly ?LedgerModuleContract              $ledger = null,
        private readonly ?WalletModuleContract              $wallet = null,
        private readonly ?PaymentIntentRepositoryInterface  $intentRepository = null,
        private readonly ?MetricsCollectorInterface         $metrics = null,
    ) {
    }

    private function emitStateTransition(string $fromState, string $toState, ?string $providerCode): void
    {
        $this->metrics?->increment('payment.refund.state_transition', [
            'from_state'    => $fromState,
            'to_state'      => $toState,
            'provider_code' => $providerCode ?? 'unknown',
            'is_wallet'     => ($providerCode === 'olekard_wallet' || $providerCode === 'wallet') ? 'true' : 'false',
        ]);
    }

    /**
     * T138/P15: Request a refund — creates a PaymentRefund record after cumulative guard.
     *
     * @param array{
     *   payment_intent_id: string,
     *   payment_attempt_id: string,
     *   provider_code: string,
     *   amount_minor: int,
     *   currency_code: string,
     *   requested_by: string,
     *   idempotency_key: string,
     *   correlation_id: string,
     *   reason?: string,
     *   purpose_type?: string,
     * } $dto
     * @throws RefundLimitExceededException        When cumulative in-flight refunds would exceed original.
     * @throws CapabilityUnsupportedException      When provider does not support refunds.
     * @throws RefundEligibilityDeniedException    When domain eligibility handler vetos this refund.
     */
    public function request(array $dto): PaymentRefundModel
    {
        // P15: Domain eligibility check BEFORE acquiring DB lock — prevents creating phantom records.
        // Registered under 'payment.refund_eligibility.{purpose_type}' in the service container.
        if (isset($dto['purpose_type'])) {
            $eligibilityKey = 'payment.refund_eligibility.' . $dto['purpose_type'];
            if ($this->container->bound($eligibilityKey)) {
                /** @var RefundEligibilityHandlerInterface $handler */
                $handler = $this->container->make($eligibilityKey);
                $handler->assertEligible(
                    paymentIntentId:      $dto['payment_intent_id'],
                    purposeType:          $dto['purpose_type'],
                    requestedAmountMinor: $dto['amount_minor'],
                    currencyCode:         $dto['currency_code'],
                    actorId:              $dto['requested_by'],
                );
            }
        }

        return DB::connection('payment')->transaction(function () use ($dto): PaymentRefundModel {
            $attemptId = $dto['payment_attempt_id'];

            // T139: Lock attempt row + sum confirmed refunds atomically
            $attempt = $this->attemptRepository->findById($attemptId);
            if ($attempt === null) {
                throw new \RuntimeException("Payment attempt not found: {$attemptId}");
            }

            // Check provider capability (full_refund or partial_refund)
            $providerCode = $dto['provider_code'];
            $adapter      = $this->resolveAdapter($providerCode);
            $capabilities = $adapter->getCapabilities();

            $isFullRefund    = ($dto['amount_minor'] === $attempt->amount_minor);
            $needsPartialCap = ! $isFullRefund;

            $hasFullRefund    = $capabilities->has(ProviderCapabilityEnum::ReversePriorPayment);
            $hasPartialRefund = $needsPartialCap && $capabilities->has(ProviderCapabilityEnum::ReversePriorPayment);

            if (! $hasFullRefund && ! $hasPartialRefund) {
                throw new CapabilityUnsupportedException($providerCode, 'full_refund/partial_refund');
            }

            // P15-T011: Use sumReservedRefundsForAttempt (includes all in-flight, not just confirmed)
            // Prevents concurrent partial refunds from exceeding the original payment amount.
            $reservedRefunds = $this->refundRepository->sumReservedRefundsForAttempt($attemptId);
            $newTotal        = $reservedRefunds + $dto['amount_minor'];

            if ($newTotal > $attempt->amount_minor) {
                throw new RefundLimitExceededException(
                    requestedMinor: $dto['amount_minor'],
                    confirmedMinor: $reservedRefunds,
                    originalMinor:  $attempt->amount_minor,
                    currencyCode:   $dto['currency_code'],
                );
            }

            // T140 / T030: Approval threshold check
            $approvalThreshold = (int) $this->config->getOrDefault(
                'payment.refund_approval_threshold',
                '20000'
            );
            $requiresApproval = $dto['amount_minor'] > $approvalThreshold;
            $initialStatus    = $requiresApproval
                ? PaymentRefundStatus::PendingApproval
                : PaymentRefundStatus::Processing;

            // Create refund record
            $refund = new PaymentRefundModel();
            $refund->id                 = (string) Uuid::uuid7();
            $refund->payment_intent_id  = $dto['payment_intent_id'];
            $refund->payment_attempt_id = $attemptId;
            $refund->amount_minor       = $dto['amount_minor'];
            $refund->status             = $initialStatus;
            $refund->approval_required  = $requiresApproval;
            $refund->idempotency_key    = $dto['idempotency_key'];
            $refund->requested_by       = $dto['requested_by'];
            $refund->reason             = $dto['reason'] ?? null;
            $refund->requested_at       = now();
            $this->refundRepository->save($refund);

            $this->audit->record(new AuditEventData(
                action:        'payment.refund.requested',
                actorId:       $dto['requested_by'],
                actorType:     'user',
                resourceType:  'payment_refund',
                resourceId:    $refund->id,
                correlationId: $dto['correlation_id'],
                outcome:       'success',
                afterState:    [
                    'refund_id'     => $refund->id,
                    'amount_minor'  => $dto['amount_minor'],
                    'requires_approval' => $requiresApproval,
                ],
            ));

            return $refund;
        });
    }

    /**
     * T140 / T030 / T035: Approve a pending-approval refund. Approver MUST NOT be the requester (FR-04).
     * High-value approvals require a valid step-up token (US5).
     * Only callable by actors with payment.refunds.approve permission.
     */
    public function approveRefund(
        string $refundId,
        string $approverActorId,
        string $correlationId,
        ?string $stepUpToken = null,
        ?string $sessionId = null,
    ): PaymentRefundModel {
        return DB::connection('payment')->transaction(
            function () use ($refundId, $approverActorId, $correlationId, $stepUpToken, $sessionId): PaymentRefundModel {
                $refund = $this->refundRepository->findById($refundId);
                if ($refund === null) {
                    throw new \RuntimeException("Refund not found: {$refundId}");
                }

                if ($refund->status !== PaymentRefundStatus::PendingApproval) {
                    throw new InvalidStateTransitionException(
                        'payment_refund',
                        $refund->status->value,
                        PaymentRefundStatus::Processing->value,
                    );
                }

                if ($refund->requested_by === $approverActorId) {
                    throw new SelfApprovalNotAllowedException();
                }

                // T035: Step-up MFA check for high-value refunds
                $stepUpThreshold = (int) $this->config->getOrDefault('auth.step_up_threshold', '10000');
                if ($refund->amount_minor > $stepUpThreshold
                    && ($this->stepUpVerifier === null || ! $this->stepUpVerifier->verifyStepUpForActor(
                        $stepUpToken ?? '',
                        'payment.refund.approve',
                        $approverActorId,
                        $sessionId ?? '',
                    ))
                ) {
                    throw new StepUpRequiredException();
                }

                $refund->status              = PaymentRefundStatus::Processing;
                $refund->approved_by         = $approverActorId;
                $refund->approved_by_actor_id = $approverActorId;
                $refund->approved_at         = now();
                $this->refundRepository->save($refund);

                $this->audit->record(new AuditEventData(
                    action:        'payment.refund.approved',
                    actorId:       $approverActorId,
                    actorType:     'user',
                    resourceType:  'payment_refund',
                    resourceId:    $refundId,
                    correlationId: $correlationId,
                    outcome:       'success',
                    afterState:    ['refund_id' => $refundId, 'approved_by' => $approverActorId],
                ));

                return $refund;
            }
        );
    }

    /**
     * Reject a pending-approval refund. Reviewer MUST NOT be the requester (FR-04).
     */
    public function rejectRefund(string $refundId, string $reviewerActorId, string $reason, string $correlationId): PaymentRefundModel
    {
        return DB::connection('payment')->transaction(
            function () use ($refundId, $reviewerActorId, $reason, $correlationId): PaymentRefundModel {
                $refund = $this->refundRepository->findById($refundId);
                if ($refund === null) {
                    throw new \RuntimeException("Refund not found: {$refundId}");
                }

                if ($refund->status !== PaymentRefundStatus::PendingApproval) {
                    throw new InvalidStateTransitionException(
                        'payment_refund',
                        $refund->status->value,
                        PaymentRefundStatus::Rejected->value,
                    );
                }

                if ($refund->requested_by === $reviewerActorId) {
                    throw new SelfApprovalNotAllowedException();
                }

                $refund->status              = PaymentRefundStatus::Rejected;
                $refund->rejected_by_actor_id = $reviewerActorId;
                $refund->rejected_at         = now();
                $refund->rejection_reason    = $reason;
                $this->refundRepository->save($refund);

                $this->audit->record(new AuditEventData(
                    action:        'payment.refund.rejected',
                    actorId:       $reviewerActorId,
                    actorType:     'user',
                    resourceType:  'payment_refund',
                    resourceId:    $refundId,
                    correlationId: $correlationId,
                    outcome:       'success',
                    afterState:    ['refund_id' => $refundId, 'rejected_by' => $reviewerActorId, 'reason' => $reason],
                ));

                return $refund;
            }
        );
    }

    /**
     * P15-T028: Initiate a processing refund with the provider adapter.
     *
     * Before calling the adapter, verifies the provider supports refund capabilities
     * using the Payment domain ProviderCapabilityEnum (full_refund / partial_refund).
     * On capability absence: throws CapabilityUnsupportedException + creates ReconciliationException.
     */
    public function initiate(string $refundId, string $correlationId): void
    {
        $refund = $this->refundRepository->findById($refundId);
        if ($refund === null) {
            throw new \RuntimeException("Refund not found: {$refundId}");
        }

        if ($refund->status !== PaymentRefundStatus::Processing) {
            throw new InvalidStateTransitionException(
                from: $refund->status->value,
                to:   'initiating',
            );
        }

        $attempt = $this->attemptRepository->findById($refund->payment_attempt_id);
        if ($attempt === null) {
            throw new \RuntimeException("Attempt not found for refund: {$refundId}");
        }

        $adapter = $this->resolveAdapter($attempt->provider_code);

        // T028: Check Payment-domain refund capabilities by comparing string values across enum types.
        // AD-07: Payment domain uses its own ProviderCapabilityEnum (full_refund/partial_refund),
        // which maps to string values not present in Platform's ProviderCapabilityEnum.
        $capabilityValues = array_map(
            static fn($c) => $c->value,
            $adapter->getCapabilities()->all()
        );
        $isPartialRefund    = ($refund->amount_minor < $attempt->amount_minor);
        $fullRefundValue    = \App\Modules\Payment\Domain\Enums\ProviderCapabilityEnum::FullRefund->value;
        $partialRefundValue = \App\Modules\Payment\Domain\Enums\ProviderCapabilityEnum::PartialRefund->value;

        $hasFullRefund    = in_array($fullRefundValue, $capabilityValues, true);
        $hasPartialRefund = in_array($partialRefundValue, $capabilityValues, true);

        // Partial amount requires partial_refund capability specifically.
        // Full amount can use either full_refund or partial_refund (partial covers full).
        $supportsRefund = $isPartialRefund
            ? $hasPartialRefund
            : ($hasFullRefund || $hasPartialRefund);

        if (! $supportsRefund) {
            $refundType = $isPartialRefund ? 'partial_refund' : 'full_refund';
            try {
                $this->reconciliationExceptions->create(
                    ReconciliationExceptionType::UnsupportedRefundMethod,
                    [
                        'payment_intent_id'  => $refund->payment_intent_id,
                        'payment_attempt_id' => $refund->payment_attempt_id,
                        'refund_id'          => $refundId,
                        'description'        => "Provider {$attempt->provider_code} does not support {$refundType}",
                    ]
                );
            } catch (\Throwable) {
                // Non-fatal: recon exception failure must not suppress the main guard
            }

            \Illuminate\Support\Facades\Log::error('Refund capability unsupported', [
                'refund_id'    => $refundId,
                'provider_code' => $attempt->provider_code,
                'refund_type'  => $refundType,
            ]);

            throw new CapabilityUnsupportedException($attempt->provider_code, $refundType);
        }

        $result = $adapter->initiateRefund(new PaymentRefundRequest(
            paymentIntentId:     $refund->payment_intent_id,
            paymentAttemptId:    $refund->payment_attempt_id,
            refundId:            $refundId,
            amountMinor:         $refund->amount_minor,
            currencyCode:        $attempt->currency_code ?? '',
            correlationId:       $correlationId,
            idempotencyKey:      $refund->idempotency_key ?? $refundId,
            providerReferenceId: $attempt->provider_reference_id,
        ));

        // For synchronous providers (wallet), confirm immediately
        if ($result->status === PaymentRefundStatus::Succeeded
            || $result->status === PaymentRefundStatus::Confirmed
        ) {
            $this->confirm($refundId, $result->providerRefundReference ?? $refundId, $correlationId);
        }

        // For async bank providers, submit (provider accepted the request)
        if ($result->status === PaymentRefundStatus::Submitted
            && isset($result->providerRefundReference)
        ) {
            $this->submit($refundId, $result->providerRefundReference, $correlationId);
        }
    }

    /**
     * T138/P15-T019: Confirm refund after provider verification.
     *
     * Idempotent: replaying an already-confirmed refund returns the existing record (no second journal).
     * Wallet refunds post a compensating ledger entry via LedgerModuleContract OUTSIDE the DB transaction.
     * Bank refunds defer accounting (AD-16 unresolved) — sets pending_ledger_refund_fee_blocked.
     * Publishes refund.confirmed outbox event.
     */
    public function confirm(string $refundId, string $providerRefundRef, string $correlationId): PaymentRefundModel
    {
        $refund = $this->refundRepository->findById($refundId);
        if ($refund === null) {
            throw new \RuntimeException("Refund not found: {$refundId}");
        }

        // Idempotency gate — return immediately if already in a terminal success state
        if ($refund->status === PaymentRefundStatus::Confirmed
            || $refund->status === PaymentRefundStatus::Succeeded
        ) {
            return $refund;
        }

        if (! $refund->status->canTransitionTo(PaymentRefundStatus::Confirmed)) {
            throw new InvalidStateTransitionException(
                $refund->status->value,
                PaymentRefundStatus::Confirmed->value,
            );
        }

        // Step 1: Persist confirmed state in payment DB
        $attempt          = $this->attemptRepository->findById($refund->payment_attempt_id);
        $fromStateConfirm = $refund->status->value;
        $providerCode     = $attempt?->provider_code ?? '';

        DB::connection('payment')->transaction(function () use ($refund, $providerRefundRef, $correlationId, $attempt): void {
            $refund->status                   = PaymentRefundStatus::Confirmed;
            $refund->provider_refund_reference = $providerRefundRef;
            $refund->confirmed_at             = now();
            $this->refundRepository->save($refund);

            $this->audit->record(new AuditEventData(
                action:        'payment.refund.confirmed',
                actorId:       'system',
                actorType:     'system',
                resourceType:  'payment_refund',
                resourceId:    $refund->id,
                correlationId: $correlationId,
                outcome:       'success',
                afterState:    ['status' => 'confirmed', 'provider_ref' => $providerRefundRef],
            ));
        });

        $this->emitStateTransition($fromStateConfirm, 'confirmed', $providerCode);

        // Step 2: Post compensating ledger entry OUTSIDE the DB transaction
        $currencyCode     = $attempt?->currency_code ?? 'BWP';
        $isWalletProvider = ($providerCode === 'wallet');

        if ($isWalletProvider && $this->ledger !== null && $this->wallet !== null) {
            $this->postRefundLedgerEntry($refund, $currencyCode, $correlationId);
        } elseif (! $isWalletProvider) {
            // AD-16: bank refund fee policy unresolved — defer accounting
            DB::connection('payment')->transaction(function () use ($refund): void {
                $refund->refund_accounting_status = 'pending_ledger_refund_fee_blocked';
                $this->refundRepository->save($refund);
            });
            \Illuminate\Support\Facades\Log::warning('Bank refund accounting blocked pending AD-16 resolution', [
                'decision'  => 'AD-16',
                'refund_id' => $refund->id,
            ]);
        }

        // Step 3: Publish outbox event
        $totalConfirmed = $this->refundRepository->sumConfirmedRefundsForAttempt($refund->payment_attempt_id);
        $isFullRefund   = $attempt !== null && $totalConfirmed >= $attempt->amount_minor;

        $this->events->publish(new RefundConfirmedEvent(
            paymentIntentId:          $refund->payment_intent_id,
            paymentRefundId:          $refundId,
            purposeType:              '',
            purposeReference:         '',
            organisationId:           '',
            refundAmountMinor:        $refund->amount_minor,
            currencyCode:             $currencyCode,
            isFullRefund:             $isFullRefund,
            remainingRefundableMinor: $attempt !== null
                ? max(0, $attempt->amount_minor - $totalConfirmed)
                : 0,
            correlationId:            $correlationId,
            occurredAt:               new \DateTimeImmutable(),
        ));

        return $refund;
    }

    /**
     * Post a double-entry compensating journal for a wallet refund.
     * Called outside the DB transaction for idempotency.
     * On ledger failure, sets pending_ledger_refund without rolling back confirmed state.
     */
    private function postRefundLedgerEntry(PaymentRefundModel $refund, string $currencyCode, string $correlationId): void
    {
        try {
            $intent = $this->intentRepository?->findById($refund->payment_intent_id);
            if ($intent === null) {
                throw new \RuntimeException("Intent not found for refund ledger: {$refund->payment_intent_id}");
            }

            $walletId       = $this->wallet->findPrimaryWalletId($intent->payer_actor_id);
            $creditRef      = $walletId !== null
                ? $this->wallet->getPocketLedgerAccountRef($walletId, $currencyCode)
                : null;
            $debitRef       = $this->config->getOrDefault('payment.credit_account_' . $currencyCode, null);

            if ($creditRef === null || $debitRef === null) {
                throw new \RuntimeException("Ledger account refs not resolved for refund {$refund->id}");
            }

            $journalId = $this->ledger->postEntry([
                'idempotency_key'       => "refund:{$refund->id}",
                'entry_type'            => 'credit',
                'currency_code'         => $currencyCode,
                'transaction_reference' => "refund:{$refund->id}",
                'correlation_id'        => $correlationId,
                'description'           => "Wallet refund compensating entry for refund {$refund->id}",
                'lines'                 => [
                    [
                        'ledger_account_reference' => $debitRef,
                        'direction'                => 'debit',
                        'amount'                   => $refund->amount_minor,
                    ],
                    [
                        'ledger_account_reference' => $creditRef,
                        'direction'                => 'credit',
                        'amount'                   => $refund->amount_minor,
                    ],
                ],
            ]);

            DB::connection('payment')->transaction(function () use ($refund, $journalId): void {
                $refund->refund_accounting_status  = 'ledger_refund_posted';
                $refund->refund_ledger_journal_id  = $journalId;
                $this->refundRepository->save($refund);
            });

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Refund ledger entry failed — queued for repair', [
                'refund_id' => $refund->id,
                'error'     => $e->getMessage(),
            ]);

            DB::connection('payment')->transaction(function () use ($refund): void {
                $refund->refund_accounting_status = 'pending_ledger_refund';
                $this->refundRepository->save($refund);
            });
        }
    }

    /**
     * Mark a refund as failed (provider declined or error).
     */
    public function fail(string $refundId, string $reason): void
    {
        DB::connection('payment')->transaction(function () use ($refundId, $reason): void {
            $refund = $this->refundRepository->findById($refundId);
            if ($refund === null) {
                throw new \RuntimeException("Refund not found: {$refundId}");
            }

            $refund->status        = PaymentRefundStatus::Failed;
            $refund->failure_reason = $reason;
            $refund->failed_at     = now();
            $this->refundRepository->save($refund);

            // Create reconciliation exception for failed refunds
            $this->reconciliationExceptions->create(
                ReconciliationExceptionType::FailedRefund,
                [
                    'refund_id'   => $refundId,
                    'description' => "Refund failed: {$reason}",
                ]
            );
        });
    }

    /**
     * T014: Transition Processing → Submitted after the provider accepts the refund request.
     * Persists provider_submitted_ref for callback matching.
     * Schedules QueryRefundStatusJob for async bank refund tracking.
     */
    public function submit(string $refundId, string $providerSubmittedRef, string $correlationId): PaymentRefundModel
    {
        return DB::connection('payment')->transaction(
            function () use ($refundId, $providerSubmittedRef, $correlationId): PaymentRefundModel {
                $refund = $this->refundRepository->findById($refundId);
                if ($refund === null) {
                    throw new \RuntimeException("Refund not found: {$refundId}");
                }

                if (! $refund->status->canTransitionTo(PaymentRefundStatus::Submitted)) {
                    throw new InvalidStateTransitionException(
                        $refund->status->value,
                        PaymentRefundStatus::Submitted->value,
                    );
                }

                $refund->status                 = PaymentRefundStatus::Submitted;
                $refund->provider_submitted_ref = $providerSubmittedRef;
                $refund->submitted_at           = now();
                $this->refundRepository->save($refund);

                $this->audit->record(new AuditEventData(
                    action:        'payment.refund.submitted',
                    actorId:       'system',
                    actorType:     'system',
                    resourceType:  'payment_refund',
                    resourceId:    $refundId,
                    correlationId: $correlationId,
                    outcome:       'success',
                    afterState:    ['status' => 'submitted', 'provider_submitted_ref' => $providerSubmittedRef],
                ));

                $this->emitStateTransition('processing', 'submitted', null);
                return $refund;
            }
        );
    }

    /**
     * T015: Transition Submitted → Pending (provider acknowledged, awaiting settlement).
     */
    public function markPending(string $refundId, string $correlationId): PaymentRefundModel
    {
        return DB::connection('payment')->transaction(
            function () use ($refundId, $correlationId): PaymentRefundModel {
                $refund = $this->refundRepository->findById($refundId);
                if ($refund === null) {
                    throw new \RuntimeException("Refund not found: {$refundId}");
                }

                if (! $refund->status->canTransitionTo(PaymentRefundStatus::Pending)) {
                    throw new InvalidStateTransitionException(
                        $refund->status->value,
                        PaymentRefundStatus::Pending->value,
                    );
                }

                $fromState = $refund->status->value;
                $refund->status     = PaymentRefundStatus::Pending;
                $refund->pending_at = now();
                $this->refundRepository->save($refund);

                $this->audit->record(new AuditEventData(
                    action:        'payment.refund.pending',
                    actorId:       'system',
                    actorType:     'system',
                    resourceType:  'payment_refund',
                    resourceId:    $refundId,
                    correlationId: $correlationId,
                    outcome:       'success',
                    afterState:    ['status' => 'pending'],
                ));

                $this->emitStateTransition($fromState, 'pending', null);
                return $refund;
            }
        );
    }

    /**
     * T016: Transition Submitted|Pending → Unknown (max query attempts exhausted; outcome unverified).
     * Does NOT release reservation — funds remain locked until explicit Confirmed or DefinitiveFailure.
     */
    public function markUnknown(string $refundId, string $correlationId): PaymentRefundModel
    {
        return DB::connection('payment')->transaction(
            function () use ($refundId, $correlationId): PaymentRefundModel {
                $refund = $this->refundRepository->findById($refundId);
                if ($refund === null) {
                    throw new \RuntimeException("Refund not found: {$refundId}");
                }

                if (! $refund->status->canTransitionTo(PaymentRefundStatus::Unknown)) {
                    throw new InvalidStateTransitionException(
                        $refund->status->value,
                        PaymentRefundStatus::Unknown->value,
                    );
                }

                $fromStateUnknown      = $refund->status->value;
                $refund->status        = PaymentRefundStatus::Unknown;
                $refund->unknown_at    = now();
                $refund->query_attempts = ($refund->query_attempts ?? 0) + 1;
                $this->refundRepository->save($refund);

                $this->audit->record(new AuditEventData(
                    action:        'payment.refund.unknown',
                    actorId:       'system',
                    actorType:     'system',
                    resourceType:  'payment_refund',
                    resourceId:    $refundId,
                    correlationId: $correlationId,
                    outcome:       'warning',
                    afterState:    [
                        'status'         => 'unknown',
                        'query_attempts' => $refund->query_attempts,
                        'alert'          => 'operator_investigation_required',
                    ],
                ));

                $this->emitStateTransition($fromStateUnknown, 'unknown', null);
                return $refund;
            }
        );
    }

    /**
     * T017: Transition Submitted|Pending|Unknown → DefinitiveFailure.
     * Releases the refund reservation (terminal state).
     * Creates a DefinitiveRefundFailure reconciliation exception.
     * Publishes RefundFailedEvent via outbox.
     */
    public function definitivelyFail(string $refundId, string $reason, string $correlationId): PaymentRefundModel
    {
        return DB::connection('payment')->transaction(
            function () use ($refundId, $reason, $correlationId): PaymentRefundModel {
                $refund = $this->refundRepository->findById($refundId);
                if ($refund === null) {
                    throw new \RuntimeException("Refund not found: {$refundId}");
                }

                if ($refund->status === PaymentRefundStatus::DefinitiveFailure) {
                    return $refund; // idempotency gate
                }

                if (! $refund->status->canTransitionTo(PaymentRefundStatus::DefinitiveFailure)) {
                    throw new InvalidStateTransitionException(
                        $refund->status->value,
                        PaymentRefundStatus::DefinitiveFailure->value,
                    );
                }

                $fromStateDF           = $refund->status->value;
                $refund->status        = PaymentRefundStatus::DefinitiveFailure;
                $refund->failure_reason = $reason;
                $refund->failed_at     = now();
                $this->refundRepository->save($refund);

                $this->reconciliationExceptions->create(
                    ReconciliationExceptionType::DefinitiveRefundFailure,
                    [
                        'payment_intent_id'  => $refund->payment_intent_id,
                        'payment_attempt_id' => $refund->payment_attempt_id,
                        'description'        => "Refund {$refundId} permanently declined by provider: {$reason}",
                    ]
                );

                $this->events->publish(new \App\Modules\Payment\Domain\Events\RefundFailedEvent(
                    refundId:          $refundId,
                    paymentIntentId:   $refund->payment_intent_id,
                    paymentAttemptId:  $refund->payment_attempt_id,
                    organisationId:    '',
                    actorId:           'system',
                    failureReason:     $reason,
                    correlationId:     $correlationId,
                    occurredAt:        now()->toIso8601String(),
                ));

                $this->audit->record(new AuditEventData(
                    action:        'payment.refund.definitive_failure',
                    actorId:       'system',
                    actorType:     'system',
                    resourceType:  'payment_refund',
                    resourceId:    $refundId,
                    correlationId: $correlationId,
                    outcome:       'failure',
                    afterState:    ['status' => 'definitive_failure', 'reason' => $reason],
                ));

                $this->emitStateTransition($fromStateDF, 'definitive_failure', null);
                return $refund;
            }
        );
    }

    /**
     * Resolve the adapter for a given provider code.
     */
    private function resolveAdapter(string $providerCode): \App\Modules\Payment\Domain\Contracts\RefundsPayment&\App\Modules\Platform\Domain\Contracts\Providers\ProviderAdapterInterface
    {
        $key = 'payment.adapter.'.$providerCode;
        if (! $this->container->bound($key)) {
            throw new \RuntimeException("No payment adapter registered for provider: {$providerCode}");
        }

        $adapter = \App\Modules\Payment\Domain\ValueObjects\PaymentCapabilities::refund($this->container->make($key));
        if (! $adapter instanceof \App\Modules\Platform\Domain\Contracts\Providers\ProviderAdapterInterface) {
            throw new CapabilityUnsupportedException($providerCode, 'provider_manifest');
        }
        return $adapter;
    }
}
