<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use RuntimeException;

final class StepUpRequiredException extends RuntimeException
{
    public function __construct(public readonly string $requiredScope)
    {
        parent::__construct('Step-up authentication is required for this operation.');
    }
}
