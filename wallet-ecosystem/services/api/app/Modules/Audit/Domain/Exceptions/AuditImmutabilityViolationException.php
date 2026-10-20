<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class AuditImmutabilityViolationException extends HttpException
{
    public function __construct(string $message = 'Audit records are immutable and cannot be modified or deleted.')
    {
        parent::__construct(403, $message, null, [], 0);
    }
}
