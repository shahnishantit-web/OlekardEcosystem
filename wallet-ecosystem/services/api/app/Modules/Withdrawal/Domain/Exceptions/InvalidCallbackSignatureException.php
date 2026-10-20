<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Domain\Exceptions;

use RuntimeException;

final class InvalidCallbackSignatureException extends RuntimeException
{
    public function __construct(string $provider)
    {
        parent::__construct("Invalid callback signature from provider: {$provider}");
    }
}
