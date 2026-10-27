<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum PayoutBeneficiaryStatus: string
{
    case PendingVerification = 'pending_verification';
    case Verified            = 'verified';
    case Suspended           = 'suspended';
    case Rejected            = 'rejected';

    public function isPayable(): bool
    {
        return $this === self::Verified;
    }
}
