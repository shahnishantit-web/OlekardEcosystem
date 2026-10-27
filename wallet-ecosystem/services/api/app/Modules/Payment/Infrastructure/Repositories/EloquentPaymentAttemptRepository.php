<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Repositories;

use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;

final class EloquentPaymentAttemptRepository implements PaymentAttemptRepositoryInterface
{
    public function save(PaymentAttemptModel $model): void
    {
        $model->save();
    }

    public function findById(string $id, bool $lock = false): ?PaymentAttemptModel
    {
        $query = PaymentAttemptModel::query();
        if ($lock) $query->lockForUpdate();
        return $query->find($id);
    }

    public function findActiveForIntent(string $paymentIntentId): ?PaymentAttemptModel
    {
        return PaymentAttemptModel::query()
            ->where('payment_intent_id', $paymentIntentId)
            // 'unknown' and 'timed_out' excluded — those are ambiguous-outcome states
            // handled by findUnknownForIntent(); excluding them allows same-provider retry
            ->whereNotIn('status', ['succeeded', 'failed', 'cancelled', 'expired', 'unknown', 'timed_out'])
            ->latest('initiated_at')
            ->first();
    }

    public function findUnknownForIntent(string $paymentIntentId): ?PaymentAttemptModel
    {
        return PaymentAttemptModel::query()
            ->where('payment_intent_id', $paymentIntentId)
            ->whereIn('status', ['unknown', 'timed_out'])
            ->latest('created_at')
            ->first();
    }

    public function findByIdempotencyKey(string $key): ?PaymentAttemptModel
    {
        return PaymentAttemptModel::query()
            ->where('idempotency_key', $key)
            ->first();
    }

    /** @return PaymentAttemptModel[] */
    public function findTimedOut(\DateTimeImmutable $before): array
    {
        return PaymentAttemptModel::query()
            ->where('timeout_at', '<=', $before->format('Y-m-d H:i:s'))
            // include 'submitting' — persisted before adapter call, may be stuck
            ->whereNotIn('status', ['succeeded', 'failed', 'cancelled', 'expired', 'unknown', 'timed_out'])
            ->orderBy('timeout_at')
            ->limit(200)
            ->get()
            ->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, PaymentAttemptModel>
     */
    public function findStuckInSubmitting(int $secondsOld): \Illuminate\Support\Collection
    {
        return PaymentAttemptModel::query()
            ->where('status', 'submitting')
            ->where('initiated_at', '<', now()->subSeconds($secondsOld))
            ->orderBy('initiated_at')
            ->get();
    }

    public function findSucceededForIntent(string $paymentIntentId): ?PaymentAttemptModel
    {
        return PaymentAttemptModel::query()
            ->where('payment_intent_id', $paymentIntentId)
            ->where('status', 'succeeded')
            ->latest('confirmed_at')
            ->first();
    }
}
