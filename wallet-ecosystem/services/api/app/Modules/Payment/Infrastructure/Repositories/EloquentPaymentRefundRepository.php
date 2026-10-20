<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Repositories;

use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use Illuminate\Support\Facades\DB;

final class EloquentPaymentRefundRepository implements PaymentRefundRepositoryInterface
{
    public function save(PaymentRefundModel $model): void
    {
        $model->save();
    }

    public function findById(string $id): ?PaymentRefundModel
    {
        return PaymentRefundModel::query()->find($id);
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

    public function sumConfirmedRefundsForAttempt(string $attemptId): int
    {
        return (int) PaymentRefundModel::query()
            ->where('payment_attempt_id', $attemptId)
            ->where('status', 'succeeded')
            ->lockForUpdate()
            ->sum(DB::raw('amount_minor'));
    }
}
