<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use RuntimeException;

final class AccountLockedException extends RuntimeException
{
    public function __construct(
        public readonly ?\DateTimeImmutable $lockedUntil = null,
    ) {
        parent::__construct('Account is locked.');
    }
}
