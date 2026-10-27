<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Payment\Domain\Contracts\PaymentOutcomeProcessor;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\ValueObjects\PaymentStatusResult;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class PaymentAttemptSingleRecoveryJob implements ShouldQueue
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

    public function uniqueId(): string
    {
        return 'payment_single_recovery:'.$this->attemptId;
    }

    public function handle(
        PaymentAttemptRepositoryInterface $attemptRepo,
        PaymentIntentRepositoryInterface  $intentRepo,
        PaymentOutcomeProcessor           $orchestration,
        WalletModuleContract              $wallet,
    ): void {
        $attempt = $attemptRepo->findById($this->attemptId);
        if ($attempt === null || $attempt->status !== PaymentAttemptStatus::Submitting) {
            return;
        }

        $intent = $intentRepo->findById($attempt->payment_intent_id);
        if ($intent === null) {
            return;
        }

        $correlationId = $intent->correlation_id ?? (string) Uuid::uuid7();
        $adapterKey    = 'payment.adapter.'.$attempt->provider_code;

        if (! app()->bound($adapterKey)) {
            $this->handleCrashUnknown($attempt, $intent, $attemptRepo, $wallet, $correlationId, 'No adapter registered for provider: '.$attempt->provider_code);
            return;
        }

        try {
            $adapter = \App\Modules\Payment\Domain\ValueObjects\PaymentCapabilities::query(app($adapterKey));
        } catch (\App\Modules\Payment\Domain\Exceptions\CapabilityUnsupportedException) {
            $this->handleCrashUnknown($attempt, $intent, $attemptRepo, $wallet, $correlationId, 'Query capability unsupported; manual resolution required.');
            return;
        }

        // Use provider_reference_id if already recorded; fall back to operation_id
        $queryRef    = $attempt->provider_reference_id ?? $attempt->operation_id;
        $statusResult = $adapter->queryPaymentStatus((string) $queryRef);

        match ($statusResult->status) {
            PaymentAttemptStatus::Succeeded => $orchestration->handleOutcome([
                'payment_intent_id'     => $intent->id,
                'payment_attempt_id'    => $this->attemptId,
                'outcome'               => 'succeeded',
                'provider_reference_id' => $statusResult->providerReferenceId,
                'fee_minor'             => 0,
                'correlation_id'        => $correlationId,
            ]),

            // Provider confirmed failed
            PaymentAttemptStatus::Failed => $orchestration->handleOutcome([
                'payment_intent_id'  => $intent->id,
                'payment_attempt_id' => $this->attemptId,
                'outcome'            => 'failed',
                'failure_reason'     => $statusResult->failureReason ?? 'crash_recovery_provider_confirmed_failed',
                'correlation_id'     => $correlationId,
            ]),

            // Absence of a reference is not authenticated proof of non-execution.
            default => $this->handleCrashUnknown(
                    $attempt,
                    $intent,
                    $attemptRepo,
                    $wallet,
                    $correlationId,
                    'Indeterminate provider status on crash recovery: '.$statusResult->status->value,
                ),
        };

        Log::info('CrashRecoverySingle: resolved attempt', [
            'attempt_id'   => $this->attemptId,
            'provider_code' => $attempt->provider_code,
        ]);
    }

    /**
     * Transition to UNKNOWN while retaining any hold until authoritative resolution.
     * Used when provider capability is absent or status is genuinely indeterminate.
     */
    private function handleCrashUnknown(
        \App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel $attempt,
        \App\Modules\Payment\Infrastructure\Models\PaymentIntentModel  $intent,
        PaymentAttemptRepositoryInterface $attemptRepo,
        WalletModuleContract              $wallet,
        string $correlationId,
        string $reason,
    ): void {
        DB::connection('payment')->transaction(function () use (
            $attempt, $intent, $attemptRepo, $reason
        ): void {
            $locked = $attemptRepo->findById($attempt->id, lock: true);
            if ($locked === null || $locked->status !== PaymentAttemptStatus::Submitting) {
                return;
            }

            \App\Modules\Payment\Domain\StateMachines\PaymentAttemptStateMachine::assertValidTransition(
                $locked->status,
                PaymentAttemptStatus::Unknown,
            );

            $locked->status = PaymentAttemptStatus::Unknown;
            $attemptRepo->save($locked);

            DB::connection('payment')->table('payment.reconciliation_exceptions')->insertOrIgnore([
                'id'                    => (string) Uuid::uuid7(),
                'exception_type'        => 'unknown_outcome',
                'status'                => 'open',
                'payment_intent_id'     => $intent->id,
                'payment_attempt_id'    => $locked->id,
                'provider_code'         => $locked->provider_code,
                'description'           => 'Crash recovery: '.$reason,
                'detected_amount_minor' => null,
                'expected_amount_minor' => null,
                'provider_reference_id' => $locked->provider_reference_id,
                'investigation_notes'   => null,
                'resolved_by'           => null,
                'resolved_at'           => null,
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
        });

        Log::critical('CrashRecoverySingle: UNKNOWN outcome — manual reconciliation required', [
            'attempt_id'   => $this->attemptId,
            'intent_id'    => $intent->id,
            'provider_code' => $attempt->provider_code,
            'reason'       => $reason,
        ]);
    }
}
