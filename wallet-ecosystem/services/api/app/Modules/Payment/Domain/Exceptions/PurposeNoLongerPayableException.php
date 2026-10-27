<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class PurposeNoLongerPayableException extends \DomainException
{
    public function __construct(string $reason = 'Purpose is no longer payable at execution time.')
    {
        parent::__construct($reason);
    }
}
