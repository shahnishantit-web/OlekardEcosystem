<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects\Providers;

use App\Modules\Platform\Domain\Enums\ProviderStatus;
use App\Modules\Platform\Domain\ValueObjects\Currency;
use App\Modules\Platform\Domain\ValueObjects\Money;

final class ProviderResponse
{
    public function __construct(
        public readonly ProviderStatus $status,
        public readonly ?string $providerReference,
        public readonly ?Money $amount,
        public readonly ?Currency $currency,
        public readonly ?\DateTimeImmutable $providerTimestamp,
        public readonly ?ProviderError $error,
        public readonly string $rawProviderStatusCode,
        public readonly string $correlationId,
    ) {
        if ($correlationId === '') {
            throw new \InvalidArgumentException('correlationId must not be empty.');
        }
    }

    public static function success(
        string $providerRef,
        string $rawCode,
        string $correlationId,
        ?Money $amount = null,
        ?Currency $currency = null,
        ?\DateTimeImmutable $ts = null,
    ): self {
        return new self(
            status: ProviderStatus::SUCCESS,
            providerReference: $providerRef,
            amount: $amount,
            currency: $currency,
            providerTimestamp: $ts,
            error: null,
            rawProviderStatusCode: $rawCode,
            correlationId: $correlationId,
        );
    }

    public static function failure(
        ProviderError $error,
        string $rawCode,
        string $correlationId,
    ): self {
        return new self(
            status: ProviderStatus::FAILURE,
            providerReference: null,
            amount: null,
            currency: null,
            providerTimestamp: null,
            error: $error,
            rawProviderStatusCode: $rawCode,
            correlationId: $correlationId,
        );
    }
}
