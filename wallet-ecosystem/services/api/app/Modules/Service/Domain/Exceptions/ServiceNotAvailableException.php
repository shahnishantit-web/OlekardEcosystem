<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\Exceptions;

use DomainException;

final class ServiceNotAvailableException extends DomainException
{
    public string $errorCode = 'SERVICE_NOT_AVAILABLE';
    public int $httpStatus = 422;
    public function __construct(string $serviceId)
    {
        parent::__construct("Service [{$serviceId}] is not available for payments.");
    }
}
