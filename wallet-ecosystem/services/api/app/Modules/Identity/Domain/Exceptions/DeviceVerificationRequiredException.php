<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use RuntimeException;

final class DeviceVerificationRequiredException extends RuntimeException
{
    public function __construct(public readonly string $challengeId)
    {
        parent::__construct('Device verification is required before authentication can proceed.');
    }
}
