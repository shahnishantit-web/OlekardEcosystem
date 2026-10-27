<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentDisputeCaseRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Enums\DisputeAccountingStatus;
use App\Modules\Payment\Domain\Enums\DisputeCaseType;
use App\Modules\Payment\Domain\Enums\DisputeEntitlementAction;
use App\Modules\Payment\Domain\Enums\PaymentDisputeCaseStatus;
use App\Modules\Payment\Domain\Enums\ReconciliationExceptionType;
use App\Modules\Payment\Domain\Events\DisputeCaseReceivedEvent;
use App\Modules\Payment\Domain\Events\DisputeHoldAuthorisedEvent;
use App\Modules\Payment\Domain\Events\DisputeLateContradictoryOutcomeEvent;
use App\Modules\Payment\Domain\Events\DisputeLostEvent;
use App\Modules\Payment\Domain\Events\DisputeReversedEvent;
use App\Modules\Payment\Domain\Events\DisputeWonEvent;
use App\Modules\Payment\Domain\Exceptions\DisputeCaseClosedException;
use App\Modules\Payment\Domain\Exceptions\DisputeCaseNotFoundException;
use App\Modules\Payment\Domain\Exceptions\DisputeInvalidTransitionException;
use App\Modules\Payment\Domain\ValueObjects\DisputeOutcomeRequest;
use App\Modules\Payment\Infrastructure\Models\DisputeCaseEventModel;
use App\Modules\Payment\Infrastructure\Models\DisputeHoldModel;
use App\Modules\Payment\Infrastructure\Models\PaymentDisputeCaseModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * P16 Dispute lifecycle service.
 *
 * Invariants:
 * - No wallet balance mutation — holds notify Reconciliation module via events.
 * - All correcting journals via LedgerModuleContract::postEntry() OUTSIDE DB transactions.
 * - Idempotency keys: dispute:{caseId}:chargeback, dispute:{caseId}:fee.
 * - DisputeCaseEvent records are append-only; never updated after insert.
 * - Double-compensation guard: net_chargeback = max(0, disputed - confirmed_refunds).
 */
final class DisputeService
{
    public function __construct(
        private readonly PaymentDisputeCaseRepositoryInterface $caseRepository,
        private readonly PaymentAttemptRepositoryInterface     $attemptRepository,
        private readonly PaymentRefundRepositoryInterface      $refundRepository,
        private readonly ReconciliationExceptionService        $reconciliationExceptions,
        private readonly EventPublisherInterface               $events,
        private readonly AuditServiceInterface                 $audit,
        private readonly Container                             $container,
        private readonly ?LedgerModuleContract                 $ledger = null,
        private readonly ?MetricsCollectorInterface            $metrics = null,
    ) {
    }

    // ─── US1: Provider event ingestion ──────────────────────────────────────

