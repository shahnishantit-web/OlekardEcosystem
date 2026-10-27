<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

use DomainException;

final class PayoutReservationExpiredException extends DomainException
{
    public static function forReservation(string $reservationId): self
    {
        return new self(
            sprintf('Payout reservation "%s" has expired and cannot be approved.', $reservationId)
        );
    }
}
