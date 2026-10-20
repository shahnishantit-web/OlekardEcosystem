<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Exceptions;

final class CommissionAlreadyReversedException extends \DomainException
{
    public readonly string $errorCode;

    public function __construct(string $commissionId)
    {
        parent::__construct("Commission [{$commissionId}] has already been reversed.");
        $this->errorCode = 'COMMISSION_ALREADY_REVERSED';
    }
}
