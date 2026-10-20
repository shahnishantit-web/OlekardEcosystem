<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Domain\Exceptions;

use RuntimeException;

final class WithdrawalRiskRejectedException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct("Withdrawal rejected by risk engine: {$reason}");
    }
}
