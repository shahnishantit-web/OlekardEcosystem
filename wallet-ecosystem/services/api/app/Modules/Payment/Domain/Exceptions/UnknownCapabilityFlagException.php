<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class UnknownCapabilityFlagException extends \RuntimeException
{
    public function __construct(string $flagKey)
    {
        parent::__construct("Unknown capability flag key: '{$flagKey}'.");
    }
}
