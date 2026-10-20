<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Exceptions;

final class CommissionNotFoundException extends \DomainException
{
    public readonly string $errorCode;

    public function __construct(string $commissionId)
    {
        parent::__construct("Commission [{$commissionId}] not found.");
        $this->errorCode = 'COMMISSION_NOT_FOUND';
    }
}
