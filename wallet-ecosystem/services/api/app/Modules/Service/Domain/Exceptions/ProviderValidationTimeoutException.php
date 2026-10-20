<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\Exceptions;

use DomainException;

final class ProviderValidationTimeoutException extends DomainException
{
    public string $errorCode = 'PROVIDER_TIMEOUT';
    public int $httpStatus = 503;
    public function __construct()
    {
        parent::__construct('Provider validation timed out. Please try again.');
    }
}
