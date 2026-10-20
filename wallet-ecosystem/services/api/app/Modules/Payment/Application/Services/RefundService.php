<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Contracts\PaymentAdapterInterface;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Domain\Enums\ReconciliationExceptionType;
use App\Modules\Payment\Domain\Events\RefundConfirmedEvent;
use App\Modules\Payment\Domain\Exceptions\CapabilityUnsupportedException;
use App\Modules\Payment\Domain\Exceptions\InvalidStateTransitionException;
use App\Modules\Payment\Domain\Exceptions\RefundLimitExceededException;
use App\Modules\Payment\Domain\ValueObjects\PaymentRefundRequest;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
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
    ) {
    }

    /**
     * T138: Request a refund — creates a PaymentRefund record after cumulative guard.
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
     * } $dto
     * @throws RefundLimitExceededException     When cumulative refunds would exceed original.
     * @throws CapabilityUnsupportedException   When provider does not support refunds.
     */
    public function request(array $dto): PaymentRefundModel
    {
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

            // T139: Cumulative refund guard with SELECT FOR UPDATE
            $confirmedRefunds = $this->refundRepository->sumConfirmedRefundsForAttempt($attemptId);
            $newTotal         = $confirmedRefunds + $dto['amount_minor'];

            if ($newTotal > $attempt->amount_minor) {
                throw new RefundLimitExceededException(
                    requestedMinor: $dto['amount_minor'],
                    confirmedMinor: $confirmedRefunds,
                    originalMinor:  $attempt->amount_minor,
                    currencyCode:   $dto['currency_code'],
                );
            }

            // T140: Approval threshold check
            $approvalThreshold = (int) $this->config->getOrDefault(
                'payment.refund_approval_threshold_minor',
                50000
            );
            $requiresApproval = $dto['amount_minor'] >= $approvalThreshold;
            $initialStatus    = $requiresApproval
                ? PaymentRefundStatus::Requested
                : PaymentRefundStatus::Processing;

            // Create refund record
            $refund = new PaymentRefundModel();
            $refund->id                = (string) Uuid::uuid7();
            $refund->payment_intent_id = $dto['payment_intent_id'];
            $refund->payment_attempt_id = $attemptId;
            $refund->amount_minor      = $dto['amount_minor'];
            $refund->status            = $initialStatus;
            $refund->idempotency_key   = $dto['idempotency_key'];
            $refund->requested_by      = $dto['requested_by'];
            $refund->reason            = $dto['reason'] ?? null;
            $refund->requested_at      = now();
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
     * T140: Approve a pending-approval refund.
     * Only callable by actors with payment.refunds.approve permission.
     */
    public function approve(string $refundId, string $approverActorId): void
    {
        DB::connection('payment')->transaction(function () use ($refundId, $approverActorId): void {
            $refund = $this->refundRepository->findById($refundId);
            if ($refund === null) {
                throw new \RuntimeException("Refund not found: {$refundId}");
            }

            if ($refund->status !== PaymentRefundStatus::Requested) {
                throw new InvalidStateTransitionException(
                    from: $refund->status->value,
                    to:   PaymentRefundStatus::Processing->value,
                );
            }

            $refund->status    = PaymentRefundStatus::Processing;
            $refund->approved_by = $approverActorId;
            $this->refundRepository->save($refund);

            $this->audit->record(new AuditEventData(
                action:        'payment.refund.approved',
                actorId:       $approverActorId,
                actorType:     'user',
                resourceType:  'payment_refund',
                resourceId:    $refundId,
                correlationId: $refundId,
                outcome:       'success',
                afterState:    ['refund_id' => $refundId, 'approved_by' => $approverActorId],
            ));
        });
    }

    /**
     * Initiate a processing refund with the provider adapter.
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

        $result = $adapter->initiateRefund(new PaymentRefundRequest(
            paymentIntentId:    $refund->payment_intent_id,
            paymentAttemptId:   $refund->payment_attempt_id,
            refundId:           $refundId,
            amountMinor:        $refund->amount_minor,
            currencyCode:       $attempt->currency_code ?? '',
            correlationId:      $correlationId,
            idempotencyKey:     $refund->idempotency_key ?? $refundId,
            providerReferenceId: $attempt->provider_reference_id,
        ));

        // For synchronous providers (wallet), confirm immediately
        if ($result->status === \App\Modules\Payment\Domain\Enums\PaymentRefundStatus::Succeeded) {
            $this->confirm($refundId, $result->providerRefundReference ?? $refundId, $correlationId);
        }
    }

    /**
     * T138: Confirm refund after provider verification.
     * Publishes refund.confirmed outbox event.
     */
    public function confirm(string $refundId, string $providerRefundRef, string $correlationId): void
    {
        DB::connection('platform')->transaction(function () use ($refundId, $providerRefundRef, $correlationId): void {
            $refund = $this->refundRepository->findById($refundId);
            if ($refund === null) {
                throw new \RuntimeException("Refund not found: {$refundId}");
            }

            $refund->status                   = PaymentRefundStatus::Succeeded;
            $refund->provider_refund_reference = $providerRefundRef;
            $refund->confirmed_at             = now();
            $this->refundRepository->save($refund);

            // Sum all confirmed refunds to check if fully refunded
            $totalConfirmed = $this->refundRepository->sumConfirmedRefundsForAttempt(
                $refund->payment_attempt_id
            );

            $attempt    = $this->attemptRepository->findById($refund->payment_attempt_id);
            $isFullRefund = $attempt !== null && $totalConfirmed >= $attempt->amount_minor;

            // Publish outbox event
            $this->events->publish(new RefundConfirmedEvent(
                paymentIntentId:        $refund->payment_intent_id,
                paymentRefundId:        $refundId,
                purposeType:            '',
                purposeReference:       '',
                organisationId:         '',
                refundAmountMinor:      $refund->amount_minor,
                currencyCode:           $attempt?->currency_code ?? '',
                isFullRefund:           $isFullRefund,
                remainingRefundableMinor: $attempt !== null
                    ? max(0, $attempt->amount_minor - $totalConfirmed)
                    : 0,
                correlationId:          $correlationId,
                occurredAt:             new \DateTimeImmutable(),
            ));
        });
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
     * Resolve the adapter for a given provider code.
     */
    private function resolveAdapter(string $providerCode): PaymentAdapterInterface
    {
        $key = 'payment.adapter.'.$providerCode;
        if (! $this->container->bound($key)) {
            throw new \RuntimeException("No payment adapter registered for provider: {$providerCode}");
        }

        /** @var PaymentAdapterInterface */
        return $this->container->make($key);
    }
}
