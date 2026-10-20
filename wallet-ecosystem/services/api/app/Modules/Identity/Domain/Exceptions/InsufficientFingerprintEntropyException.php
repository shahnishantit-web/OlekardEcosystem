<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

final class InsufficientFingerprintEntropyException extends \RuntimeException
{
    public static function make(): self
    {
        return new self('Device fingerprint attributes do not meet the minimum entropy requirement.');
    }
}
