<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\Exceptions;

use RuntimeException;

final class KycCaseIncompleteException extends RuntimeException
{
    public readonly string $errorCode;

    public readonly int $httpStatus;

    /** @var string[] */
    public readonly array $missing;

    /** @param string[] $missing */
    public function __construct(array $missing)
    {
        parent::__construct('KYC case is incomplete and cannot be submitted.');
        $this->errorCode  = 'CASE_INCOMPLETE';
        $this->httpStatus = 422;
        $this->missing    = $missing;
    }
}
