<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Exceptions;

use RuntimeException;

final class WebhookProviderNotFoundException extends RuntimeException
{
    public static function forSlug(string $providerSlug): self
    {
        return new self("No active integration found for provider slug: {$providerSlug}");
    }
}
