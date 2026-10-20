<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class ProductionBlockedException extends \RuntimeException
{
    public function __construct(string $adapterIdentifier)
    {
        parent::__construct(
            "Payment adapter [{$adapterIdentifier}] is a development mock and cannot be used in production. "
            . 'Set APP_ENV to local or testing to use mock adapters.'
        );
    }
}
