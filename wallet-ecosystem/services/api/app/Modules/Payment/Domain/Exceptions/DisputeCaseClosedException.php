<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

use RuntimeException;

final class DisputeCaseClosedException extends RuntimeException
{
    public function __construct(string $caseId, string $currentStatus)
    {
        parent::__construct(
            "Dispute case {$caseId} is already closed or terminal (status: {$currentStatus})"
        );
    }
}
