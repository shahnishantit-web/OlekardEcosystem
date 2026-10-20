# Implementation Plan: Provider Adapter Foundation (E1000)

**Branch**: `1000-provider-adapter-foundation` | **Date**: 2026-08-26 | **Spec**: [spec.md](./spec.md)

**Additive Extension of**: E999 Platform Foundation — no existing file is modified.

---

## Summary

E1000 delivers the abstract provider-adapter contract family mandated by OKI-ARCH-2026-003 and formalised in ADR-008 and ADR-009. It adds new PHP interfaces, value objects, enums, exceptions, one database table, and one application service to the existing `app/Modules/Platform/` module. All downstream epics (E05–E25 that require external provider integration) depend on these contracts before they can be planned.

Technical approach: additive PHP 8.4 interfaces and readonly value objects under `app/Modules/Platform/Domain/Contracts/Providers/` and `Domain/ValueObjects/Providers/`, backed by one Eloquent model + migration for `platform.provider_external_references`, and an `ExternalReferenceMappingService` in the Application layer. A `NullProviderAdapter` test double is provided for downstream epic test infrastructure. No concrete provider implementation is included.

---

## Technical Context

**Language/Version**: PHP 8.4+ (project standard — constitution §Status)

**Framework**: Laravel 11+ (project standard — constitution §Status)

**Primary Dependencies**: Pest (testing), PHPStan/Psalm (static analysis), existing Platform Foundation interfaces (AuditServiceInterface, IdempotencyServiceInterface, ConfigurationServiceInterface, SecretProviderInterface, EventPublisherInterface)

**Storage**: PostgreSQL — `platform` schema (existing). One new table: `platform.provider_external_references`. Migration uses the same `$connection = 'platform'` pattern as existing Platform migrations.

**Testing**: Pest (project standard) — unit tests for all interfaces, value objects, enums, exceptions, and service logic; contract compliance tests for `NullProviderAdapter`; concurrency test for the unique constraint

**Target Platform**: Laravel application server (modular monolith, extraction-ready)

**Project Type**: Infrastructure library extension — pure contracts, value objects, and one domain service. No HTTP controllers.

**Performance Goals**: TBD-E1000-014 (provider SLA input required). The contract layer itself adds no measurable latency — all interfaces are abstract; concrete adapters carry the latency.

**Constraints**:
- All new code under `app/Modules/Platform/` — zero changes to existing files in that module except an additive registration in `PlatformServiceProvider::register()`
- No provider name, credential, SLA value, timeout, or retry count hard-coded anywhere
- All value objects are readonly PHP 8.4 classes with constructor-enforced invariants
- `provider_reference` on `ExternalReferenceMapping` is nullable at creation; one-time-write semantics enforced at service layer

**Scale/Scope**: Contract layer is stateless. `platform.provider_external_references` will eventually contain one row per outbound provider call across all epics. Scale assumptions are deferred to E20 (Reconciliation) which owns the retention and archival strategy for this table.

---

## Constitution Check

*GATE: Must pass before Phase 0. Re-checked after Phase 1 design.*

| Principle | Status | Notes |
|---|---|---|
| P1 — Source-of-Truth Requirements | ✅ PASS | Spec aligned with OKI-ARCH-2026-003 classification A decisions; ADR-008/009 accepted |
| P2 — TBD Governance | ✅ PASS | 15 TBD items in register; none invented; all classification D except TBD-E1000-015 (C — recommendation) |
| P3 — Configuration Over Hard-Coding | ✅ PASS | Timeouts, retry counts, chain length, failover strategy all sourced from `platform.configuration_values`. Absence of config = error, not silent default |
| P4 — Financial Integrity | ✅ PASS | FR-E1000-026 speculative record before call; FR-E1000-024 ProviderException seam; immutable ExternalReferenceMapping; no financial logic implemented |
| P5 — Identity and Authorization | ✅ PASS | No auth logic; contracts carry correlation_id and platform_entity_id for audit traceability |
| P6 — Zero Trust and Security | ✅ PASS | Credentials never in contracts, value objects, audit, logs, or metrics; SecretProviderInterface used by concrete adapters |
| P7 — Domain Boundaries | ✅ PASS | Contracts have zero dependency on any domain-specific entity; extraction-ready by construction |
| P8 — API Contracts | N/A | E1000 adds no HTTP endpoints. Webhook HTTP endpoint owned by E25. |
| P9 — Event Reliability | ✅ PASS | NormalizedWebhookEvent is enqueued via outbox (ADR-005); webhook idempotency via IdempotencyServiceInterface |
| P10 — UX and Design System | N/A | Infrastructure only; no UI |
| P11 — Accessibility | N/A | Infrastructure only; no UI |
| P12 — Test-First Quality | ✅ PASS | 100% unit test coverage required (SC-E1000-009); contract compliance tests; concurrency test for SC-E1000-008 |
| P13 — Observability | ✅ PASS | FR-E1000-020 audit per call; FR-E1000-021 metrics; FR-E1000-022 structured logging; FR-E1000-023 correlation ID |
| P14 — No Silent Architecture Changes | ✅ PASS | ADR-008 and ADR-009 accepted; no stack change |
| P15 — Release Governance | ✅ PASS | Quality gates defined in success criteria SC-E1000-001 through SC-E1000-010 |

