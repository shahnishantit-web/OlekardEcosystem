<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Payment\Domain\Contracts\PaymentOutcomeProcessor;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\ValueObjects\PaymentStatusResult;
use App\Modules\Payment\Infrastructure\Models\ReconciliationExceptionModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class PaymentAttemptTimeoutJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $attemptId,
    ) {
    }

    /**
     * Uniqueness key prevents multiple timeout jobs for the same attempt.
     */
    public function uniqueId(): string
    {
        return 'payment_attempt_timeout:'.$this->attemptId;
    }

    public function handle(
        PaymentAttemptRepositoryInterface  $attemptRepository,
        PaymentIntentRepositoryInterface   $intentRepository,
        PaymentOutcomeProcessor            $orchestration,
    ): void {
        $attempt = $attemptRepository->findById($this->attemptId);
        if ($attempt === null) {
            return;
        }

        // Already in terminal state — nothing to do
        if ($attempt->status->isTerminal() || $attempt->status === PaymentAttemptStatus::Unknown) {
            return;
        }

        $intent = $intentRepository->findById($attempt->payment_intent_id);
        if ($intent === null) {
            return;
        }

        $correlationId = $intent->correlation_id ?? (string) Uuid::uuid7();

        // No provider reference — cannot query adapter; outcome is ambiguous
        if ($attempt->provider_reference_id === null) {
            $this->transitionToUnknown($attempt, $intent, $attemptRepository, $correlationId, 'Timeout without provider reference');
            return;
        }

        // Resolve adapter and query provider status
        $adapterKey = 'payment.adapter.'.$attempt->provider_code;

        if (! app()->bound($adapterKey)) {
            $this->transitionToUnknown($attempt, $intent, $attemptRepository, $correlationId, 'No adapter registered for provider: '.$attempt->provider_code);
            return;
        }

        try {
            $adapter = \App\Modules\Payment\Domain\ValueObjects\PaymentCapabilities::query(app($adapterKey));
        } catch (\App\Modules\Payment\Domain\Exceptions\CapabilityUnsupportedException) {
            $this->transitionToUnknown($attempt, $intent, $attemptRepository, $correlationId, 'Query capability unsupported; manual resolution required.');
            return;
        }

        $statusResult = $adapter->queryPaymentStatus($attempt->provider_reference_id);

        match ($statusResult->status) {
            PaymentAttemptStatus::Succeeded => $orchestration->handleOutcome([
                'payment_intent_id'     => $intent->id,
                'payment_attempt_id'    => $this->attemptId,
                'outcome'               => 'succeeded',
                'provider_reference_id' => $statusResult->providerReferenceId,
                'fee_minor'             => 0,
                'correlation_id'        => $correlationId,
            ]),
            PaymentAttemptStatus::Failed => $orchestration->handleOutcome([
                'payment_intent_id'  => $intent->id,
                'payment_attempt_id' => $this->attemptId,
                'outcome'            => 'failed',
                'failure_reason'     => $statusResult->failureReason ?? 'timeout',
                'correlation_id'     => $correlationId,
            ]),
            // All other statuses (Processing, AwaitingAction, Submitting, etc.) are ambiguous —
            // P06: transition to UNKNOWN; intent stays non-terminal; wallet hold is preserved
            default => $this->transitionToUnknown(
                $attempt,
                $intent,
                $attemptRepository,
                $correlationId,
                'Ambiguous provider status on timeout: '.$statusResult->status->value,
            ),
        };
    }

    /**
     * Transition the attempt to UNKNOWN state. The intent is left unchanged
     * (non-terminal) and the wallet hold is preserved — the unresolved charge
     * must be reconciled before any hold release or expiry occurs.
     */
    private function transitionToUnknown(
        \App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel $attempt,
        \App\Modules\Payment\Infrastructure\Models\PaymentIntentModel  $intent,
        PaymentAttemptRepositoryInterface $attemptRepository,
        string $correlationId,
        string $reason,
    ): void {
        DB::connection('payment')->transaction(function () use (
            $attempt, $intent, $attemptRepository, $reason
        ): void {
            // Reload with lock to avoid lost-update with concurrent callbacks
            $locked = $attemptRepository->findById($attempt->id, lock: true);
            if ($locked === null || $locked->status->isTerminal() || $locked->status === PaymentAttemptStatus::Unknown) {
                return;
            }

            \App\Modules\Payment\Domain\StateMachines\PaymentAttemptStateMachine::assertValidTransition(
                $locked->status,
                PaymentAttemptStatus::Unknown,
            );

            $locked->status = PaymentAttemptStatus::Unknown;
            $attemptRepository->save($locked);

            // Intent status is intentionally NOT changed — the financial outcome is unresolved

            DB::connection('payment')->table('payment.reconciliation_exceptions')->insertOrIgnore([
                'id'                   => (string) Uuid::uuid7(),
                'exception_type'       => 'unknown_outcome',
                'status'               => 'open',
                'payment_intent_id'    => $intent->id,
                'payment_attempt_id'   => $locked->id,
                'provider_code'        => $locked->provider_code,
                'description'          => $reason,
                'detected_amount_minor' => null,
                'expected_amount_minor' => null,
                'provider_reference_id' => $locked->provider_reference_id,
                'investigation_notes'  => null,
                'resolved_by'          => null,
                'resolved_at'          => null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        });
    }
}
