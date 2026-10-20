<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Exceptions;

final class InvalidDisputeStatusTransitionException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $message = 'Invalid dispute status transition.')
    {
        parent::__construct($message);
        $this->errorCode  = 'INVALID_DISPUTE_STATUS_TRANSITION';
        $this->httpStatus = 422;
    }
}
