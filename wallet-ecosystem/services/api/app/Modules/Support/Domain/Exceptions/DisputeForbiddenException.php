<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Exceptions;

final class DisputeForbiddenException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $message = 'Access denied to dispute.')
    {
        parent::__construct($message);
        $this->errorCode  = 'PERMISSION_DENIED';
        $this->httpStatus = 403;
    }
}
