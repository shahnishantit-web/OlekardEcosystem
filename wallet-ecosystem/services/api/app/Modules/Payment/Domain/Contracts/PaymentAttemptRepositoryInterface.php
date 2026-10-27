<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;

interface PaymentAttemptRepositoryInterface
{
    public function save(PaymentAttemptModel $model): void;

    public function findById(string $id, bool $lock = false): ?PaymentAttemptModel;

    public function findActiveForIntent(string $paymentIntentId): ?PaymentAttemptModel;

    /**
     * Find an attempt in UNKNOWN (or legacy timed_out) state for the given intent.
     * Used by the UNSAFE_PROVIDER_SWITCH guard and the expireIntent() deferral check.
     */
    public function findUnknownForIntent(string $paymentIntentId): ?PaymentAttemptModel;

    public function findByIdempotencyKey(string $key): ?PaymentAttemptModel;

    /**
     * Returns non-terminal attempts whose timeout_at has passed, including
     * attempts in 'submitting' state (persisted but adapter not yet called).
     *
     * @return PaymentAttemptModel[]
     */
    public function findTimedOut(\DateTimeImmutable $before): array;

    /**
     * P07 US2: Find attempts stuck in SUBMITTING state for longer than $secondsOld.
     * Used by PaymentAttemptCrashRecoveryJob.
     *
     * @return \Illuminate\Support\Collection<int, PaymentAttemptModel>
     */
    public function findStuckInSubmitting(int $secondsOld): \Illuminate\Support\Collection;

    /**
     * P08 US2: Find the succeeded attempt for a given intent.
     * Used by RepairSingleAccountingJob to retrieve the attempt for ledger posting.
     * Returns null if no succeeded attempt exists.
     */
    public function findSucceededForIntent(string $paymentIntentId): ?PaymentAttemptModel;
}
