<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Exceptions;

final class DisputeRefundException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $message = 'Dispute refund processing failed due to an external service error.')
    {
        parent::__construct($message);
        $this->errorCode  = 'DISPUTE_REFUND_FAILED';
        $this->httpStatus = 502;
    }
}
