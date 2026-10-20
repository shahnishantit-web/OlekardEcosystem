<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class CapabilityUnsupportedException extends \RuntimeException
{
    public function __construct(string $providerCode, string $capability)
    {
        parent::__construct(
            "Provider [{$providerCode}] does not support capability [{$capability}]."
        );
    }
}
