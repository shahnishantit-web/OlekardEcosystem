<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Exceptions;

final class DisputeAlreadyEscalatedException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $message = 'Dispute is already escalated.')
    {
        parent::__construct($message);
        $this->errorCode  = 'DISPUTE_ALREADY_ESCALATED';
        $this->httpStatus = 409;
    }
}
