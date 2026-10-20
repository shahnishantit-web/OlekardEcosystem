<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

final class DeviceAlreadyAcknowledgedException extends \RuntimeException
{
    public static function make(): self
    {
        return new self('This device notification has already been acknowledged.');
    }
}
