<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

use DomainException;

final class InsufficientPayableBalanceException extends DomainException
{
    public static function forAmount(int $requestedMinor, int $availableMinor): self
    {
        return new self(
            sprintf(
                'Requested %d minor units exceeds the payable balance of %d minor units.',
                $requestedMinor,
                $availableMinor,
            )
        );
    }
}