**Post-Design Re-check**: No violations introduced during Phase 1. All new types are additive.

---

## Project Structure

### Documentation (this feature)

```text
specs/1000-provider-adapter-foundation/
├── plan.md              ← this file
├── research.md          ← Phase 0 output
├── data-model.md        ← Phase 1 output
├── quickstart.md        ← Phase 1 output
├── contracts/
│   └── provider-adapter-contracts.md   ← Phase 1 output
├── checklists/
│   └── requirements.md  ← created during /speckit-specify
└── tasks.md             ← /speckit-tasks output (not yet created)
```

### Source Code

```text
wallet-ecosystem/services/api/
├── app/Modules/Platform/
│   ├── Domain/
│   │   ├── Contracts/
│   │   │   └── Providers/                                  # NEW directory
│   │   │       ├── ProviderAdapterInterface.php
│   │   │       ├── ProviderCapabilityInterface.php
│   │   │       ├── ProviderHealthInterface.php
│   │   │       ├── ProviderRoutingInterface.php
│   │   │       ├── ProviderFailoverInterface.php
│   │   │       ├── ProviderWebhookContract.php
│   │   │       └── ProviderReconciliationContract.php
│   │   ├── Enums/                                          # NEW directory
│   │   │   ├── ProviderErrorCode.php
│   │   │   ├── ProviderStatus.php
│   │   │   └── ReconciliationState.php
│   │   ├── Exceptions/                                     # NEW directory
│   │   │   ├── ProviderException.php
│   │   │   ├── WebhookSignatureException.php
│   │   │   └── ImmutableReferenceViolationException.php
│   │   └── ValueObjects/
│   │       └── Providers/                                  # NEW directory
│   │           ├── ProviderRequest.php
│   │           ├── ProviderResponse.php
│   │           ├── ProviderError.php
│   │           ├── ProviderCapabilitySet.php
│   │           ├── ProviderHealthStatus.php
│   │           └── NormalizedWebhookEvent.php
│   ├── Application/
│   │   └── Services/
│   │       └── ExternalReferenceMappingService.php         # NEW
│   ├── Infrastructure/
│   │   ├── Migrations/
│   │   │   └── 2026_09_01_001000_create_provider_external_references.php  # NEW
│   │   ├── Persistence/
│   │   │   └── ProviderExternalReferenceModel.php          # NEW (Eloquent model)
│   │   └── Providers/
│   │       └── NullProviderAdapter.php                     # NEW (test double)
│   └── Providers/
│       └── PlatformServiceProvider.php                     # ADDITIVE — register ExternalReferenceMappingService
└── tests/
    └── Unit/Platform/Providers/                            # NEW test directory
        ├── ProviderAdapterInterfaceTest.php
        ├── ProviderRoutingInterfaceTest.php
        ├── ProviderFailoverInterfaceTest.php
        ├── ExternalReferenceMappingServiceTest.php
        ├── NullProviderAdapterTest.php
        └── NormalizedWebhookEventTest.php
    └── Integration/Platform/
        └── ExternalReferenceMappingConcurrencyTest.php     # NEW (SC-E1000-008)
```

**Structure Decision**: Single additive module under existing `app/Modules/Platform/`. New sub-directories under `Domain/Contracts/Providers/`, `Domain/Enums/`, `Domain/Exceptions/`, `Domain/ValueObjects/Providers/`, and `Infrastructure/Providers/` keep the new work cleanly separated from existing Platform Foundation files.

---

## Design Decisions

### D-001: ProviderCapabilitySet representation
**Decision**: PHP 8.1+ backed string enum `ProviderCapabilityEnum` with cases for each recognized operation category. `ProviderCapabilitySet` is a readonly value object wrapping a `Set<ProviderCapabilityEnum>` (implemented as a readonly array with deduplication). Resolves TBD-E1000-015 (C — Technical Recommendation).
**Rationale**: PHP 8.1 backed enums are type-safe, IDE-friendly, serializable, and require no external library. String backing allows meaningful values in logs and audit events without exposing raw integers.
**Alternatives rejected**: Bit-flags (no native PHP support, bitwise errors); string constants (not type-safe); interface with methods (over-engineered for capability declaration).

