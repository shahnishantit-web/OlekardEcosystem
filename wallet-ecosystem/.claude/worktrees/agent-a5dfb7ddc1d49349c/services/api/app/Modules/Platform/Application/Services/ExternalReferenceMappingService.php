<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderReconciliationContract;
use App\Modules\Platform\Domain\Enums\ReconciliationState;
use App\Modules\Platform\Domain\Exceptions\ImmutableReferenceViolationException;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Domain\ValueObjects\Providers\ExternalReferenceMapping;
use App\Modules\Platform\Infrastructure\Persistence\ProviderExternalReferenceModel;
use Illuminate\Database\UniqueConstraintViolationException;
use Ramsey\Uuid\Uuid;

final class ExternalReferenceMappingService implements ProviderReconciliationContract
{
    public function __construct(
        private readonly AuditServiceInterface $audit,
    ) {}

    /**
     * Create a speculative record (provider_reference = null) before the provider call.
     *
     * IDEMPOTENT: if a PENDING record already exists for the same
     * (platformEntityId, platformEntityType, providerKey, operationType), returns the
     * existing record without inserting a duplicate (enforced by partial unique index +
     * find-or-create pattern on unique-constraint violation).
     */
    public function createSpeculative(
        string $platformEntityId,
        string $platformEntityType,
        string $providerKey,
        string $operationType,
    ): ExternalReferenceMapping {
        // Attempt to find an existing PENDING record first (optimistic path)
        $existing = ProviderExternalReferenceModel::query()
            ->where('platform_entity_id', $platformEntityId)
            ->where('platform_entity_type', $platformEntityType)
            ->where('provider_key', $providerKey)
            ->where('operation_type', $operationType)
            ->where('reconciliation_state', ReconciliationState::PENDING->value)
            ->first();

        if ($existing !== null) {
            return $existing->toEntity();
        }

        try {
            $model = new ProviderExternalReferenceModel();
            $model->id = (string) Uuid::uuid7();
            $model->platform_entity_id = $platformEntityId;
            $model->platform_entity_type = $platformEntityType;
            $model->provider_key = $providerKey;
            $model->operation_type = $operationType;
            $model->reconciliation_state = ReconciliationState::PENDING;
            $model->created_at = now('UTC');
            $model->saveOrFail();

            return $model->toEntity();
        } catch (UniqueConstraintViolationException) {
            // Concurrent insert raced — re-query to return the winning record
            $winner = ProviderExternalReferenceModel::query()
                ->where('platform_entity_id', $platformEntityId)
                ->where('platform_entity_type', $platformEntityType)
                ->where('provider_key', $providerKey)
                ->where('operation_type', $operationType)
                ->where('reconciliation_state', ReconciliationState::PENDING->value)
                ->firstOrFail();

            return $winner->toEntity();
        }
    }

    /**
     * Write provider_reference exactly once (one-time-write rule).
     *
     * @throws ImmutableReferenceViolationException if already set, or if the pair is not unique
     */
    public function confirmProviderReference(
        string $id,
        string $providerReference,
        \DateTimeImmutable $confirmedAt,
    ): ExternalReferenceMapping {
        $model = ProviderExternalReferenceModel::findOrFail($id);

        // One-time-write: once set, provider_reference is immutable
        if ($model->provider_reference !== null) {
            throw ImmutableReferenceViolationException::forKey(
                $model->provider_key,
                "provider_reference already set on record {$id}",
            );
        }

        // Check global uniqueness of (provider_key, providerReference)
        $duplicate = ProviderExternalReferenceModel::query()
            ->where('provider_key', $model->provider_key)
            ->where('provider_reference', $providerReference)
            ->where('id', '!=', $id)
            ->exists();

        if ($duplicate) {
            throw ImmutableReferenceViolationException::forKey(
                $model->provider_key,
                "providerReference '{$providerReference}' is already used by another record",
            );
        }

        // Atomic update — use raw update to bypass guarded fields protection for this controlled write
        ProviderExternalReferenceModel::query()
            ->where('id', $id)
            ->whereNull('provider_reference')
            ->update([
                'provider_reference'      => $providerReference,
                'reference_confirmed_at'  => $confirmedAt->format('Y-m-d H:i:s.u'),
            ]);

        $model->refresh();

        return $model->toEntity();
    }

    /**
     * Use surrogate reference when provider omits one (FR-E1000-011).
     * Logs an audit warning; returns the created record.
     */
    public function createWithSurrogateReference(
        string $platformEntityId,
        string $platformEntityType,
        string $providerKey,
        string $operationType,
    ): ExternalReferenceMapping {
        $surrogate = 'surrogate-' . (string) Uuid::uuid7();

        $model = new ProviderExternalReferenceModel();
        $model->id = (string) Uuid::uuid7();
        $model->platform_entity_id = $platformEntityId;
        $model->platform_entity_type = $platformEntityType;
        $model->provider_key = $providerKey;
        $model->operation_type = $operationType;
        $model->reconciliation_state = ReconciliationState::PENDING;
        $model->created_at = now('UTC');
        $model->saveOrFail();

        // Write surrogate reference — controlled write bypassing guarded protection
        ProviderExternalReferenceModel::query()
            ->where('id', $model->id)
            ->update([
                'provider_reference'     => $surrogate,
                'reference_confirmed_at' => now('UTC')->format('Y-m-d H:i:s.u'),
            ]);

        $model->refresh();

        $this->audit->record(new AuditEventData(
            action: 'provider.external_reference.surrogate_created',
            actorType: 'system',
            resourceType: 'provider_external_reference',
            correlationId: $model->id,
            outcome: 'warning',
            resourceId: $model->id,
            afterState: ['surrogate_reference' => $surrogate, 'provider_key' => $providerKey],
            metadata: ['platform_entity_id' => $platformEntityId],
        ));

        return $model->toEntity();
    }