    /**
     * Receive and deduplicate a provider dispute/reversal/chargeback event.
     *
     * @param array{
     *   provider_code: string,
     *   provider_dispute_reference: string,
     *   payment_attempt_id: string,
     *   case_type: string,
     *   disputed_amount_minor: int,
     *   currency_code: string,
     *   reason_code?: string|null,
     *   response_deadline?: string|null,
     *   correlation_id?: string,
     * } $payload
     * @return array{action: 'created'|'duplicate_acknowledged', dispute_case_id: string}
     */
    public function receive(array $payload): array
    {
        $providerCode      = $payload['provider_code'];
        $providerRef       = $payload['provider_dispute_reference'];
        $attemptId         = $payload['payment_attempt_id'];
        $correlationId     = $payload['correlation_id'] ?? "dispute:{$providerRef}";

        // Deduplication: if a case for this provider_ref already exists, acknowledge silently
        $existing = $this->caseRepository->findByProviderRef($providerCode, $providerRef);
        if ($existing !== null) {
            Log::info('DisputeService::receive duplicate acknowledged', [
                'dispute_case_id' => $existing->id,
                'provider_ref'    => $providerRef,
                'correlation_id'  => $correlationId,
            ]);
            $this->appendEvent($existing->id, 'duplicate_detected', null, [
                'provider_ref'   => $providerRef,
                'correlation_id' => $correlationId,
            ], $correlationId);
            return ['action' => 'duplicate_acknowledged', 'dispute_case_id' => $existing->id];
        }

        // Verify the payment attempt exists
        $attempt = $this->attemptRepository->findById($attemptId);
        if ($attempt === null) {
            $this->reconciliationExceptions->record(
                ReconciliationExceptionType::UnmatchedDisputeEvent,
                null,
                $attemptId,
                $providerCode,
                $providerRef,
                null,
                null,
                $payload['currency_code'] ?? null,
                "Provider dispute event references unknown payment attempt {$attemptId}",
            );
            throw new \InvalidArgumentException(
                "Payment attempt not found: {$attemptId}"
            );
        }

        // Sum confirmed refunds for double-compensation guard
        $priorConfirmedRefunds = $this->refundRepository->sumConfirmedRefundsForAttempt($attemptId);
        $netExposure           = max(0, $payload['disputed_amount_minor'] - $priorConfirmedRefunds);

        $caseId = Uuid::uuid7()->toString();
        $now    = now()->toIso8601String();

        $case = DB::connection('payment')->transaction(function () use (
            $caseId, $payload, $providerCode, $providerRef, $attemptId,
            $priorConfirmedRefunds, $netExposure, $correlationId, $now
        ): PaymentDisputeCaseModel {
            $case = new PaymentDisputeCaseModel([
                'id'                          => $caseId,
                'payment_attempt_id'          => $attemptId,
                'provider_code'               => $providerCode,
                'provider_dispute_reference'  => $providerRef,
                'case_type'                   => $payload['case_type'],
                'status'                      => PaymentDisputeCaseStatus::Received->value,
                'disputed_amount_minor'       => $payload['disputed_amount_minor'],
                'currency_code'               => $payload['currency_code'],
                'reason_code'                 => $payload['reason_code'] ?? null,
                'response_deadline'           => $payload['response_deadline'] ?? null,
                'net_exposure_minor'          => $netExposure,
                'prior_refunds_minor'         => $priorConfirmedRefunds,
                'entitlement_action'          => DisputeEntitlementAction::Pending->value,
                'accounting_status'           => DisputeAccountingStatus::Pending->value,
                'correlation_id'              => $correlationId,
            ]);
            $case->save();

            $this->appendEventRecord($caseId, 'case_received', null, [
                'provider_ref'          => $providerRef,
                'disputed_amount_minor' => $payload['disputed_amount_minor'],
                'case_type'             => $payload['case_type'],
                'net_exposure_minor'    => $netExposure,
                'prior_refunds_minor'   => $priorConfirmedRefunds,
            ], $correlationId);

            return $case;
        });

        // Publish outbox event OUTSIDE DB transaction
        $intent  = $attempt->payment_intent_id ?? null;
        $this->events->publish(new DisputeCaseReceivedEvent(
            disputeCaseId:            $caseId,
            paymentAttemptId:         $attemptId,
            paymentIntentId:          $intent,
            organisationId:           null,
            providerCode:             $providerCode,
            providerDisputeReference: $providerRef,
            caseType:                 $payload['case_type'],
            disputedAmountMinor:      $payload['disputed_amount_minor'],
            currencyCode:             $payload['currency_code'],
            reasonCode:               $payload['reason_code'] ?? null,
            responseDeadline:         $payload['response_deadline'] ?? null,
            correlationId:            $correlationId,
            occurredAt:               $now,
        ), "dispute.received:{$caseId}");

        $this->metrics?->increment('payment.dispute.case_received', ['case_type' => $payload['case_type']]);

        return ['action' => 'created', 'dispute_case_id' => $caseId];
    }

    // ─── US2: Financial exposure assessment and authorised hold ─────────────

