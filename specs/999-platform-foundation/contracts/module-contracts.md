# Contract: Module Interface Contracts

**Feature**: Platform Foundation | **Version**: 1.0.0 | **Date**: 2026-08-16

This document defines the PHP interface contracts that Platform Foundation establishes for cross-module communication. No module may access another module's internal domain models, Eloquent models, or repositories directly. All cross-module communication goes through these interfaces.

---

## Dependency Rules

```
Allowed dependency directions:
  Any Module → Platform Module (cross-cutting services)
  Any Module → Shared Contracts (interfaces only)
  Platform Module → No other module

Forbidden:
  Module A Domain → Module B Domain
  Module A Infrastructure → Module B Infrastructure
  Any module → Platform internal implementation (only via interface)
  Cross-schema SQL JOINs (forbidden at DB layer)
```

Enforced by: Pest Architecture Tests + Deptrac CI check.

---

## Platform Module Contracts (Implemented by Platform Foundation)

### `IdempotencyServiceInterface`

```php
namespace App\Modules\Platform\Domain\Contracts;

interface IdempotencyServiceInterface
{
    /**
     * Check if a request with this key was already processed.
     * Returns the cached response if so, or null if this is a new request.
     */
    public function findCompletedResponse(string $idempotencyKey): ?IdempotencyResult;

    /**
     * Acquire an exclusive lock for this idempotency key.
     * Throws IdempotencyInProgressException if another request holds the lock.
     * Throws IdempotencyKeyMismatchException if the payload hash differs.
     */
    public function acquireLock(string $idempotencyKey, string $requestHash): IdempotencyLock;

    /**
     * Record the completed response and release the lock.
     */
    public function recordResponse(IdempotencyLock $lock, int $statusCode, array $responseBody): void;

    /**
     * Record a failed execution and release the lock.
     */
    public function recordFailure(IdempotencyLock $lock, \Throwable $e): void;
}
```

### `EventPublisherInterface`

```php
namespace App\Modules\Platform\Domain\Contracts;

interface EventPublisherInterface
{
    /**
     * Persist an integration event to the outbox within the current DB transaction.
     * The event will be dispatched asynchronously after the transaction commits.
     */
    public function publish(IntegrationEventInterface $event): void;

    /**
     * Persist multiple events atomically.
     */
    public function publishAll(array $events): void;
}
```

### `AuditServiceInterface`

```php
namespace App\Modules\Platform\Domain\Contracts;

interface AuditServiceInterface
{
    /**
     * Record an immutable audit event.
     * PII fields in before/after state must be pre-masked by the caller.
     */
    public function record(AuditEventData $event): void;
}
```

### `ConfigurationServiceInterface`

```php
namespace App\Modules\Platform\Domain\Contracts;

interface ConfigurationServiceInterface
{
    /**
     * Get the active value for a configuration key.
     * Returns null for TBD items. Throws TbdConfigurationException if
     * $requireValue is true and the key is TBD.
     */
    public function get(string $key, bool $requireValue = false): mixed;

    /**
     * Get a typed value with a default fallback.
     */
    public function getOrDefault(string $key, mixed $default): mixed;

    /**
     * Check if a configuration key is defined and active.
     */
    public function isConfigured(string $key): bool;

    /**
     * Invalidate the Redis cache for a key (called after updates).
     */
    public function invalidate(string $key): void;
}
```

### `CorrelationContextInterface`

```php
namespace App\Modules\Platform\Domain\Contracts;

interface CorrelationContextInterface
{
    public function correlationId(): string;
    public function requestId(): string;
    public function actor(): ?ActorContract;
    public function withCorrelationId(string $id): static;
    public function withActor(ActorContract $actor): static;
}
```

### `ActorContract`

```php
namespace App\Modules\Platform\Domain\Contracts;

interface ActorContract
{
    public function id(): string;        // UUID v7
    public function type(): string;     // 'user' | 'service' | 'system'
    public function isUser(): bool;
    public function isService(): bool;
}
```

---

## Shared Value Object Contracts

### `MoneyContract`

**Signed amount policy**: `Money` represents a signed exact monetary amount in the smallest currency unit. The value returned by `amount()` MAY be negative (e.g., to represent a debit, a reversal, or a liability). Whether a negative amount is **valid** in a given business context (e.g., whether a wallet balance may go negative) is determined by the owning domain's business rules — NOT by this contract.

**Currency mismatch policy**: `add()` and `subtract()` MUST throw `CurrencyMismatchException` when the operand's currency differs from `$this->currency()`. Cross-currency arithmetic is not supported at the `Money` object level. Domain services requiring currency conversion must perform the conversion explicitly before calling these methods.

```php
namespace App\Modules\Platform\Domain\Contracts;

interface MoneyContract
{
    public function amount(): int;         // Integer in smallest unit (e.g., thebe); MAY be negative
    public function currency(): CurrencyContract;

    /**
     * @throws \App\Modules\Platform\Domain\Exceptions\CurrencyMismatchException
     *         if $other->currency()->code() !== $this->currency()->code()
     */
    public function add(MoneyContract $other): static;

    /**
     * @throws \App\Modules\Platform\Domain\Exceptions\CurrencyMismatchException
     *         if $other->currency()->code() !== $this->currency()->code()
     */
    public function subtract(MoneyContract $other): static;

    public function isZero(): bool;
    public function isPositive(): bool;
    public function isNegative(): bool;
    public function equals(MoneyContract $other): bool;
    public function toDisplayString(): string;  // e.g., "BWP 500.00" or "BWP -100.00"
}
```

