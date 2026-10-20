<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Domain\Exceptions;

use RuntimeException;

final class InvalidPinException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Transaction PIN verification failed.');
    }
}
