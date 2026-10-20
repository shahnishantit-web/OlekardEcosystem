<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Exceptions;

final class DisputeNotFoundException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $message = 'Dispute not found.')
    {
        parent::__construct($message);
        $this->errorCode  = 'DISPUTE_NOT_FOUND';
        $this->httpStatus = 404;
    }
}
