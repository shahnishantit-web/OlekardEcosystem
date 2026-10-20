<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\Exceptions;

use RuntimeException;

final class KycAlreadyApprovedException extends RuntimeException
{
    public readonly string $errorCode;

    public readonly int $httpStatus;

    public function __construct(string $message = 'This user already has an approved KYC case.')
    {
        parent::__construct($message);
        $this->errorCode  = 'KYC_ALREADY_APPROVED';
        $this->httpStatus = 409;
    }
}