    public function findByProviderReference(
        string $providerKey,
        string $providerReference,
    ): ?ExternalReferenceMapping {
        $model = ProviderExternalReferenceModel::query()
            ->where('provider_key', $providerKey)
            ->where('provider_reference', $providerReference)
            ->first();

        return $model?->toEntity();
    }

    /** @return ExternalReferenceMapping[] */
    public function findByPlatformEntity(
        string $platformEntityId,
        string $platformEntityType,
    ): array {
        return ProviderExternalReferenceModel::query()
            ->where('platform_entity_id', $platformEntityId)
            ->where('platform_entity_type', $platformEntityType)
            ->get()
            ->map(fn ($m) => $m->toEntity())
            ->all();
    }

    /** @return ExternalReferenceMapping[] */
    public function findStalePending(\DateInterval $threshold): array
    {
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->sub($threshold);

        return ProviderExternalReferenceModel::query()
            ->where('reconciliation_state', ReconciliationState::PENDING->value)
            ->where('created_at', '<', $cutoff->format('Y-m-d H:i:s'))
            ->get()
            ->map(fn ($m) => $m->toEntity())
            ->all();
    }

    /** @return ExternalReferenceMapping[] */
    public function findAllExceptions(): array
    {
        return ProviderExternalReferenceModel::query()
            ->where('reconciliation_state', ReconciliationState::EXCEPTION->value)
            ->get()
            ->map(fn ($m) => $m->toEntity())
            ->all();
    }

    // ── ProviderReconciliationContract ──────────────────────────────────────

    public function getState(string $providerKey, string $providerReference): ReconciliationState
    {
        $model = ProviderExternalReferenceModel::query()
            ->where('provider_key', $providerKey)
            ->where('provider_reference', $providerReference)
            ->first();

        if ($model === null) {
            throw new \DomainException(
                "No ExternalReferenceMapping found for provider_key={$providerKey}, provider_reference={$providerReference}"
            );
        }

        return ReconciliationState::from($model->reconciliation_state instanceof ReconciliationState
            ? $model->reconciliation_state->value
            : (string) $model->reconciliation_state);
    }

    public function match(string $providerKey, string $providerReference): void
    {
        $model = ProviderExternalReferenceModel::query()
            ->where('provider_key', $providerKey)
            ->where('provider_reference', $providerReference)
            ->first();

        if ($model === null) {
            throw new \DomainException(
                "No ExternalReferenceMapping found for provider_key={$providerKey}, provider_reference={$providerReference}"
            );
        }

        $state = $model->reconciliation_state instanceof ReconciliationState
            ? $model->reconciliation_state
            : ReconciliationState::from((string) $model->reconciliation_state);

        if ($state === ReconciliationState::MATCHED) {
            throw new \DomainException('Cannot transition from MATCHED state — MATCHED is terminal within E1000.');
        }

        if ($state === ReconciliationState::EXCEPTION) {
            if (empty($model->reconciliation_reason) || empty($model->reconciliation_owner)) {
                throw new \DomainException(
                    'Cannot match an EXCEPTION record without reconciliation_reason and reconciliation_owner already set.'
                );
            }
        }

        $model->update([
            'reconciliation_state' => ReconciliationState::MATCHED->value,
            'reconciled_at'        => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function exception(
        string $providerKey,
        string $providerReference,
        string $reason,
        string $owner,
    ): void {
        if ($reason === '') {
            throw new \InvalidArgumentException('reconciliation_reason must not be empty — no-silent-resolution rule.');
        }
        if ($owner === '') {
            throw new \InvalidArgumentException('reconciliation_owner must not be empty — no-silent-resolution rule.');
        }

        $model = ProviderExternalReferenceModel::query()
            ->where('provider_key', $providerKey)
            ->where('provider_reference', $providerReference)
            ->first();

        if ($model === null) {
            throw new \DomainException(
                "No ExternalReferenceMapping found for provider_key={$providerKey}, provider_reference={$providerReference}"
            );
        }

        $state = $model->reconciliation_state instanceof ReconciliationState
            ? $model->reconciliation_state
            : ReconciliationState::from((string) $model->reconciliation_state);

        if ($state === ReconciliationState::MATCHED) {
            throw new \DomainException('MATCHED→EXCEPTION is prohibited within E1000.');
        }

        $model->update([
            'reconciliation_state'  => ReconciliationState::EXCEPTION->value,
            'reconciliation_reason' => $reason,
            'reconciliation_owner'  => $owner,
            'reconciled_at'         => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
    }
}
