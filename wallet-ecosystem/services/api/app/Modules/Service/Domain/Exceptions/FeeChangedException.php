<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\Exceptions;

use DomainException;

final class FeeChangedException extends DomainException
{
    public string $errorCode = 'FEE_CHANGED';
    public int $httpStatus = 409;
    public function __construct(
        public readonly int $newFeeAmount,
        public readonly int $newTotalAmount,
    ) {
        parent::__construct(
            "The service fee has changed. New fee: {$newFeeAmount}, new total: {$newTotalAmount}. Please confirm."
        );
    }
}
