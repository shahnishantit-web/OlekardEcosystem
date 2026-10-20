<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Exceptions;

final class PayoutCancellationNotAllowedException extends \DomainException
{
    public readonly string $errorCode;

    public function __construct(string $payoutId, string $status)
    {
        parent::__construct(
            "Commission payout [{$payoutId}] cannot be cancelled in status [{$status}]. Only deferred payouts may be cancelled."
        );
        $this->errorCode = 'PAYOUT_CANCELLATION_NOT_ALLOWED';
    }
}
