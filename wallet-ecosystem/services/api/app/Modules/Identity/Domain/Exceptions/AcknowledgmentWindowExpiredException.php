<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

final class AcknowledgmentWindowExpiredException extends \RuntimeException
{
    public static function make(): self
    {
        return new self('The acknowledgment window for this device notification has expired.');
    }
}
