<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class UnknownPurposeTypeException extends \DomainException
{
    public function __construct(string $purposeType)
    {
        parent::__construct("No BusinessPurposeResolver registered for purpose type '{$purposeType}'.");
    }
}
