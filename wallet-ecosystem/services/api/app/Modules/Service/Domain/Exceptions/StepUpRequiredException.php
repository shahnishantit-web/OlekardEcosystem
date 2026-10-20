<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\Exceptions;

use DomainException;

final class StepUpRequiredException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;
    public function __construct()
    {
        $this->errorCode = 'STEP_UP_REQUIRED';
        $this->httpStatus = 403;
        parent::__construct('Step-up authentication is required for payments at or above the configured threshold.');
    }
}
