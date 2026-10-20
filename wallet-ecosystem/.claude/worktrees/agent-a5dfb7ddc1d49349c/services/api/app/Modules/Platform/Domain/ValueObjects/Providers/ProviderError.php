<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects\Providers;

use App\Modules\Platform\Domain\Enums\ProviderErrorCode;

final class ProviderError
{
    public function __construct(
        public readonly ProviderErrorCode $normalizedCode,
        public readonly string $providerSpecificCode,
        public readonly bool $isTransient,
        public readonly string $message,
    ) {
        if ($message === '') {
            throw new \InvalidArgumentException('ProviderError message must not be empty.');
        }
    }

    public static function fromCode(
        ProviderErrorCode $code,
        string $rawCode,
        string $message,
    ): self {
        return new self(
            normalizedCode: $code,
            providerSpecificCode: $rawCode,
            isTransient: $code->isTransient(),
            message: $message,
        );
    }
}
