<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\Exceptions;

use RuntimeException;

final class KycCaseAlreadyOpenException extends RuntimeException
{
    public readonly string $errorCode;

    public readonly int $httpStatus;

    public function __construct(string $message = 'A KYC case is already open for this user.')
    {
        parent::__construct($message);
        $this->errorCode  = 'KYC_CASE_ALREADY_OPEN';
        $this->httpStatus = 409;
    }
}
