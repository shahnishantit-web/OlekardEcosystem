<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Repositories;

use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class EloquentPaymentRefundRepository implements PaymentRefundRepositoryInterface
{
    /** Terminal statuses — rows in these states do NOT hold a refund reservation. */
    private const TERMINAL_STATUSES = [
        'confirmed',
        'definitive_failure',
        'succeeded',
        'failed',
        'cancelled',
        'rejected',
    ];

    public function save(PaymentRefundModel $model): void
    {
        $model->save();
    }

    public function findById(string $id): ?PaymentRefundModel
    {
        return PaymentRefundModel::query()->find($id);
    }

    public function findByProviderSubmittedRef(string $providerSubmittedRef): ?PaymentRefundModel
    {
        return PaymentRefundModel::query()
            ->where('provider_submitted_ref', $providerSubmittedRef)
            ->first();
    }

    /** @return PaymentRefundModel[] */
    public function findByIntentId(string $paymentIntentId): array
    {
        return PaymentRefundModel::query()
            ->where('payment_intent_id', $paymentIntentId)
            ->orderBy('requested_at')
            ->get()
            ->all();
    }

    /**
     * Legacy: sum only terminal-success rows. Retained for backward compat.
     * New guards should call sumReservedRefundsForAttempt().
     */
    public function sumConfirmedRefundsForAttempt(string $attemptId): int
    {
        return (int) PaymentRefundModel::query()
            ->where('payment_attempt_id', $attemptId)
            ->whereIn('status', ['succeeded', 'confirmed'])
            ->lockForUpdate()
            ->sum(DB::raw('amount_minor'));
    }

    public function sumReservedRefundsForAttempt(string $attemptId): int
    {
        return (int) PaymentRefundModel::query()
            ->where('payment_attempt_id', $attemptId)
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->lock('for update skip locked')
            ->sum(DB::raw('amount_minor'));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<PaymentRefundModel>
     */
    public function listFiltered(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = PaymentRefundModel::query();

        if (isset($filters['status'])) {
            $statuses = (array) $filters['status'];
            $query->whereIn('status', $statuses);
        }

        if (isset($filters['payment_intent_id'])) {
            $query->where('payment_intent_id', $filters['payment_intent_id']);
        }

        if (isset($filters['payment_attempt_id'])) {
            $query->where('payment_attempt_id', $filters['payment_attempt_id']);
        }

        if (isset($filters['provider_submitted_ref'])) {
            $query->where('provider_submitted_ref', $filters['provider_submitted_ref']);
        }

        if (isset($filters['requested_from'])) {
            $query->where('requested_at', '>=', $filters['requested_from']);
        }

        if (isset($filters['requested_to'])) {
            $query->where('requested_at', '<=', $filters['requested_to']);
        }

        return $query->orderByDesc('requested_at')->paginate(perPage: $perPage, page: $page);
    }

    /** @return LengthAwarePaginator<PaymentRefundModel> */
    public function listByStatus(PaymentRefundStatus $status, int $page, int $perPage): LengthAwarePaginator
    {
        return PaymentRefundModel::query()
            ->where('status', $status->value)
            ->orderByDesc('requested_at')
            ->paginate(perPage: $perPage, page: $page);
    }
}
