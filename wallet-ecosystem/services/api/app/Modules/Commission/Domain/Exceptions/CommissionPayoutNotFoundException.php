<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Exceptions;

final class CommissionPayoutNotFoundException extends \DomainException
{
    public readonly string $errorCode;

    public function __construct(string $payoutId)
    {
        parent::__construct("Commission payout [{$payoutId}] not found.");
        $this->errorCode = 'COMMISSION_PAYOUT_NOT_FOUND';
    }
}
