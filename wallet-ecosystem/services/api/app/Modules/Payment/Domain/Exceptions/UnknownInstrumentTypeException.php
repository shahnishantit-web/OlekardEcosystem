<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class UnknownInstrumentTypeException extends \RuntimeException
{
    public function __construct(string $instrumentType)
    {
        parent::__construct("Unknown payment instrument type: '{$instrumentType}'.");
    }
}
