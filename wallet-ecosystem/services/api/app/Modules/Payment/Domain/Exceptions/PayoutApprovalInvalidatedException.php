<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

use DomainException;

final class PayoutApprovalInvalidatedException extends DomainException
{
    public static function afterAmountChange(): self
    {
        return new self('Payout approval has been invalidated because the reserved amount changed.');
    }

    public static function afterDestinationChange(): self
    {
        return new self('Payout approval has been invalidated because the beneficiary destination changed.');
    }
}
