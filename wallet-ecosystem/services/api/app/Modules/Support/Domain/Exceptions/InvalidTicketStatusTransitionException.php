<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Exceptions;

final class InvalidTicketStatusTransitionException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $message = 'Invalid ticket status transition.')
    {
        $this->errorCode  = 'INVALID_TICKET_STATUS_TRANSITION';
        $this->httpStatus = 422;
        parent::__construct($message);
    }
}
