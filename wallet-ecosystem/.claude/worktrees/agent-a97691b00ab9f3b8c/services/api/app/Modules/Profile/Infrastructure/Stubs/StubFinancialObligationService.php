<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Stubs;

use App\Modules\Profile\Domain\Contracts\FinancialObligationContract;
use App\Modules\Profile\Domain\Contracts\ObligationCheckResult;

/**
 * Fail-safe stub — always reports obligations blocked.
 * Prevents accidental account closure until E06/E11 wire real implementations (ADR-E02-003).
 */
final class StubFinancialObligationService implements FinancialObligationContract
{
    public function checkObligations(string $userId): ObligationCheckResult
    {
        return new ObligationCheckResult(
            hasObligations: true,
            blockedBy: ['obligations_check_unavailable'],
        );
    }
}
