<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\Exceptions;

use DomainException;

final class QrExpiredException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct()
    {
        parent::__construct('QR code has expired.');
        $this->errorCode  = 'QR_EXPIRED';
        $this->httpStatus = 422;
    }
}