### D-002: ExternalReferenceMapping entity storage
**Decision**: `platform.provider_external_references` in the existing `platform` PostgreSQL schema. `$connection = 'platform'` on the Eloquent model (same pattern as existing Platform migrations).
**Rationale**: Platform schema is already accessible from all modules. Avoids a new schema/database connection before E20 determines final reconciliation topology.
**Migration path**: If E20 planning requires a dedicated `integration` schema, a future migration moves the table — this is TBD-E1000-009, to be resolved as an ADR-008 addendum.

### D-003: One-time-write enforcement for provider_reference
**Decision**: `ExternalReferenceMappingService::confirmProviderReference()` checks for an existing non-null `provider_reference` before writing. If already set, throws `ImmutableReferenceViolationException`. The Eloquent model does NOT override `setAttribute()` — enforcement is at the service layer, not ORM layer.
**Rationale**: Service-layer enforcement is testable in isolation without database. ORM-level overrides are harder to audit.

### D-004: ProviderRequest financial vs. non-financial distinction
**Decision**: `ProviderRequest` carries optional `amount` (`Money|null`) and `currency` (`Currency|null`) fields. A named constructor `ProviderRequest::forFinancialOperation(Money $amount, ...)` enforces non-null amount. Non-financial requests use `ProviderRequest::forNonFinancialOperation(...)`. Both use the existing `Money` and `Currency` value objects from Platform Foundation.
**Rationale**: Reuses proven Foundation value objects; named constructors make the financial/non-financial distinction explicit at the call site.

### D-005: NullProviderAdapter test double scope
**Decision**: `NullProviderAdapter` lives in `Infrastructure/Providers/` (not a test-only directory) so that downstream epics can import it as a development stub via service container binding during local development. It implements `ProviderAdapterInterface` and returns a configurable `ProviderResponse` (success or simulated failure). It never makes any network call.
**Rationale**: Downstream epics need a concrete stub for container binding before a real adapter is available. A test-only stub would require duplicating this across every downstream epic.

### D-006: ExternalReferenceMapping as Entity (not Value Object)
**Decision**: `ExternalReferenceMapping` is a domain entity (has identity, persisted, stateful reconciliation lifecycle) — not a value object. It is represented in the domain by a plain PHP readonly class and in the infrastructure by `ProviderExternalReferenceModel` (Eloquent). Domain code receives the entity class; infrastructure handles persistence.
**Rationale**: The reconciliation state lifecycle (PENDING → MATCHED/EXCEPTION) requires mutable state on a persisted identity. A pure value object cannot represent this.

### D-007: Migration timestamp convention
**Decision**: Migration file named `2026_09_01_001000_create_provider_external_references.php` — using a date in September 2026 to sequence after all existing Platform Foundation migrations (which use August 2026 dates). The suffix `001000` signals the E1000 epic origin.
**Rationale**: Consistent with existing Platform Foundation migration naming; clearly sequences after Foundation without conflicting.

### D-008: ProviderWebhookContract location in E1000
**Decision**: `ProviderWebhookContract` is defined as an interface in this feature. No concrete implementation or HTTP controller is included. Concrete implementations belong to domain epics (e.g., E08, E05) or E25. E25 owns the HTTP webhook receiver endpoint that calls `parse()`.
**Rationale**: Resolved in Q2 clarification. E1000 must not become an HTTP routing module.

---

## Implementation Phases

### Phase 1: Core Contract Interfaces (P0, no dependencies)

Define all abstract PHP interfaces, enums, exceptions, and value objects. No database. No HTTP. No service bindings.

Files created:
- `Domain/Contracts/Providers/ProviderAdapterInterface.php`
- `Domain/Contracts/Providers/ProviderCapabilityInterface.php`
- `Domain/Contracts/Providers/ProviderHealthInterface.php`
- `Domain/Contracts/Providers/ProviderRoutingInterface.php`
- `Domain/Contracts/Providers/ProviderFailoverInterface.php`
- `Domain/Contracts/Providers/ProviderWebhookContract.php`
- `Domain/Contracts/Providers/ProviderReconciliationContract.php`
- `Domain/Enums/ProviderErrorCode.php`
- `Domain/Enums/ProviderStatus.php`
- `Domain/Enums/ReconciliationState.php`
- `Domain/Exceptions/ProviderException.php`
- `Domain/Exceptions/WebhookSignatureException.php`
- `Domain/Exceptions/ImmutableReferenceViolationException.php`
- `Domain/ValueObjects/Providers/ProviderRequest.php`
- `Domain/ValueObjects/Providers/ProviderResponse.php`
- `Domain/ValueObjects/Providers/ProviderError.php`
- `Domain/ValueObjects/Providers/ProviderCapabilitySet.php`
- `Domain/ValueObjects/Providers/ProviderHealthStatus.php`
- `Domain/ValueObjects/Providers/NormalizedWebhookEvent.php`

