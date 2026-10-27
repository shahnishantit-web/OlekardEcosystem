<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

use RuntimeException;

final class DisputeInvalidTransitionException extends RuntimeException
{
    public function __construct(string $caseId, string $fromStatus, string $toStatus)
    {
        parent::__construct(
            "Invalid dispute case state transition for {$caseId}: {$fromStatus} → {$toStatus}"
        );
    }
}
