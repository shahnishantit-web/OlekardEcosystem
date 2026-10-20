# Provider Adapter Contracts (E1000)

**Date**: 2026-08-26
**Authority**: ADR-008, ADR-009, OKI-ARCH-2026-003

These are the authoritative PHP interface contracts for the Provider Adapter Foundation. Each downstream epic that integrates an external provider MUST implement one or more of these interfaces in its Infrastructure layer. No domain service may reference a concrete adapter class by name.

All interfaces live in namespace: `App\Modules\Platform\Domain\Contracts\Providers`
All value objects live in namespace: `App\Modules\Platform\Domain\ValueObjects\Providers`
All enums live in namespace: `App\Modules\Platform\Domain\Enums`

---

## 1. ProviderAdapterInterface

**Namespace**: `App\Modules\Platform\Domain\Contracts\Providers\ProviderAdapterInterface`

**Responsibility**: Base contract for all provider adapters. Translates a normalized platform request into the provider's wire format, executes the call, and returns a normalized response. Credentials are retrieved internally via `SecretProviderInterface` — they must not appear in any method signature.

```php
interface ProviderAdapterInterface
{
    /**
     * Execute an outbound provider call.
     *
     * @throws ProviderException if the provider returns an unrecoverable error
     *                           that cannot be normalized into a ProviderResponse.
     */
    public function send(ProviderRequest $request): ProviderResponse;

    /**
     * Return the static capability declarations for this adapter instance.
     * Must NOT query the provider API at runtime.
     */
    public function getCapabilities(): ProviderCapabilitySet;

    /**
     * Return the configuration key identifier for this adapter.
     * Example: 'mno_primary', 'kyc_vendor', 'otp_gateway_fallback'
     * Must NOT return a provider brand name, credential, or secret name.
     */
    public function getProviderIdentifier(): string;
}
```

**Contract obligations for implementors**:
- `send()` MUST return a `ProviderResponse` — never null. On provider error, the response carries a `ProviderError` with `is_transient` correctly set.
- `send()` MUST map provider-specific error codes to `ProviderErrorCode` enum values before returning.
- `send()` MUST emit an audit event via `AuditServiceInterface` for every call (success and failure) per FR-E1000-020.
- `send()` MUST emit metrics per FR-E1000-021.
- `getCapabilities()` MUST return a static `ProviderCapabilitySet`; it MUST NOT make a network call.
- `getProviderIdentifier()` MUST match the configuration key used to bind this adapter in the service container.

---

## 2. ProviderCapabilityInterface

**Namespace**: `App\Modules\Platform\Domain\Contracts\Providers\ProviderCapabilityInterface`

**Responsibility**: Declares the static capability set for a provider adapter. Typically satisfied by `ProviderAdapterInterface` implementors (which already include `getCapabilities()`), but available as a standalone interface for routing/selection code that needs only capability discovery without calling `send()`.

```php
interface ProviderCapabilityInterface
{
    public function getCapabilities(): ProviderCapabilitySet;
}
```

**Note**: `ProviderAdapterInterface` extends `ProviderCapabilityInterface` implicitly via structural typing. Implementors of `ProviderAdapterInterface` automatically satisfy `ProviderCapabilityInterface`.

---

## 3. ProviderHealthInterface

**Namespace**: `App\Modules\Platform\Domain\Contracts\Providers\ProviderHealthInterface`

**Responsibility**: Reports the current health status of a bound provider adapter. The mechanism for updating health status (polling, circuit-breaker, provider webhook) is TBD-E1000-010.

```php
interface ProviderHealthInterface
{
    /**
     * Return the current health status of this provider adapter.
     * Must NOT make a live provider API call on each invocation.
     * Health status is maintained by an external health-check mechanism (TBD-E1000-010).
     */
    public function getHealthStatus(): ProviderHealthStatus;
}
```

**Contract obligations for implementors**:
- `getHealthStatus()` MUST return `ProviderHealthStatus::unknown()` as the safe initial state if no health data has been received yet.
- `getHealthStatus()` MUST NOT make a live network call.
- The `ProviderHealthStatus::$lastCheckedAt` field MUST be set to the time of the most recent health update, not `now()`.

