<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Exceptions;

final class DuplicatePhoneException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A user with this phone number already exists.');
    }
}
