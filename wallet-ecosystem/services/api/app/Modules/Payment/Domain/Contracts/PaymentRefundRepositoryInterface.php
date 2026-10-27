<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use Illuminate\Pagination\LengthAwarePaginator;

interface PaymentRefundRepositoryInterface
{
    public function save(PaymentRefundModel $model): void;

    public function findById(string $id): ?PaymentRefundModel;

    public function findByProviderSubmittedRef(string $providerSubmittedRef): ?PaymentRefundModel;

    /** @return PaymentRefundModel[] */
    public function findByIntentId(string $paymentIntentId): array;

    /**
     * Sum confirmed refund amounts for an attempt (legacy — includes only terminal success states).
     * Prefer sumReservedRefundsForAttempt() for new refund request guards.
     */
    public function sumConfirmedRefundsForAttempt(string $attemptId): int;

    /**
     * Sum the amount of all non-terminal (in-flight + succeeded) refunds for an attempt.
     * Uses SELECT FOR UPDATE SKIP LOCKED to prevent concurrent over-refund while avoiding deadlocks.
     * MUST be called inside a DB transaction.
     */
    public function sumReservedRefundsForAttempt(string $attemptId): int;

    /**
     * Flexible filtered list for admin and monitoring views.
     *
     * Supported filters: status (string|string[]), payment_intent_id, payment_attempt_id,
     *   provider_submitted_ref, requested_from (datetime), requested_to (datetime).
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<PaymentRefundModel>
     */
    public function listFiltered(array $filters, int $page, int $perPage): LengthAwarePaginator;

    /** @return LengthAwarePaginator<PaymentRefundModel> */
    public function listByStatus(PaymentRefundStatus $status, int $page, int $perPage): LengthAwarePaginator;
}