---

## 4. ProviderRoutingInterface

**Namespace**: `App\Modules\Platform\Domain\Contracts\Providers\ProviderRoutingInterface`

**Responsibility**: Selects which bound provider adapter to use for a given request. Decouples routing logic from domain services. Routing strategy (ordered, round-robin, weighted, affinity) is a concrete implementation supplied via the service container — this interface defines the seam.

```php
interface ProviderRoutingInterface
{
    /**
     * Select an eligible provider adapter for the given request context.
     *
     * Selection criteria:
     *   - Provider capability set MUST include the operation type in $context.
     *   - Provider health MUST NOT be DOWN.
     *   - If $candidates is empty, throw ProviderException('No eligible provider').
     *
     * @param ProviderAdapterInterface[] $candidates  All bound providers for this category.
     * @throws ProviderException if no eligible provider is found.
     */
    public function selectProvider(ProviderRequest $context, array $candidates): ProviderAdapterInterface;
}
```

**Contract obligations for implementors**:
- MUST NOT select a provider with `ProviderHealthStatus::DOWN`.
- MUST NOT select a provider whose `ProviderCapabilitySet` does not include the `operationType` from `$context`.
- MUST throw `ProviderException` if no eligible candidate exists (fail-fast; never silently use an ineligible provider).

---

## 5. ProviderFailoverInterface

**Namespace**: `App\Modules\Platform\Domain\Contracts\Providers\ProviderFailoverInterface`

**Responsibility**: Defines the failover seam — whether to attempt a fallback after a provider error, and which provider to try next. Concrete failover strategies (ordered list, configurable chain length) are supplied via the service container.

```php
interface ProviderFailoverInterface
{
    /**
     * Determine whether a failover should be attempted after $error.
     *
     * Returns true only if $error->isTransient === true.
     * Permanent errors (INSUFFICIENT_FUNDS, INVALID_REFERENCE, etc.) MUST return false.
     */
    public function shouldFailover(ProviderError $error): bool;

    /**
     * Select the next provider in the failover chain.
     *
     * @param ProviderAdapterInterface   $current    The provider that just failed.
     * @param ProviderAdapterInterface[] $candidates All bound providers for this category.
     * @param ProviderRequest            $context    The original request (for capability/health checks).
     * @return ProviderAdapterInterface|null  Next provider to try; null if chain exhausted.
     */
    public function selectFallback(
        ProviderAdapterInterface $current,
        array $candidates,
        ProviderRequest $context
    ): ?ProviderAdapterInterface;
}
```

**Contract obligations for implementors**:
- `shouldFailover()` MUST return `false` for any `ProviderError` with `isTransient === false`.
- `selectFallback()` MUST NOT return `$current` (prevent infinite loops).
- `selectFallback()` MUST NOT return a provider with health DOWN or an incapable capability set.
- `selectFallback()` returns `null` when all eligible fallback candidates are exhausted (caller must surface a terminal failure).

---

## 6. ProviderWebhookContract

**Namespace**: `App\Modules\Platform\Domain\Contracts\Providers\ProviderWebhookContract`

**Responsibility**: Normalizes a raw inbound provider webhook payload into a `NormalizedWebhookEvent`. Signature validation is provider-specific; each concrete adapter implements its own signature verification logic behind this interface. The HTTP endpoint that receives the raw POST request is owned by E25 (API Integration Platform).

```php
interface ProviderWebhookContract
{
    /**
     * Parse and normalize an inbound provider webhook.
     *
     * Implementations MUST:
     *   1. Validate the provider signature using $signature and $headers.
     *   2. Map provider-specific event type strings to normalized platform event type values.
     *   3. Resolve the platform entity ID from ExternalReferenceMapping (may be null if unresolved).
     *   4. Set an idempotency key (provider notification ID or deterministic hash).
     *   5. Preserve $rawPayload verbatim in the returned NormalizedWebhookEvent.
     *
     * @param string   $rawPayload  The raw request body as received from the provider.
     * @param string   $signature   Provider-supplied signature (header value).
     * @param string[] $headers     All request headers (for providers that use multiple header fields).
     * @throws WebhookSignatureException if signature validation fails.
     * @throws ProviderException         if the payload is structurally invalid.
     */
    public function parse(string $rawPayload, string $signature, array $headers): NormalizedWebhookEvent;
}
```

