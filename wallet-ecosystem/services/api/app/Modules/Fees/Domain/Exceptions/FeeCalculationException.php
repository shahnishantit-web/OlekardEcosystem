<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain\Exceptions;

use RuntimeException;

final class FeeCalculationException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct("Fee calculation failed: {$reason}");
    }
}
