<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Exceptions;

final class ApplicationNotEditableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Agent application is not in an editable status.');
    }
}
