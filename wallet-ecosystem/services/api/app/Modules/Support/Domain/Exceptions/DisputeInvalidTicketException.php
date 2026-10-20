<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Exceptions;

final class DisputeInvalidTicketException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $message = 'Dispute must be linked to a valid open support ticket.')
    {
        parent::__construct($message);
        $this->errorCode  = 'DISPUTE_INVALID_TICKET';
        $this->httpStatus = 422;
    }
}
