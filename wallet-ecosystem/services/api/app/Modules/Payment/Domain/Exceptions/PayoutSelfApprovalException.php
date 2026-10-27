<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

use DomainException;

final class PayoutSelfApprovalException extends DomainException
{
    public static function notPermitted(): self
    {
        return new self('Maker cannot approve their own payout reservation.');
    }
}