    /**
     * Authorise a financial hold on a dispute case.
     *
     * @throws DisputeCaseNotFoundException
     * @throws DisputeInvalidTransitionException
     * @throws \InvalidArgumentException
     */
    public function authoriseHold(
        string  $caseId,
        int     $holdAmountMinor,
        string  $exposureType,
        string  $actorId,
        string  $correlationId,
    ): PaymentDisputeCaseModel {
        $case = $this->requireCase($caseId);

        if ($case->status !== PaymentDisputeCaseStatus::Received) {
            throw new DisputeInvalidTransitionException($caseId, $case->status->value, 'under_review');
        }

        // Zero-amount hold is valid for settled_funds_receivable path
        if ($exposureType === 'hold' && $holdAmountMinor < 1) {
            throw new \InvalidArgumentException('hold_amount_minor must be >= 1 when exposure_type = hold');
        }

        if ($holdAmountMinor > $case->net_exposure_minor && $case->net_exposure_minor > 0) {
            throw new \InvalidArgumentException(
                "hold_amount_minor ({$holdAmountMinor}) exceeds net_exposure_minor ({$case->net_exposure_minor})"
            );
        }

        $holdId = Uuid::uuid7()->toString();
        $now    = now()->toIso8601String();

        DB::connection('payment')->transaction(function () use (
            $case, $caseId, $holdId, $holdAmountMinor, $exposureType, $actorId, $correlationId
        ): void {
            $hold = new DisputeHoldModel([
                'id'                      => $holdId,
                'dispute_case_id'         => $caseId,
                'hold_amount_minor'       => $holdAmountMinor,
                'currency_code'           => $case->currency_code,
                'settled_funds_receivable' => $exposureType === 'settled_funds_receivable',
                'authorised_by'           => $actorId,
            ]);
            $hold->save();

            $case->status           = PaymentDisputeCaseStatus::UnderReview;
            $case->exposure_type    = $exposureType;
            $case->hold_amount_minor = $holdAmountMinor;
            if ($exposureType === 'settled_funds_receivable') {
                $case->accounting_status = DisputeAccountingStatus::ReceivableRecorded;
            }
            $case->save();

            $this->appendEventRecord($caseId, 'hold_authorised', $actorId, [
                'hold_id'           => $holdId,
                'hold_amount_minor' => $holdAmountMinor,
                'exposure_type'     => $exposureType,
            ], $correlationId);
        });

        // Publish OUTSIDE DB transaction — notifies Reconciliation module
        $this->events->publish(new DisputeHoldAuthorisedEvent(
            disputeCaseId:    $caseId,
            paymentAttemptId: $case->payment_attempt_id,
            organisationId:   null,
            holdAmountMinor:  $holdAmountMinor,
            currencyCode:     $case->currency_code,
            exposureType:     $exposureType,
            authorisedBy:     $actorId,
            correlationId:    $correlationId,
            occurredAt:       $now,
        ), "dispute.hold:{$caseId}");

        $this->audit->record(new AuditEventData(
            action:        'dispute.hold_authorised',
            actorId:       $actorId,
            resourceType:  'payment_dispute_case',
            resourceId:    $caseId,
            metadata:      ['hold_amount_minor' => $holdAmountMinor, 'exposure_type' => $exposureType],
        ));

        return $this->requireCase($caseId);
    }

    // ─── US3: Domain module entitlement coordination ─────────────────────────

