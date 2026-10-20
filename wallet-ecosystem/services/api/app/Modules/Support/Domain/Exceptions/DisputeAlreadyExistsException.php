<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Exceptions;

final class DisputeAlreadyExistsException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $message = 'An active dispute already exists for this transaction.')
    {
        parent::__construct($message);
        $this->errorCode  = 'DISPUTE_ALREADY_EXISTS';
        $this->httpStatus = 409;
    }
}
