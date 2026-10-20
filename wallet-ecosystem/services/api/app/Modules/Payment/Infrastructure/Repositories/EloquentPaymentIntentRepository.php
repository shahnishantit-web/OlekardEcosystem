<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Repositories;

use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use Illuminate\Support\Facades\DB;

final class EloquentPaymentIntentRepository implements PaymentIntentRepositoryInterface
{
    public function save(PaymentIntentModel $model): void
    {
        $model->save();
    }

    public function findById(string $id, bool $lock = false): ?PaymentIntentModel
    {
        $query = PaymentIntentModel::query()->where('id', $id);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function findByIdempotencyKey(string $key, string $payerActorId): ?PaymentIntentModel
    {
        return PaymentIntentModel::query()
            ->where('idempotency_key', $key)
            ->where('payer_actor_id', $payerActorId)
            ->first();
    }

    /** @return PaymentIntentModel[] */
    public function findActiveForPurpose(string $purposeType, string $purposeReference): array
    {
        return PaymentIntentModel::query()
            ->where('purpose_type', $purposeType)
            ->where('purpose_reference', $purposeReference)
            ->whereNotIn('status', ['failed', 'cancelled', 'expired', 'refunded'])
            ->get()
            ->all();
    }

    /** @return PaymentIntentModel[] */
    public function findExpiring(\DateTimeImmutable $before): array
    {
        return PaymentIntentModel::query()
            ->where('expires_at', '<=', $before->format('Y-m-d H:i:s'))
            ->whereIn('status', ['pending', 'awaiting_action'])
            ->orderBy('expires_at')
            ->limit(200)
            ->get()
            ->all();
    }
}
