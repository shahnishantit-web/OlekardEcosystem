<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

use RuntimeException;

final class DisputeCaseNotFoundException extends RuntimeException
{
    public function __construct(string $caseId)
    {
        parent::__construct("PaymentDisputeCase not found: {$caseId}");
    }
}
