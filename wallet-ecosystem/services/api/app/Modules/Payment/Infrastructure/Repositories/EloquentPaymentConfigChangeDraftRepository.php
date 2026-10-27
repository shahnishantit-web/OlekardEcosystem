<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Repositories;

use App\Modules\Payment\Domain\Contracts\PaymentConfigChangeDraftRepositoryInterface;
use App\Modules\Payment\Domain\Enums\ConfigChangeDraftStatus;
use App\Modules\Payment\Infrastructure\Models\PaymentConfigChangeDraftModel;
use Illuminate\Support\Collection;

final class EloquentPaymentConfigChangeDraftRepository implements PaymentConfigChangeDraftRepositoryInterface
{
    public function create(array $data): PaymentConfigChangeDraftModel
    {
        $draft = new PaymentConfigChangeDraftModel();
        $draft->fill($data);
        $draft->save();

        return $draft;
    }

    public function findById(string $id): ?PaymentConfigChangeDraftModel
    {
        return PaymentConfigChangeDraftModel::query()->find($id);
    }

    public function findByIdempotencyKey(string $key): ?PaymentConfigChangeDraftModel
    {
        return PaymentConfigChangeDraftModel::query()
            ->where('idempotency_key', $key)
            ->first();
    }

    public function findPendingByProviderId(string $providerId): Collection
    {
        return PaymentConfigChangeDraftModel::query()
            ->where('provider_id', $providerId)
            ->where('status', ConfigChangeDraftStatus::PendingApproval->value)
            ->orderByDesc('created_at')
            ->get();
    }

    public function updateStatus(
        string $id,
        ConfigChangeDraftStatus $status,
        ?string $reviewerActorId,
        ?string $notes,
    ): PaymentConfigChangeDraftModel {
        /** @var PaymentConfigChangeDraftModel $draft */
        $draft = PaymentConfigChangeDraftModel::query()->findOrFail($id);

        $draft->status = $status;

        if ($reviewerActorId !== null) {
            $draft->reviewed_by_actor_id = $reviewerActorId;
            $draft->reviewed_at          = now();
        }

        if ($notes !== null) {
            $draft->review_notes = $notes;
        }

        $draft->save();

        return $draft;
    }
}
