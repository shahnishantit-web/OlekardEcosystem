<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Enums;

enum ProviderErrorCode: string
{
    case INSUFFICIENT_FUNDS   = 'insufficient_funds';
    case PROVIDER_UNAVAILABLE = 'provider_unavailable';
    case INVALID_REFERENCE    = 'invalid_reference';
    case INVALID_DESTINATION  = 'invalid_destination';
    case TIMEOUT              = 'timeout';
    case RATE_LIMITED         = 'rate_limited';
    case CREDENTIAL_REJECTED  = 'credential_rejected';
    case CONTENT_REJECTED     = 'content_rejected';
    case UNKNOWN              = 'unknown';

    public function isTransient(): bool
    {
        return match ($this) {
            self::PROVIDER_UNAVAILABLE,
            self::TIMEOUT,
            self::RATE_LIMITED => true,
            default            => false,
        };
    }
}
