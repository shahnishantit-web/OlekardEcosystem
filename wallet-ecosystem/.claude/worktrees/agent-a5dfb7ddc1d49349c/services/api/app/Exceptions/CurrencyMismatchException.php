<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Modules\Platform\Domain\ValueObjects\Currency;
use RuntimeException;

final class CurrencyMismatchException extends RuntimeException
{
    public function __construct(Currency $a, Currency $b)
    {
        parent::__construct(
            "Cannot perform arithmetic on different currencies: {$a->getCode()} and {$b->getCode()}.",
        );
    }
}
