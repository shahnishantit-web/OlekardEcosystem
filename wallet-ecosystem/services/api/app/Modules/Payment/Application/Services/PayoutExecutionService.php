<?php

declare(strict_types=1);

// STUB — execution layer blocked pending AD-14 resolution.
// Replace this stub with the full implementation once the payout adapter contract is finalised.

namespace App\Modules\Payment\Application\Services;

final class PayoutExecutionService
{
    public function submitExecution(string $payoutRequestId, string $actorId): never
    {
        throw new \LogicException('PayoutExecutionService is not implemented. Pending AD-14 resolution.');
    }

    public function queryAttempt(string $payoutAttemptId): never
    {
        throw new \LogicException('PayoutExecutionService is not implemented. Pending AD-14 resolution.');
    }
}
