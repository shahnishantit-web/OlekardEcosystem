<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\Exceptions;

use DomainException;

final class QrAlreadyUsedException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct()
    {
        parent::__construct('QR code has already been used.');
        $this->errorCode  = 'QR_ALREADY_USED';
        $this->httpStatus = 422;
    }
}
