<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

class ImmutableReferenceViolationException extends \DomainException
{
    public static function forKey(string $providerKey, string $context): self
    {
        return new self(
            "provider_reference is immutable for provider_key={$providerKey}. Context: {$context}"
        );
    }
}
