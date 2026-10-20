<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\Exceptions;

use DomainException;

final class QrPayloadMismatchException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct()
    {
        parent::__construct('Payment amount does not match the QR code amount.');
        $this->errorCode  = 'QR_PAYLOAD_MISMATCH';
        $this->httpStatus = 422;
    }
}
