<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class AuditWriteException extends HttpException
{
    public function __construct(string $message = 'Failed to record audit event.', ?\Throwable $previous = null)
    {
        parent::__construct(500, $message, $previous, [], 0);
    }
}
