<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Exceptions;

final class DisputeAlreadyResolvedException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $message = 'Dispute is already in a terminal state.')
    {
        parent::__construct($message);
        $this->errorCode  = 'DISPUTE_ALREADY_RESOLVED';
        $this->httpStatus = 422;
    }
}
