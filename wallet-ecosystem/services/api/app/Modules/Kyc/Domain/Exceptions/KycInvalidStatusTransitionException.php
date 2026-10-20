<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\Exceptions;

use App\Modules\Kyc\Domain\Enums\KycCaseStatus;
use RuntimeException;

final class KycInvalidStatusTransitionException extends RuntimeException
{
    public readonly string $errorCode;

    public readonly int $httpStatus;

    public function __construct(KycCaseStatus $from, string $action)
    {
        parent::__construct(
            "Invalid KYC state transition: cannot perform '{$action}' from status '{$from->value}'."
        );
        $this->errorCode  = 'INVALID_CASE_STATUS';
        $this->httpStatus = 422;
    }
}
