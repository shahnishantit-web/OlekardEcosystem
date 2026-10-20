<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Exceptions;

final class EvidenceLockedException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $message = 'Evidence cannot be attached while dispute is under investigation.')
    {
        parent::__construct($message);
        $this->errorCode  = 'EVIDENCE_LOCKED';
        $this->httpStatus = 422;
    }
}
