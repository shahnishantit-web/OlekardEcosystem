<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\Exceptions;

use RuntimeException;

final class KycDuplicateIdentityException extends RuntimeException
{
    public readonly string $errorCode;

    public readonly int $httpStatus;

    public function __construct(string $message = 'A verified identity with this national ID already exists.')
    {
        parent::__construct($message);
        $this->errorCode  = 'DUPLICATE_IDENTITY_DETECTED';
        $this->httpStatus = 409;
    }
}
