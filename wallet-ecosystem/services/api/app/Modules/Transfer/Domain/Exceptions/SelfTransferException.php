<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Domain\Exceptions;

final class SelfTransferException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sender and recipient must be different customers.');
    }
}
