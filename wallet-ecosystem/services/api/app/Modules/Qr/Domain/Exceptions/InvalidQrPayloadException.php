<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\Exceptions;

use DomainException;

final class InvalidQrPayloadException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct(string $reason = 'Invalid or tampered QR payload.')
    {
        parent::__construct($reason);
        $this->errorCode  = 'INVALID_QR_PAYLOAD';
        $this->httpStatus = 422;
    }
}
