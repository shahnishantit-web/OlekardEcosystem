<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Infrastructure\Models\PayoutReservationModel;
use Illuminate\Database\Eloquent\Collection;

interface PayoutReservationRepositoryInterface
{
    public function findByPayoutRequest(string $payoutRequestId): ?PayoutReservationModel;

    /** @return Collection<int, PayoutReservationModel> */
    public function findExpired(): Collection;

    public function save(PayoutReservationModel $model): void;
}
