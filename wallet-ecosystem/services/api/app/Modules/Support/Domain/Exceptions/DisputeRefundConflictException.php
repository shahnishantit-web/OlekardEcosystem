<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Exceptions;

final class DisputeRefundConflictException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $message = 'Dispute refund is already pending confirmation.')
    {
        parent::__construct($message);
        $this->errorCode  = 'DISPUTE_REFUND_CONFLICT';
        $this->httpStatus = 409;
    }
}
