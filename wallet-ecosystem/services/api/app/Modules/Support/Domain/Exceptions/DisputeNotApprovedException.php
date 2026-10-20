<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Exceptions;

final class DisputeNotApprovedException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $message = 'Dispute has not been approved.')
    {
        parent::__construct($message);
        $this->errorCode  = 'DISPUTE_NOT_APPROVED';
        $this->httpStatus = 422;
    }
}
