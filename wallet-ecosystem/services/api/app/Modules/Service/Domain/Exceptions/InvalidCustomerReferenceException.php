<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\Exceptions;

use DomainException;

final class InvalidCustomerReferenceException extends DomainException
{
    public string $errorCode = 'INVALID_CUSTOMER_REFERENCE';
    public int $httpStatus = 422;
    public function __construct(string $reference)
    {
        parent::__construct("Customer reference [{$reference}] is invalid or not found with the provider.");
    }
}
