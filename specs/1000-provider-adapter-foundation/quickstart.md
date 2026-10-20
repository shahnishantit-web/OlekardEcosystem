# Quickstart Validation Guide: Provider Adapter Foundation (E1000)

**Date**: 2026-08-26

This guide documents how to validate that E1000 is correctly implemented. All scenarios use local development tooling only — no real provider API is called at any point.

---

## Prerequisites

- PHP 8.4+ installed and active.
- Laravel application running locally (`php artisan serve` or Sail).
- PostgreSQL connected and migrations run (`php artisan migrate`).
- Pest test runner available (`./vendor/bin/pest`).
- Static analysis tool available (`./vendor/bin/phpstan analyse`).

---

## Scenario 1: Implement a Stub Adapter (US1 — Contract Compliance)

**Goal**: Verify that `ProviderAdapterInterface` is implementable and usable in a domain service test without any concrete provider code.

**Steps**:

1. Create a test class implementing `ProviderAdapterInterface`:
   ```php
   use App\Modules\Platform\Domain\Contracts\Providers\ProviderAdapterInterface;
   use App\Modules\Platform\Infrastructure\Providers\NullProviderAdapter;
   use App\Modules\Platform\Domain\Enums\ProviderCapabilityEnum;
   use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderCapabilitySet;

   $adapter = NullProviderAdapter::alwaysSucceed(
       ProviderCapabilitySet::of(ProviderCapabilityEnum::InitiatePayment)
   );
   ```

2. Inject it into a domain service test as if it were a real provider adapter.

**Expected outcome**: The domain service receives a valid `ProviderResponse` from `$adapter->send($request)`. The test passes without any network call. `$adapter->getProviderIdentifier()` returns `'null'`.

**Validation command**:
```shell
./vendor/bin/pest tests/Unit/Platform/Providers/NullProviderAdapterTest.php
```

---

## Scenario 2: Speculative Record Creation and Confirmation (US2 — Financial Safety)

**Goal**: Verify that `ExternalReferenceMappingService` creates a speculative record before the provider call and confirms the reference exactly once.

**Steps**:

1. Run the `ExternalReferenceMappingService` unit test suite:
   ```shell
   ./vendor/bin/pest tests/Unit/Platform/Providers/ExternalReferenceMappingServiceTest.php
   ```

2. Key test cases to verify (all must pass):
   - `createSpeculative()` inserts a row in `platform.provider_external_references` with `provider_reference = NULL`.
   - `confirmProviderReference()` sets `provider_reference` and `reference_confirmed_at` atomically on the first call.
   - A second call to `confirmProviderReference()` on the same record throws `ImmutableReferenceViolationException`.
   - `findByProviderReference()` resolves the platform entity ID for reconciliation.

**Expected outcome**: All four test cases pass. Zero database rows have a non-null `provider_reference` that was written more than once.

---

## Scenario 3: Webhook Idempotency (US3 — Webhook Normalization)

**Goal**: Verify that submitting the same webhook payload twice produces exactly one `NormalizedWebhookEvent` in the outbox.

**Steps**:

1. Run the webhook normalization tests:
   ```shell
   ./vendor/bin/pest tests/Unit/Platform/Providers/NormalizedWebhookEventTest.php
   ```

2. Key test cases:
   - Valid payload + valid signature → returns `NormalizedWebhookEvent` with correct idempotency key.
   - Invalid/missing signature → throws `WebhookSignatureException`.
   - Same payload submitted twice (same idempotency key) → `IdempotencyServiceInterface` returns the cached result; outbox receives only one event.
   - Payload with no matching `ExternalReferenceMapping` → returns `NormalizedWebhookEvent` with `platformEntityId = null` (does not throw).

---

## Scenario 4: Provider Routing and Failover (US4 — Routing Seams)

**Goal**: Verify that `ProviderRoutingInterface` and `ProviderFailoverInterface` seams select correct providers and skip DOWN or incapable ones.

**Steps**:

1. Run the routing and failover tests:
   ```shell
   ./vendor/bin/pest tests/Unit/Platform/Providers/ProviderRoutingInterfaceTest.php
   ```

