<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects\Providers;

use App\Modules\Platform\Domain\Enums\ReconciliationState;

final class ExternalReferenceMapping
{
    public function __construct(
        public readonly string $id,
        public readonly string $platformEntityId,
        public readonly string $platformEntityType,
        public readonly string $providerKey,
        public readonly ?string $providerReference,
        public readonly ?\DateTimeImmutable $referenceConfirmedAt,
        public readonly string $operationType,
        public readonly ReconciliationState $reconciliationState,
        public readonly ?string $reconciliationReason,
        public readonly ?string $reconciliationOwner,
        public readonly ?\DateTimeImmutable $reconciledAt,
        public readonly \DateTimeImmutable $createdAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            id: $row['id'],
            platformEntityId: $row['platform_entity_id'],
            platformEntityType: $row['platform_entity_type'],
            providerKey: $row['provider_key'],
            providerReference: $row['provider_reference'] ?? null,
            referenceConfirmedAt: isset($row['reference_confirmed_at'])
                ? self::parseDateTime($row['reference_confirmed_at'])
                : null,
            operationType: $row['operation_type'],
            reconciliationState: $row['reconciliation_state'] instanceof ReconciliationState
                ? $row['reconciliation_state']
                : ReconciliationState::from($row['reconciliation_state']),
            reconciliationReason: $row['reconciliation_reason'] ?? null,
            reconciliationOwner: $row['reconciliation_owner'] ?? null,
            reconciledAt: isset($row['reconciled_at'])
                ? self::parseDateTime($row['reconciled_at'])
                : null,
            createdAt: self::parseDateTime($row['created_at']),
        );
    }

    private static function parseDateTime(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTime) {
            return \DateTimeImmutable::createFromMutable($value);
        }
        return new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
    }
}
