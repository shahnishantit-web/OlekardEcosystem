<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

final class DeviceAlreadyBlockedException extends \RuntimeException
{
    public static function make(): self
    {
        return new self('This device is already blocked.');
    }
}