2. Key test cases:
   - Two `NullProviderAdapter` instances: one with `ProviderHealthStatus::DOWN`, one with `UP`. Router must select the UP adapter.
   - Two adapters: one with `ProviderCapabilitySet::of(InitiatePayment)`, one with `ProviderCapabilitySet::empty()`. Router must select the capable adapter for an `InitiatePayment` request.
   - `ProviderFailoverInterface::shouldFailover()` returns `true` for `isTransient=true` error; `false` for `isTransient=false`.
   - `selectFallback()` never returns the current (failed) adapter.
   - `selectFallback()` returns `null` when the chain is exhausted.

---

## Scenario 5: Reconciliation State Transitions (US5 — Reconciliation)

**Goal**: Verify that EXCEPTION state requires reason and owner, and that silent resolution is rejected.

**Steps**:

1. Run reconciliation tests:
   ```shell
   ./vendor/bin/pest tests/Unit/Platform/Providers/ExternalReferenceMappingServiceTest.php --filter=reconciliation
   ```

2. Key test cases:
   - `match()` on a PENDING record → state transitions to MATCHED; `reconciled_at` is set.
   - `exception()` with empty `$reason` → throws `\InvalidArgumentException`.
   - `exception()` with empty `$owner` → throws `\InvalidArgumentException`.
   - Valid `exception()` → state transitions to EXCEPTION; all required fields are set.
   - `match()` on an EXCEPTION record without prior reason/owner → throws `\DomainException`.

---

## Scenario 6: Concurrency — Unique Constraint (SC-E1000-008)

**Goal**: Verify that concurrent speculative record insertions for the same `(provider_key, provider_reference)` pair produce exactly one confirmed record, not duplicates.

**Steps**:

```shell
./vendor/bin/pest tests/Integration/Platform/ExternalReferenceMappingConcurrencyTest.php
```

This test runs two concurrent `confirmProviderReference()` calls for the same `provider_key` + `provider_reference` value. One must succeed; the other must throw `ImmutableReferenceViolationException` (or a PostgreSQL unique constraint violation caught at the service layer).

**Expected outcome**: Exactly one row exists in `platform.provider_external_references` with the given `(provider_key, provider_reference)` pair after both calls complete.

---

## Scenario 7: Static Analysis Gate (SC-E1000-010)

**Goal**: Verify that no contract interface imports a class from any domain module other than Platform Foundation.

```shell
./vendor/bin/phpstan analyse app/Modules/Platform/Domain/Contracts/Providers \
                              app/Modules/Platform/Domain/ValueObjects/Providers \
                              app/Modules/Platform/Domain/Enums \
                              app/Modules/Platform/Domain/Exceptions \
                              app/Modules/Platform/Application/Services/ExternalReferenceMappingService.php \
                              --level=max
```

**Expected outcome**: Zero errors. Zero imports from `App\Modules\Identity`, `App\Modules\Wallet`, `App\Modules\Ledger`, or any other non-Platform module namespace.

---

## Scenario 8: Full Regression

**Goal**: Verify that E1000 additions do not regress any existing E999 tests.

```shell
./vendor/bin/pest --stop-on-failure
```

**Expected outcome**: All existing tests continue to pass. Net new test count = E1000 tests added.

---

## Validation Checklist

| Scenario | Command | Expected Outcome |
|---|---|---|
| 1 — Contract compliance | `pest NullProviderAdapterTest.php` | Pass; no network calls |
| 2 — Speculative record | `pest ExternalReferenceMappingServiceTest.php` | Pass; one-time-write enforced |
| 3 — Webhook idempotency | `pest NormalizedWebhookEventTest.php` | Pass; duplicate rejected |
| 4 — Routing/failover | `pest ProviderRoutingInterfaceTest.php` | Pass; DOWN/incapable skipped |
| 5 — Reconciliation | `pest ...(--filter=reconciliation)` | Pass; EXCEPTION requires reason+owner |
| 6 — Concurrency | `pest ExternalReferenceMappingConcurrencyTest.php` | Pass; exactly one confirmed row |
| 7 — Static analysis | `phpstan analyse ... --level=max` | Zero errors; zero cross-module imports |
| 8 — Full regression | `pest --stop-on-failure` | All tests pass |

All 8 scenarios must pass before E1000 is considered converged.