### Phase 2: ExternalReferenceMapping Entity and Service (P1, depends on Phase 1)

Files created:
- `Domain/ValueObjects/Providers/ExternalReferenceMapping.php` (domain entity readonly class)
- `Application/Services/ExternalReferenceMappingService.php`
- `Infrastructure/Migrations/2026_09_01_001000_create_provider_external_references.php`
- `Infrastructure/Persistence/ProviderExternalReferenceModel.php`

`PlatformServiceProvider.php`: additive registration of `ExternalReferenceMappingService`.

### Phase 3: NullProviderAdapter Test Double (P2, depends on Phase 1)

Files created:
- `Infrastructure/Providers/NullProviderAdapter.php`

### Phase 4: Tests (P3, depends on Phases 1–3)

Files created under `tests/Unit/Platform/Providers/` and `tests/Integration/Platform/`:
- Contract compliance test for `NullProviderAdapter` (verifies it satisfies `ProviderAdapterInterface`)
- Unit tests for all value objects and enums (invariant enforcement)
- `ExternalReferenceMappingServiceTest.php` — including one-time-write violation, PENDING query, EXCEPTION validation
- `ExternalReferenceMappingConcurrencyTest.php` — concurrent inserts for same `(provider_key, provider_reference)` (SC-E1000-008)
- Routing and failover seam tests using two `NullProviderAdapter` instances
- Audit event emission tests (via `AuditServiceInterface` spy)

### Phase 5: Static Analysis and Dependency Gate (P4, depends on Phases 1–4)

Run PHPStan at configured level on all new files. Verify zero domain imports outside Platform Foundation (SC-E1000-010). Run full Pest regression.

---

## Key Technical Decisions: Interface Signatures

These are the authoritative method signatures for this implementation. See `contracts/provider-adapter-contracts.md` for complete documentation.

```php
// ProviderAdapterInterface
public function send(ProviderRequest $request): ProviderResponse;
public function getCapabilities(): ProviderCapabilitySet;
public function getProviderIdentifier(): string;

// ProviderCapabilityInterface
public function getCapabilities(): ProviderCapabilitySet;

// ProviderHealthInterface
public function getHealthStatus(): ProviderHealthStatus;

// ProviderRoutingInterface
public function selectProvider(ProviderRequest $context, array $candidates): ProviderAdapterInterface;

// ProviderFailoverInterface
public function shouldFailover(ProviderError $error): bool;
public function selectFallback(ProviderAdapterInterface $current, array $candidates, ProviderRequest $context): ?ProviderAdapterInterface;

// ProviderWebhookContract
public function parse(string $rawPayload, string $signature, array $headers): NormalizedWebhookEvent;

// ProviderReconciliationContract
public function getState(string $providerKey, string $providerReference): ReconciliationState;
public function match(string $providerKey, string $providerReference): void;
public function exception(string $providerKey, string $providerReference, string $reason, string $owner): void;
```

---

## Dependency Map

```
E1000 (this feature)
  └── Depends on (read-only):
       ├── AuditServiceInterface          (E999 — no change)
       ├── IdempotencyServiceInterface    (E999 — no change)
       ├── ConfigurationServiceInterface  (E999 — no change)
       ├── EventPublisherInterface        (E999 — no change)
       ├── SecretProviderInterface        (E999 — no change)
       ├── Money, Currency value objects  (E999 — no change)
       └── platform schema (PostgreSQL)   (E999 — additive new table only)

E05–E25 (downstream)
  └── Depend on (new contracts from E1000):
       ├── ProviderAdapterInterface
       ├── ProviderCapabilityInterface
       ├── ProviderHealthInterface
       ├── ProviderRoutingInterface
       ├── ProviderFailoverInterface
       ├── ProviderWebhookContract        (E25 owns HTTP caller)
       ├── ProviderReconciliationContract (E20 plans after E1000 converges)
       ├── ExternalReferenceMappingService
       └── NullProviderAdapter            (dev/test stub)
```

---

## Complexity Tracking

No constitution violations. Complexity table not required.
