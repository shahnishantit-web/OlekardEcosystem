<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects\Providers;

use App\Modules\Platform\Domain\ValueObjects\Currency;
use App\Modules\Platform\Domain\ValueObjects\Money;

final class ProviderRequest
{
    private const CREDENTIAL_BLOCKLIST = [
        'api_key', 'secret', 'password', 'token', 'credential', 'private_key',
    ];

    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $platformEntityId,
        public readonly string $platformEntityType,
        public readonly string $operationType,
        public readonly string $correlationId,
        public readonly ?Money $amount,
        public readonly ?Currency $currency,
        public readonly array $metadata,
    ) {
        if ($correlationId === '') {
            throw new \InvalidArgumentException('correlationId must not be empty.');
        }
        self::assertMetadataClean($metadata);
    }

    /** @param array<string, mixed> $metadata */
    public static function forFinancialOperation(
        Money $amount,
        Currency $currency,
        string $entityId,
        string $entityType,
        string $opType,
        string $correlationId,
        array $metadata = [],
    ): self {
        if ($amount->isZero()) {
            throw new \InvalidArgumentException('Financial ProviderRequest amount must be non-zero.');
        }

        return new self(
            platformEntityId: $entityId,
            platformEntityType: $entityType,
            operationType: $opType,
            correlationId: $correlationId,
            amount: $amount,
            currency: $currency,
            metadata: $metadata,
        );
    }

    /** @param array<string, mixed> $metadata */
    public static function forNonFinancialOperation(
        string $entityId,
        string $entityType,
        string $opType,
        string $correlationId,
        array $metadata = [],
    ): self {
        return new self(
            platformEntityId: $entityId,
            platformEntityType: $entityType,
            operationType: $opType,
            correlationId: $correlationId,
            amount: null,
            currency: null,
            metadata: $metadata,
        );
    }

    /** @param array<string, mixed> $metadata */
    private static function assertMetadataClean(array $metadata): void
    {
        foreach (array_keys($metadata) as $key) {
            foreach (self::CREDENTIAL_BLOCKLIST as $blocked) {
                if (strtolower((string) $key) === $blocked) {
                    throw new \InvalidArgumentException(
                        "metadata key '{$key}' is disallowed — credentials must not appear in ProviderRequest."
                    );
                }
            }
        }
    }
}
