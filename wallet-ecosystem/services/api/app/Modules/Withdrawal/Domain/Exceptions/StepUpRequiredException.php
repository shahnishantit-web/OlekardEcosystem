<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Domain\Exceptions;

use RuntimeException;

final class StepUpRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Step-up authentication is required for this withdrawal amount.');
    }
}
