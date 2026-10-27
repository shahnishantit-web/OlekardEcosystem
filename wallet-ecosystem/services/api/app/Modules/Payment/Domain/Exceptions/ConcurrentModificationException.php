<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

use RuntimeException;

final class ConcurrentModificationException extends RuntimeException
{
    public function __construct(string $resource, int $tries)
    {
        parent::__construct(
            "Concurrent modification on {$resource} after {$tries} deadlock retries"
        );
    }
}