    /**
     * Coordinate domain entitlement restrictions for a dispute case under review.
     */
    public function coordinateEntitlements(string $caseId, string $correlationId): void
    {
        $case = $this->requireCase($caseId);

        // Resolve purpose type from the payment intent via the attempt
        $attempt = $this->attemptRepository->findById($case->payment_attempt_id);
        $purposeType = $attempt?->payment_intent?->purpose_type ?? 'unknown';

        $handlerKey = "payment.dispute_entitlement.{$purposeType}";

        if (! $this->container->has($handlerKey)) {
            DB::connection('payment')->transaction(function () use ($case, $caseId, $correlationId): void {
                $case->entitlement_action = DisputeEntitlementAction::NoHandler;
                $case->save();
                $this->appendEventRecord($caseId, 'entitlement_no_action', null, [
                    'reason' => 'no handler registered for purpose type',
                ], $correlationId);
            });
            Log::info('DisputeService::coordinateEntitlements no handler', [
                'case_id'      => $caseId,
                'purpose_type' => $purposeType,
            ]);
            return;
        }

        try {
            /** @var \App\Modules\Payment\Domain\Contracts\DisputeEntitlementHandlerInterface $handler */
            $handler = $this->container->make($handlerKey);
            $purposeReference = $attempt?->payment_intent?->purpose_reference ?? $caseId;
            $result = $handler->handleDisputeEntitlement($caseId, $purposeReference, $correlationId);

            DB::connection('payment')->transaction(function () use ($case, $caseId, $result, $correlationId): void {
                $case->entitlement_action = $result->action;

                if ($result->action === DisputeEntitlementAction::PostDrawLiability) {
                    $case->requires_finance_review  = true;
                    $case->liability_exception_type = 'post_draw_raffle';
                }
                $case->save();

                $eventType = match ($result->action) {
                    DisputeEntitlementAction::BlockUnused              => 'entitlement_blocked',
                    DisputeEntitlementAction::ConsumedEvidenceRecorded => 'entitlement_consumed',
                    DisputeEntitlementAction::PostDrawLiability        => 'entitlement_no_action',
                    default                                             => 'entitlement_no_action',
                };
                $this->appendEventRecord($caseId, $eventType, null, [
                    'action'             => $result->action->value,
                    'evidence_reference' => $result->evidenceReference,
                    'notes'              => $result->notes,
                ], $correlationId);
            });
        } catch (\Throwable $e) {
            DB::connection('payment')->transaction(function () use ($case, $caseId, $correlationId, $e): void {
                $case->entitlement_action = DisputeEntitlementAction::CoordinationFailed;
                $case->save();
                $this->appendEventRecord($caseId, 'entitlement_coordination_failed', null, [
                    'error' => $e->getMessage(),
                ], $correlationId);
            });
            Log::error('DisputeService::coordinateEntitlements handler failed', [
                'case_id' => $caseId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    // ─── US4: Provider outcome, journals, hold release ───────────────────────

    /**
     * Record a provider outcome (won / lost / closed_reversed).
     *
     * @throws DisputeCaseNotFoundException
     * @throws DisputeInvalidTransitionException
     */
    public function recordOutcome(string $caseId, DisputeOutcomeRequest $request, string $actorId): PaymentDisputeCaseModel
    {
        $case = $this->requireCase($caseId);
        $now  = now()->toIso8601String();
        $nowMs = (int) (microtime(true) * 1000);

        // Contradictory guard (C2 fix):
        // - Terminal + same outcome = idempotent no-op
        // - Terminal + different outcome OR investigation_required + any outcome = contradictory
        if ($case->status->isTerminal()) {
            if ($case->status === $request->outcome) {
                // Same-outcome replay on terminal case — idempotent no-op
                return $case;
            }
            // Different direction — contradictory
            $this->handleContradictoryOutcome($case, $request, $correlationId = "dispute:contradictory:{$caseId}", $now, $nowMs);
            return $this->requireCase($caseId);
        }

        if ($case->status === PaymentDisputeCaseStatus::InvestigationRequired) {
            $this->handleContradictoryOutcome($case, $request, "dispute:contradictory:{$caseId}", $now, $nowMs);
            return $this->requireCase($caseId);
        }

        if ($case->status !== PaymentDisputeCaseStatus::UnderReview) {
            throw new DisputeInvalidTransitionException($caseId, $case->status->value, $request->outcome->value);
        }

        $correlationId = "dispute:outcome:{$caseId}";

        match ($request->outcome) {
            PaymentDisputeCaseStatus::Won          => $this->recordWonOutcome($case, $request, $actorId, $correlationId, $now),
            PaymentDisputeCaseStatus::Lost          => $this->recordLostOutcome($case, $request, $actorId, $correlationId, $now),
            PaymentDisputeCaseStatus::ClosedReversed => $this->recordReversedOutcome($case, $request, $actorId, $correlationId, $now),
            default => throw new DisputeInvalidTransitionException($caseId, $case->status->value, $request->outcome->value),
        };

        $this->audit->record(new AuditEventData(
            action:       "dispute.outcome.{$request->outcome->value}",
            actorId:      $actorId,
            resourceType: 'payment_dispute_case',
            resourceId:   $caseId,
            metadata:     ['provider_decision_reference' => $request->providerDecisionReference],
        ));

        return $this->requireCase($caseId);
    }

    private function recordWonOutcome(
        PaymentDisputeCaseModel $case,
        DisputeOutcomeRequest   $request,
        string                  $actorId,
        string                  $correlationId,
        string                  $now,
    ): void {
        DB::connection('payment')->transaction(function () use ($case, $request, $actorId, $correlationId, $now): void {
            $case->status                   = PaymentDisputeCaseStatus::Won;
            $case->provider_decision_reference = $request->providerDecisionReference;
            $case->accounting_status        = DisputeAccountingStatus::HoldReleased;
            $case->closed_at                = $now;
            $case->save();

            // Release hold
            $hold = $case->hold;
            if ($hold !== null) {
                $hold->released_at = $now;
                $hold->save();
            }

            $this->appendEventRecord($case->id, 'outcome_won', $actorId, [
                'provider_decision_reference' => $request->providerDecisionReference,
            ], $correlationId);
        });

        // Publish OUTSIDE transaction
        $this->events->publish(new DisputeWonEvent(
            disputeCaseId:            $case->id,
            paymentAttemptId:         $case->payment_attempt_id,
            paymentIntentId:          null,
            organisationId:           null,
            disputedAmountMinor:      $case->disputed_amount_minor,
            currencyCode:             $case->currency_code,
            providerDecisionReference: $request->providerDecisionReference,
            correlationId:            $correlationId,
            occurredAt:               $now,
        ), "dispute.won:{$case->id}");
    }

    private function recordLostOutcome(
        PaymentDisputeCaseModel $case,
        DisputeOutcomeRequest   $request,
        string                  $actorId,
        string                  $correlationId,
        string                  $now,
    ): void {
        $netChargeback = max(0, $case->disputed_amount_minor - $case->prior_refunds_minor);

        $chargebackJournalId = null;
        $feeJournalId        = null;

        // Post correcting journals OUTSIDE DB transaction
        if ($netChargeback > 0 && $this->ledger !== null) {
            $chargebackKey       = "dispute:{$case->id}:chargeback";
            $chargebackJournalId = $this->ledger->postEntry(
                idempotencyKey: $chargebackKey,
                description:    "Chargeback for dispute {$case->id}",
                amountMinor:    $netChargeback,
                currencyCode:   $case->currency_code,
                metadata:       ['dispute_case_id' => $case->id, 'type' => 'chargeback'],
            );
        }

        if ($request->chargebackFeeMinor > 0 && $this->ledger !== null) {
            $feeKey       = "dispute:{$case->id}:fee";
            $feeJournalId = $this->ledger->postEntry(
                idempotencyKey: $feeKey,
                description:    "Chargeback fee for dispute {$case->id}",
                amountMinor:    $request->chargebackFeeMinor,
                currencyCode:   $case->currency_code,
                metadata:       ['dispute_case_id' => $case->id, 'type' => 'chargeback_fee'],
            );
        }

        $accountingStatus = $netChargeback === 0
            ? DisputeAccountingStatus::NoJournalRequired
            : ($feeJournalId !== null
                ? DisputeAccountingStatus::FeePosted
                : DisputeAccountingStatus::CorrectingJournalPosted);

        DB::connection('payment')->transaction(function () use (
            $case, $request, $actorId, $correlationId, $now,
            $netChargeback, $chargebackJournalId, $feeJournalId, $accountingStatus
        ): void {
            $case->status                        = PaymentDisputeCaseStatus::Lost;
            $case->provider_decision_reference   = $request->providerDecisionReference;
            $case->chargeback_ledger_journal_id  = $chargebackJournalId;
            $case->fee_ledger_journal_id         = $feeJournalId;
            $case->accounting_status             = $accountingStatus;
            $case->closed_at                     = $now;
            $case->save();

            $this->appendEventRecord($case->id, 'outcome_lost', $actorId, [
                'net_chargeback_minor'        => $netChargeback,
                'chargeback_fee_minor'        => $request->chargebackFeeMinor,
                'provider_decision_reference' => $request->providerDecisionReference,
            ], $correlationId);
        });

        // Publish OUTSIDE transaction
        $this->events->publish(new DisputeLostEvent(
            disputeCaseId:              $case->id,
            paymentAttemptId:           $case->payment_attempt_id,
            paymentIntentId:            null,
            organisationId:             null,
            disputedAmountMinor:        $case->disputed_amount_minor,
            priorRefundsMinor:          $case->prior_refunds_minor,
            netChargebackMinor:         $netChargeback,
            chargebackFeeMinor:         $request->chargebackFeeMinor,
            currencyCode:               $case->currency_code,
            chargebackLedgerJournalId:  $chargebackJournalId,
            providerDecisionReference:  $request->providerDecisionReference,
            correlationId:              $correlationId,
            occurredAt:                 $now,
        ), "dispute.lost:{$case->id}");
    }

    private function recordReversedOutcome(
        PaymentDisputeCaseModel $case,
        DisputeOutcomeRequest   $request,
        string                  $actorId,
        string                  $correlationId,
        string                  $now,
    ): void {
        $reversalJournalId = null;

        // Post compensating reversal journal OUTSIDE DB transaction
        if ($this->ledger !== null) {
            $reversalKey       = "dispute:{$case->id}:chargeback";
            $reversalJournalId = $this->ledger->postEntry(
                idempotencyKey: $reversalKey,
                description:    "Provider reversal for dispute {$case->id}",
                amountMinor:    $case->disputed_amount_minor,
                currencyCode:   $case->currency_code,
                metadata:       ['dispute_case_id' => $case->id, 'type' => 'reversal'],
            );
        }

        DB::connection('payment')->transaction(function () use (
            $case, $request, $actorId, $correlationId, $now, $reversalJournalId
        ): void {
            $case->status                       = PaymentDisputeCaseStatus::ClosedReversed;
            $case->provider_decision_reference  = $request->providerDecisionReference;
            $case->chargeback_ledger_journal_id = $reversalJournalId;
            $case->accounting_status            = DisputeAccountingStatus::CorrectingJournalPosted;
            $case->closed_at                    = $now;
            $case->save();

            // B2 fix: Update PaymentAttempt::outcome = 'reversed' within same DB transaction
            \App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel::where('id', $case->payment_attempt_id)
                ->update(['outcome' => 'reversed']);

            $this->appendEventRecord($case->id, 'outcome_reversed', $actorId, [
                'reversal_journal_id'         => $reversalJournalId,
                'provider_decision_reference' => $request->providerDecisionReference,
            ], $correlationId);
        });

        // Publish OUTSIDE transaction
        $this->events->publish(new DisputeReversedEvent(
            disputeCaseId:             $case->id,
            paymentAttemptId:          $case->payment_attempt_id,
            paymentIntentId:           null,
            organisationId:            null,
            disputedAmountMinor:       $case->disputed_amount_minor,
            currencyCode:              $case->currency_code,
            reversalLedgerJournalId:   $reversalJournalId,
            providerDecisionReference: $request->providerDecisionReference,
            correlationId:             $correlationId,
            occurredAt:                $now,
        ), "dispute.reversed:{$case->id}");
    }

    private function handleContradictoryOutcome(
        PaymentDisputeCaseModel $case,
        DisputeOutcomeRequest   $request,
        string                  $correlationId,
        string                  $now,
        int                     $nowMs,
    ): void {
        DB::connection('payment')->transaction(function () use ($case, $request, $correlationId, $now): void {
            $case->status = PaymentDisputeCaseStatus::InvestigationRequired;
            $case->save();

            $this->appendEventRecord($case->id, 'contradictory_outcome', null, [
                'incoming_outcome'            => $request->outcome->value,
                'prior_status'                => $case->status->value,
                'provider_decision_reference' => $request->providerDecisionReference,
            ], $correlationId);
        });

        // Publish OUTSIDE transaction
        $this->events->publish(new DisputeLateContradictoryOutcomeEvent(
            disputeCaseId:             $case->id,
            paymentAttemptId:          $case->payment_attempt_id,
            currentStatus:             $case->status->value,
            contradictoryOutcome:      $request->outcome->value,
            providerDecisionReference: $request->providerDecisionReference,
            correlationId:             $correlationId,
            occurredAt:                $now,
            receivedAtEpochMs:         $nowMs,
        ), "dispute.contradictory:{$case->id}:{$nowMs}");
    }

    // ─── US5: Evidence and management ────────────────────────────────────────

    /**
     * Record an evidence reference on a dispute case.
     *
     * @throws DisputeCaseNotFoundException
     * @throws DisputeCaseClosedException
     */
    public function recordEvidence(string $caseId, string $evidenceReference, string $actorId, string $correlationId): void
    {
        $case = $this->requireCase($caseId);

        if ($case->status->isTerminal()) {
            throw new DisputeCaseClosedException($caseId, $case->status->value);
        }

        DB::connection('payment')->transaction(function () use ($case, $caseId, $evidenceReference, $actorId, $correlationId): void {
            $case->evidence_submitted = true;
            $case->save();

            $this->appendEventRecord($caseId, 'evidence_submitted', $actorId, [
                'evidence_reference' => $evidenceReference,
            ], $correlationId);
        });
    }

    // ─── US5: Finance review acknowledgement ─────────────────────────────────

    /**
     * Acknowledge the PostDrawLiability finance-review gate on a dispute case.
     *
     * Clears requires_finance_review, records who acknowledged and when.
     *
     * @throws DisputeCaseNotFoundException
     * @throws DisputeCaseClosedException
     */
    public function acknowledgeFinanceReview(string $caseId, string $actorId): void
    {
        $case = $this->requireCase($caseId);

        if ($case->status->isTerminal()) {
            throw new DisputeCaseClosedException($caseId, $case->status->value);
        }

        $now           = now()->toIso8601String();
        $correlationId = "dispute:finance_review:{$caseId}";

        DB::connection('payment')->transaction(function () use ($case, $caseId, $actorId, $now, $correlationId): void {
            $case->requires_finance_review             = false;
            $case->finance_review_acknowledged_at      = $now;
            $case->finance_review_acknowledged_by      = $actorId;
            $case->save();

            $this->appendEventRecord($caseId, 'finance_review_acknowledged', $actorId, [], $correlationId);
        });

        $this->audit->record(new AuditEventData(
            action:       'dispute.finance_review.acknowledged',
            actorId:      $actorId,
            resourceType: 'payment_dispute_case',
            resourceId:   $caseId,
            metadata:     [],
        ));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function requireCase(string $caseId): PaymentDisputeCaseModel
    {
        $case = $this->caseRepository->findById($caseId);
        if ($case === null) {
            throw new DisputeCaseNotFoundException($caseId);
        }
        return $case;
    }

    private function appendEvent(string $caseId, string $eventType, ?string $actorId, array $payload, string $correlationId): void
    {
        $this->appendEventRecord($caseId, $eventType, $actorId, $payload, $correlationId);
    }

    private function appendEventRecord(string $caseId, string $eventType, ?string $actorId, array $payload, string $correlationId): void
    {
        $event = new DisputeCaseEventModel([
            'id'              => Uuid::uuid7()->toString(),
            'dispute_case_id' => $caseId,
            'event_type'      => $eventType,
            'actor_id'        => $actorId,
            'payload'         => $payload,
            'correlation_id'  => $correlationId,
        ]);
        $event->save();
    }
}
