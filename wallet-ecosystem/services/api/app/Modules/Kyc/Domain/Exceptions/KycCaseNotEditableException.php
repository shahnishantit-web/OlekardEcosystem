<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\Exceptions;

use RuntimeException;

final class KycCaseNotEditableException extends RuntimeException
{
    public readonly string $errorCode;

    public readonly int $httpStatus;

    public function __construct()
    {
        parent::__construct(
            'KYC case cannot be edited in its current status.'
        );
        $this->errorCode  = 'CASE_NOT_EDITABLE';
        $this->httpStatus = 422;
    }
}
