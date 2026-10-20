<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain\Exceptions;

use InvalidArgumentException;

final class InvalidFeeRateException extends InvalidArgumentException
{
    public function __construct(string $reason)
    {
        parent::__construct("Invalid fee rate: {$reason}");
    }
}
