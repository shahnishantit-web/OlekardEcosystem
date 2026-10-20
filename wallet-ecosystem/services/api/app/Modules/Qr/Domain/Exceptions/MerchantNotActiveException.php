<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\Exceptions;

use DomainException;

final class MerchantNotActiveException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct()
    {
        parent::__construct('Merchant is not active and cannot accept payments.');
        $this->errorCode  = 'MERCHANT_NOT_ACTIVE';
        $this->httpStatus = 422;
    }
}
