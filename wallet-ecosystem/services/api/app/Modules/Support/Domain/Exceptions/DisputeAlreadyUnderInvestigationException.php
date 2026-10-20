<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Exceptions;

final class DisputeAlreadyUnderInvestigationException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $message = 'Dispute is already under investigation.')
    {
        parent::__construct($message);
        $this->errorCode  = 'DISPUTE_ALREADY_UNDER_INVESTIGATION';
        $this->httpStatus = 409;
    }
}
