<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Domain\Exceptions;

final class RecipientNotFoundException extends \RuntimeException
{
    public function __construct(string $identifier)
    {
        parent::__construct("Recipient not found for identifier: {$identifier}.");
    }
}
