<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\Exceptions;

use DomainException;

final class ReceiptNotAvailableException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct()
    {
        parent::__construct('Receipt is not available for this transaction.');
        $this->errorCode  = 'RECEIPT_NOT_AVAILABLE';
        $this->httpStatus = 422;
    }
}
