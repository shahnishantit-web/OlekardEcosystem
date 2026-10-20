<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Services;

use App\Modules\Profile\Domain\Contracts\FinancialObligationContract;
use App\Modules\Profile\Domain\Exceptions\ClosureBlockedByObligationsException;

final class ClosureGateService
{
    public function __construct(
        private readonly FinancialObligationContract $obligations,
    ) {}

    /**
     * Check financial obligations and throw if any remain unresolved.
     * Used by account closure flows (self-service and admin).
     *
     * @throws ClosureBlockedByObligationsException
     */
    public function checkAndEnforce(string $userId): void
    {
        $result = $this->obligations->checkObligations($userId);

        if ($result->hasObligations) {
            throw new ClosureBlockedByObligationsException(
                blockedBy: $result->blockedBy,
                message:   'Account closure is blocked by outstanding obligations.',
            );
        }
    }
}
