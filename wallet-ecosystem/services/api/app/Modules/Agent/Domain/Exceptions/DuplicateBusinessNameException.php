<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Exceptions;

final class DuplicateBusinessNameException extends \RuntimeException
{
    public function __construct(string $tradingName = '')
    {
        parent::__construct(
            $tradingName !== ''
                ? "An approved agent already uses the trading name '{$tradingName}'."
                : 'An approved agent already uses this trading name.',
        );
    }
}
