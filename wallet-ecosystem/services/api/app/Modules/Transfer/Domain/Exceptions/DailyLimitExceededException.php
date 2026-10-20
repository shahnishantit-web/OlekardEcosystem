<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Domain\Exceptions;

final class DailyLimitExceededException extends \RuntimeException
{
    public function __construct(public readonly int $remaining)
    {
        parent::__construct("Daily transfer limit exceeded. Remaining capacity: {$remaining}.");
    }
}
