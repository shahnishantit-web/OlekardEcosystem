<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Repositories;

use App\Modules\Payment\Domain\Contracts\PayoutReservationRepositoryInterface;
use App\Modules\Payment\Infrastructure\Models\PayoutReservationModel;
use Illuminate\Database\Eloquent\Collection;

final class EloquentPayoutReservationRepository implements PayoutReservationRepositoryInterface
{
    public function findByPayoutRequest(string $payoutRequestId): ?PayoutReservationModel
    {
        /** @var PayoutReservationModel|null */
        return PayoutReservationModel::query()
            ->where('payout_request_id', $payoutRequestId)
            ->first();
    }

    /** @return Collection<int, PayoutReservationModel> */
    public function findExpired(): Collection
    {
        /** @var Collection<int, PayoutReservationModel> */
        return PayoutReservationModel::query()
            ->where('status', 'awaiting_approval')
            ->where('expires_at', '<', now())
            ->get();
    }

    public function save(PayoutReservationModel $model): void
    {
        $model->save();
    }
}
