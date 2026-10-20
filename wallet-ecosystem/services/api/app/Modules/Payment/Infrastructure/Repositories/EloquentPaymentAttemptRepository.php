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

    public function findById(string $id): ?PaymentAttemptModel
    {
        return PaymentAttemptModel::query()->find($id);
    }

    public function findActiveForIntent(string $paymentIntentId): ?PaymentAttemptModel
    {
        return PaymentAttemptModel::query()
            ->where('payment_intent_id', $paymentIntentId)
            ->whereNotIn('status', ['succeeded', 'failed', 'cancelled', 'expired'])
            ->latest('initiated_at')
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
            ->whereNotIn('status', ['succeeded', 'failed', 'cancelled', 'expired'])
            ->orderBy('timeout_at')
            ->limit(200)
            ->get()
            ->all();
    }
}
