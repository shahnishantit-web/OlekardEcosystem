<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

final class PayoutOperationId
{
    public static function fromReservation(string $reservationId): string
    {
        return bin2hex(hash('sha256', "payout:{$reservationId}", true));
    }
}
