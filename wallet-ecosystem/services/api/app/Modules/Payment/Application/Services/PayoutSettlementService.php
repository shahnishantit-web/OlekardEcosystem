<?php

declare(strict_types=1);

// STUB — settlement confirmation blocked pending AD-10 and LC14 resolution.
// Replace this stub with the full implementation once unblocked.

namespace App\Modules\Payment\Application\Services;

final class PayoutSettlementService
{
    public function confirmSettlement(string $payoutRequestId, array $providerEvidence, string $actorId): never
    {
        throw new \LogicException('PayoutSettlementService is not implemented. Pending AD-10 and LC14 resolution.');
    }
}
