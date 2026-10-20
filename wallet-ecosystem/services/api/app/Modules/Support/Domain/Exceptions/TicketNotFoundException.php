<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Exceptions;

final class TicketNotFoundException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $message = 'Support ticket not found.')
    {
        $this->errorCode  = 'TICKET_NOT_FOUND';
        $this->httpStatus = 404;
        parent::__construct($message);
    }
}
