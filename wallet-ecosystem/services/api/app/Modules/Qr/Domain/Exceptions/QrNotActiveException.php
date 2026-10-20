<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\Exceptions;

use DomainException;

final class QrNotActiveException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct()
    {
        parent::__construct('QR code is not active.');
        $this->errorCode  = 'QR_NOT_ACTIVE';
        $this->httpStatus = 422;
    }
}