**Contract obligations for implementors**:
- MUST throw `WebhookSignatureException` for invalid or missing signatures — never silently accept unsigned payloads.
- MUST preserve `$rawPayload` in the returned `NormalizedWebhookEvent::$rawPayload` field.
- MUST set `NormalizedWebhookEvent::$platformEntityId` to `null` (not throw) when the `ExternalReferenceMapping` lookup returns no match — deferred resolution applies per FR-E1000-019.
- MUST generate the `idempotencyKey` from the provider-supplied notification ID (if available) or a deterministic hash of the raw payload and a stable, implementation-defined provider key (e.g., `hash('sha256', $rawPayload . $providerKey)` where `$providerKey` is a constant or method on the concrete implementing class). `ProviderWebhookContract` does not declare a `getProviderIdentifier()` method; implementations that also satisfy `ProviderAdapterInterface` MAY use `$this->getProviderIdentifier()` for this purpose.
- MUST NOT log or surface credentials, OTP values, or PII from the raw payload.

---

## 7. ProviderReconciliationContract

**Namespace**: `App\Modules\Platform\Domain\Contracts\Providers\ProviderReconciliationContract`

**Responsibility**: Exposes the reconciliation state machine for `ExternalReferenceMapping` records. Implemented by `ExternalReferenceMappingService` in E1000. Whether this interface migrates to E20 is TBD-E1000-012.

```php
interface ProviderReconciliationContract
{
    /**
     * Return the current reconciliation state for the given external reference.
     *
     * @throws \DomainException if no record exists for (providerKey, providerReference).
     */
    public function getState(string $providerKey, string $providerReference): ReconciliationState;

    /**
     * Transition the external reference record to MATCHED state.
     *
     * Valid source states: PENDING, EXCEPTION (when reconciliation_reason and
     * reconciliation_owner are already set on the record).
     * MATCHED is terminal within E1000 — no further E1000 transitions are defined from MATCHED.
     *
     * @throws \DomainException if the record is already in MATCHED state.
     * @throws \DomainException if the source state is EXCEPTION and reconciliation_reason
     *                          or reconciliation_owner is null on the record.
     */
    public function match(string $providerKey, string $providerReference): void;

    /**
     * Transition the external reference record to EXCEPTION state.
     * Requires reason, owner, and timestamp — no-silent-resolution rule (OKI-ARCH-2026-003 §10).
     *
     * Valid source states: PENDING, EXCEPTION (idempotent field update).
     * Prohibited source state: MATCHED — MATCHED→EXCEPTION is not a valid E1000 transition.
     *
     * @throws \InvalidArgumentException if $reason or $owner is empty.
     * @throws \DomainException if the record is in MATCHED state.
     */
    public function exception(
        string $providerKey,
        string $providerReference,
        string $reason,
        string $owner
    ): void;
}
```

**Contract obligations for implementors**:
- `exception()` MUST reject empty `$reason` or `$owner` — no-silent-resolution is non-negotiable.
- `exception()` MUST reject a MATCHED record — MATCHED→EXCEPTION is prohibited within E1000.
- `match()` MUST set `reconciled_at` to current UTC time.
- `match()` on an EXCEPTION source state MUST require that `reconciliation_reason` and `reconciliation_owner` are already set on the record.
- The `UNMATCHED` state is reserved in the `ReconciliationState` enum for future reconciliation epics. No method in this contract transitions a record to UNMATCHED. Implementations MUST NOT silently transition records to UNMATCHED. The PENDING→UNMATCHED and UNMATCHED→EXCEPTION transitions are deferred to the epic that owns provider-specific settlement reconciliation (planned: E20).

