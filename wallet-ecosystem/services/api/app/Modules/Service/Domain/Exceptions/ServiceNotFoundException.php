<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\Exceptions;

use DomainException;

final class ServiceNotFoundException extends DomainException
{
    public string $errorCode = 'SERVICE_NOT_FOUND';
    public int $httpStatus = 404;
    public function __construct(string $id)
    {
        parent::__construct("Service [{$id}] not found or not available.");
    }
}
