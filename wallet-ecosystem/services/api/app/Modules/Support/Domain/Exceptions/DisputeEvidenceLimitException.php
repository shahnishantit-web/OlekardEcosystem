<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Exceptions;

final class DisputeEvidenceLimitException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $message = 'Maximum evidence file limit reached for this dispute.')
    {
        parent::__construct($message);
        $this->errorCode  = 'DISPUTE_EVIDENCE_LIMIT_REACHED';
        $this->httpStatus = 422;
    }
}