### `CurrencyMismatchException`

```php
namespace App\Modules\Platform\Domain\Exceptions;

/**
 * Thrown when arithmetic is attempted between Money objects of different currencies.
 * This is a domain error, not a PHP type error.
 */
class CurrencyMismatchException extends \DomainException
{
    public static function between(
        \App\Modules\Platform\Domain\Contracts\CurrencyContract $a,
        \App\Modules\Platform\Domain\Contracts\CurrencyContract $b
    ): static {
        return new static(
            "Cannot perform arithmetic between {$a->code()} and {$b->code()}. " .
            "Perform explicit currency conversion before calling add() or subtract()."
        );
    }
}
```

### `CurrencyContract`

```php
namespace App\Modules\Platform\Domain\Contracts;

interface CurrencyContract
{
    public function code(): string;       // ISO 4217 (e.g., "BWP")
    public function precision(): int;     // Decimal places (e.g., 2)
    public function name(): string;
    public function isSupported(): bool;
}
```

### `IntegrationEventInterface`

```php
namespace App\Modules\Platform\Domain\Contracts;

interface IntegrationEventInterface
{
    public function eventId(): string;           // UUID v7
    public function aggregateId(): string;       // UUID v7
    public function aggregateType(): string;     // PascalCase
    public function actorId(): ?string;
    public function correlationId(): string;
    public function causationId(): ?string;
    public function occurredAt(): \DateTimeImmutable;
    public function eventName(): string;         // Dot-notation PascalCase
    public function eventVersion(): string;      // SemVer
    public function payload(): array;
    public function metadata(): array;
}
```

---

## Module Stub Contracts (Implemented by E01–E34)

These contracts are defined now so Platform Foundation can reference them without implementation. Each module provides its own implementation registered in its Service Provider.

### `IdentityModuleContract` (stub — implemented by E01)

```php
namespace App\Modules\Identity\Domain\Contracts;

interface IdentityModuleContract
{
    /** Resolve a user identity from a valid bearer token. */
    public function resolveActorFromToken(string $bearerToken): ActorContract;

    /** Check if a permission is granted to the actor. */
    public function hasPermission(ActorContract $actor, string $permission): bool;

    /** Verify service-to-service credentials. */
    public function resolveServiceActor(string $clientId, string $clientSecret): ActorContract;
}
```

### `NotificationModuleContract` (stub — implemented by notification epic)

```php
namespace App\Modules\Notification\Domain\Contracts;

interface NotificationModuleContract
{
    /** Dispatch a notification (actual delivery is async). */
    public function dispatch(NotificationRequest $request): void;
}
```

### `WalletModuleContract` (stub — implemented by E06)

```php
namespace App\Modules\Wallet\Domain\Contracts;

interface WalletModuleContract
{
    /** Returns true if the wallet exists and is in an active, operable state. */
    public function isActive(string $walletId): bool;

    /** Returns the UUID of the actor who owns the wallet, or null if the wallet does not exist. */
    public function ownerActorId(string $walletId): ?string;

    /** Returns the currency of the wallet. */
    public function currency(string $walletId): \App\Modules\Platform\Domain\Contracts\CurrencyContract;
}
```

### `LedgerModuleContract` (stub — implemented by E07)

```php
namespace App\Modules\Ledger\Domain\Contracts;

interface LedgerModuleContract
{
    /**
     * Post a double-entry ledger record.
     * Returns the ledger entry UUID.
     * LedgerEntryRequest is defined by E07; Platform Foundation registers a
     * NullImplementation that throws ModuleNotImplementedException.
     */
    public function postEntry(LedgerEntryRequest $request): string;

    /** Returns true if a ledger entry for the given transaction reference already exists. */
    public function entryExists(string $transactionReference): bool;
}
```

### `TransactionModuleContract` (stub — implemented by E11)

```php
namespace App\Modules\Transaction\Domain\Contracts;

interface TransactionModuleContract
{
    /**
     * Generate a platform-unique transaction reference string
     * (format defined by E11; guaranteed to be stable and sortable).
     */
    public function generateReference(): string;

    /**
     * Returns the current lifecycle status of the given transaction reference.
     * Returns null if the reference is not found.
     */
    public function status(string $transactionReference): ?string;
}
```

---

## Extraction Seams

Each module is extraction-ready because:

| Seam | How It Works | Extraction Action |
|---|---|---|
| **Database** | Dedicated PostgreSQL schema + named connection | Change connection host env var |
| **Business logic** | Module Contracts (interfaces) injected via DI | Implement a remote client behind the same interface |
| **Events** | Integration events dispatched via `EventPublisherInterface` | Swap event bus from Redis to cloud service |
| **Config** | `ConfigurationServiceInterface` | Extracted module calls config API endpoint |
| **Audit** | `AuditServiceInterface` | Extracted module calls audit API endpoint |
| **HTTP** | Module owns its `/v1/{module}/` route prefix | Route prefix moves to new service host |

**Forbidden dependencies** (enforced by Pest Architecture Tests):
- No `App\Modules\{X}\Domain` importing `App\Modules\{Y}\Domain` where X ≠ Y and Y ≠ Platform
- No `App\Modules\{X}\Infrastructure` importing `App\Modules\{Y}\Infrastructure`
- No Eloquent models directly used across module boundaries (DTOs/contracts only)
- No cross-schema SQL JOINs in any repository
