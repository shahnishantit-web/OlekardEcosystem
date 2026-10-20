<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Exceptions;

class ClosureBlockedByObligationsException extends \RuntimeException
{
    public function __construct(
        public readonly array $blockedBy,
        string $message = '',
    ) {
        parent::__construct($message);
    }
}
