<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\Payment\Application\Services\PayoutReservationService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class PayoutReservationExpiryJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;

    public int $tries = 1;

    public function uniqueId(): string
    {
        return 'payout-reservation-expiry';
    }

    public function handle(PayoutReservationService $service): void
    {
        $service->expireStale();
    }
}
