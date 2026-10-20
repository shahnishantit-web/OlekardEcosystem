<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Exceptions;

final class TicketAlreadyClosedException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $message = 'Support ticket is already closed.')
    {
        $this->errorCode  = 'TICKET_IS_CLOSED';
        $this->httpStatus = 422;
        parent::__construct($message);
    }
}
