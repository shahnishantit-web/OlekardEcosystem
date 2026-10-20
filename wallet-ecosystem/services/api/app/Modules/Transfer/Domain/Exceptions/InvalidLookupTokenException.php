<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Domain\Exceptions;

final class InvalidLookupTokenException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Lookup token is invalid, expired, or has already been used.');
    }
}