---

## ExternalReferenceMappingService (Application Service)

**Namespace**: `App\Modules\Platform\Application\Services\ExternalReferenceMappingService`

This service is not an interface — it is the single authoritative implementation. It implements `ProviderReconciliationContract` and owns all reads and writes to `platform.provider_external_references`.

**Public API**:

```php
class ExternalReferenceMappingService implements ProviderReconciliationContract
{
    // Create a speculative record (provider_reference = null) before the provider call.
    // IDEMPOTENT: if a PENDING record already exists for the same
    // (platformEntityId, platformEntityType, providerKey, operationType), returns the
    // existing record without inserting a duplicate (enforced by partial unique index +
    // find-or-create pattern on unique-constraint violation).
    public function createSpeculative(
        string $platformEntityId,
        string $platformEntityType,
        string $providerKey,
        string $operationType
    ): ExternalReferenceMapping;

    // Write provider_reference exactly once (one-time-write rule)
    // Throws ImmutableReferenceViolationException if already set
    public function confirmProviderReference(
        string $id,
        string $providerReference,
        \DateTimeImmutable $confirmedAt
    ): ExternalReferenceMapping;

    // Use surrogate reference when provider omits one (FR-E1000-011)
    // Logs an audit warning; returns the created record
    public function createWithSurrogateReference(
        string $platformEntityId,
        string $platformEntityType,
        string $providerKey,
        string $operationType
    ): ExternalReferenceMapping;

    // Lookup by provider reference (for webhook resolution and reconciliation)
    public function findByProviderReference(
        string $providerKey,
        string $providerReference
    ): ?ExternalReferenceMapping;

    // Lookup all records for a platform entity
    public function findByPlatformEntity(
        string $platformEntityId,
        string $platformEntityType
    ): array; // ExternalReferenceMapping[]

    // PENDING records older than $threshold (for timeout detection)
    public function findStalePending(\DateInterval $threshold): array;

    // All EXCEPTION records (for reconciliation review queue)
    public function findAllExceptions(): array;

    // ProviderReconciliationContract methods:
    public function getState(string $providerKey, string $providerReference): ReconciliationState;
    public function match(string $providerKey, string $providerReference): void;
    public function exception(string $providerKey, string $providerReference, string $reason, string $owner): void;
}
```

---

## NullProviderAdapter (Test Double)

**Namespace**: `App\Modules\Platform\Infrastructure\Providers\NullProviderAdapter`

**Purpose**: Configurable in-code stub implementing `ProviderAdapterInterface` for local development and testing. Never makes a network call. Must NOT be registered as the live container binding in production.

```php
final class NullProviderAdapter implements ProviderAdapterInterface, ProviderHealthInterface
{
    public function __construct(
        private readonly ProviderResponse $responseToReturn,
        private readonly ProviderCapabilitySet $capabilities,
        private readonly string $providerIdentifier = 'null',
    ) {}

    // Convenience constructors:
    public static function alwaysSucceed(ProviderCapabilitySet $caps): self;
    public static function alwaysFail(ProviderError $error, ProviderCapabilitySet $caps): self;

    public function send(ProviderRequest $request): ProviderResponse;
    public function getCapabilities(): ProviderCapabilitySet;
    public function getProviderIdentifier(): string;
    public function getHealthStatus(): ProviderHealthStatus; // always returns UP
}
```

---

## Integration Event Contracts

E1000 does not define new integration events. Domain services produce audit events via `AuditServiceInterface` (existing) and enqueue `NormalizedWebhookEvent` to the outbox via `EventPublisherInterface` (existing). No new event schema is introduced.

---

## Backward Compatibility

All contracts in this document are **new**. No existing Platform Foundation interface is modified. Downstream epics that implement these contracts are not breaking any existing contract when they do so.

If a new case is added to `ProviderCapabilityEnum` or `ProviderErrorCode` in a future version, existing adapters that do not handle the new case will continue to compile — enums with an unhandled value will fall through to the `UNKNOWN` code path or capability mismatch path, both of which are handled safely.
