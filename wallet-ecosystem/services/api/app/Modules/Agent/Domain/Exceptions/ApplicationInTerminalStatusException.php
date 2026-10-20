<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Exceptions;

final class ApplicationInTerminalStatusException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Agent application is in a terminal status and cannot be modified.');
    }
}
