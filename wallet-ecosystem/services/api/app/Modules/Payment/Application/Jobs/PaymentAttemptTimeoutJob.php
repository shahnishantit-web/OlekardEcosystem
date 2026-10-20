<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Payment\Application\PaymentOrchestrationService;
use App\Modules\Payment\Domain\Contracts\PaymentAdapterInterface;
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
        PaymentOrchestrationService        $orchestration,
    ): void {
        $attempt = $attemptRepository->findById($this->attemptId);
        if ($attempt === null) {
            return;
        }

        // Already in terminal state — nothing to do
        if ($attempt->status->isTerminal()) {
            return;
        }

        $intent = $intentRepository->findById($attempt->payment_intent_id);
        if ($intent === null) {
            return;
        }

        $correlationId = $intent->correlation_id ?? (string) Uuid::uuid7();

        // No provider reference — cannot query; treat as ambiguous outcome
        if ($attempt->provider_reference_id === null) {
            $this->createReconciliationException($attempt->id, $intent->id, 'unknown_outcome', 'Timeout without provider reference');
            return;
        }

        // Resolve adapter and query provider status
        $adapterKey = 'payment.adapter.'.$attempt->provider_code;

        if (! app()->bound($adapterKey)) {
            $this->createReconciliationException($attempt->id, $intent->id, 'unknown_outcome', 'No adapter registered for provider: '.$attempt->provider_code);
            return;
        }

        /** @var PaymentAdapterInterface $adapter */
        $adapter = app($adapterKey);

        $statusResult = $adapter->queryPaymentStatus($attempt->provider_reference_id);

        match ($statusResult->status) {
            PaymentAttemptStatus::Succeeded => $orchestration->handleOutcome([
                'payment_intent_id'   => $intent->id,
                'payment_attempt_id'  => $this->attemptId,
                'outcome'             => 'succeeded',
                'provider_reference_id' => $statusResult->providerReferenceId,
                'fee_minor'           => 0,
                'correlation_id'      => $correlationId,
            ]),
            PaymentAttemptStatus::Failed => $orchestration->handleOutcome([
                'payment_intent_id'   => $intent->id,
                'payment_attempt_id'  => $this->attemptId,
                'outcome'             => 'failed',
                'failure_reason'      => $statusResult->failureReason ?? 'timeout',
                'correlation_id'      => $correlationId,
            ]),
            default => $this->createReconciliationException(
                $attempt->id,
                $intent->id,
                'unknown_outcome',
                'Ambiguous provider status on timeout: '.$statusResult->status->value
            ),
        };
    }

    private function createReconciliationException(
        string $attemptId,
        string $intentId,
        string $type,
        string $description,
    ): void {
        DB::connection('payment')->table('payment.reconciliation_exceptions')->insertOrIgnore([
            'id'                  => (string) Uuid::uuid7(),
            'type'                => $type,
            'status'              => 'open',
            'payment_intent_id'   => $intentId,
            'payment_attempt_id'  => $attemptId,
            'description'         => $description,
            'detected_amount_minor' => null,
            'expected_amount_minor' => null,
            'provider_code'       => null,
            'investigation_notes' => null,
            'resolved_by'         => null,
            'resolved_at'         => null,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }
}
