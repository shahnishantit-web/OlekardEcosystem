<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Exceptions;

use RuntimeException;

final class InvalidApiCredentialsException extends RuntimeException
{
    public static function credentialsMismatch(): self
    {
        return new self('API credentials are invalid.');
    }

    public static function clientInactive(): self
    {
        return new self('API client is inactive.');
    }

    public static function malformedHeader(): self
    {
        return new self('Authorization header is malformed. Expected: Bearer base64(api_key:secret).');
    }
}
