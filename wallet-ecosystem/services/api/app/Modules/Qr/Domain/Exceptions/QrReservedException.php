<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\Exceptions;

use DomainException;

final class QrReservedException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct()
    {
        parent::__construct('QR code is currently reserved by another payment in progress.');
        $this->errorCode  = 'QR_RESERVED';
        $this->httpStatus = 409;
    }
}
