<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Exceptions;

final class CommissionAlreadyProcessedException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $commissionId)
    {
        parent::__construct(
            "Commission [{$commissionId}] is already in approved, paid, or reversed status and cannot be included in a new payout."
        );
        $this->errorCode  = 'COMMISSION_ALREADY_PROCESSED';
        $this->httpStatus = 400;
    }
}
