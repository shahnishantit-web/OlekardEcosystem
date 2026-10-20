# Research: Provider Adapter Foundation (E1000)

**Date**: 2026-08-26
**Branch**: `1000-provider-adapter-foundation`

---

## R-001: PHP Interface and Value Object Patterns for Provider Adapters

**Decision**: PHP 8.4 interfaces with readonly value objects.

**Rationale**: PHP 8.4 readonly classes with constructor property promotion provide immutable value objects with zero boilerplate. PHP 8.1 backed enums provide type-safe, serializable capability and error-code representations. Both are in the project's approved PHP version range and require no additional libraries.

**Alternatives considered**:
- Doctrine Embeddables: unnecessary coupling to ORM for pure domain objects.
- Spatie Laravel Data / DTOs: third-party dependency not currently approved in the stack; existing Platform Foundation uses plain readonly classes.
- Abstract classes instead of interfaces: rejected because PHP allows implementing multiple interfaces but only extending one abstract class — downstream adapters may legitimately need to implement multiple contracts.

---

## R-002: ProviderCapabilitySet Implementation

**Decision**: PHP 8.1 backed string enum `ProviderCapabilityEnum` with cases per recognized operation category. `ProviderCapabilitySet` is a readonly value object wrapping a deduplicated `ProviderCapabilityEnum[]`.

**Resolved from**: TBD-E1000-015 (C — Technical Recommendation).

**Rationale**: String-backed enums produce human-readable values in logs and audit events (e.g., `"initiate_payment"` rather than an integer). `ProviderCapabilityEnum` cases cover cross-cutting capabilities shared across multiple provider categories:

| Case | String value | Used by |
|---|---|---|
| `InitiatePayment` | `initiate_payment` | MNO, banking, payment gateway |
| `QueryBalance` | `query_balance` | MNO, banking |
| `ReceiveWebhook` | `receive_webhook` | all categories with callbacks |
| `ReversePriorPayment` | `reverse_prior_payment` | payment gateway, banking |
| `DeliverOtp` | `deliver_otp` | SMS/OTP |
| `VerifyIdentity` | `verify_identity` | KYC |
| `ScreenSanctions` | `screen_sanctions` | AML |
| `ScoreRisk` | `score_risk` | fraud/risk |
| `GenerateUtilityToken` | `generate_utility_token` | utility payment |
| `QueryTransactionStatus` | `query_transaction_status` | all financial categories |

Domain-specific epics may extend this set by registering additional capability values via configuration; the core set is defined here. `ProviderCapabilitySet` does not reject unknown string values at runtime (to support forward-compatibility) but provides `has(ProviderCapabilityEnum $cap): bool` for type-safe lookups.

**Alternatives rejected**: Bit-flags (no native PHP type-safety); raw string constants (no autocomplete, typo-prone); separate interface per capability (combinatorial explosion).

---

## R-003: ExternalReferenceMapping — Speculative Record Pattern

**Decision**: `provider_reference` is nullable at creation (speculative state). One-time write: NULL → provider-value permitted once; subsequent writes throw `ImmutableReferenceViolationException`. `reference_confirmed_at` is set atomically with the first write to `provider_reference`.

**Resolved from**: Q1 clarification (2026-08-26).

**Rationale**: The speculative record is required for financial durability: if the process crashes after initiating a provider call but before receiving the response, the record must already exist to enable crash-recovery and reconciliation (FR-E1000-026). Making `provider_reference` nullable with one-time-write semantics is the simplest model: no secondary table, no surrogate reference chaining, no additional columns.

PostgreSQL unique constraint on `(provider_key, provider_reference)` naturally excludes NULLs, so multiple speculative (null) records for different platform entities are permitted — only confirmed references must be unique per provider.

**Alternatives rejected**:
- Surrogate UUID placeholder (Option B): requires cross-record linkage for reconciliation queries; lookup by provider reference would need to join across the surrogate.
- Separate `speculative_reference_id` column (Option C): adds a column that is never used by reconciliation queries; complicates the unique constraint.

**Implementation note**: `ExternalReferenceMappingService::confirmProviderReference(string $id, string $providerReference, Carbon $confirmedAt): void` performs:
1. Find record by `$id`.
2. If `provider_reference !== null` → throw `ImmutableReferenceViolationException`.
3. Check global unique constraint: if `(provider_key, $providerReference)` already exists → throw `ImmutableReferenceViolationException` (duplicate provider reference from same provider).
4. Update `provider_reference`, `reference_confirmed_at` in a single atomic write.

---

## R-004: Webhook HTTP Endpoint Ownership

**Decision**: E25 (API Integration Platform) owns the HTTP webhook receiver. E1000 defines only the `ProviderWebhookContract` interface and `NormalizedWebhookEvent` value object.

**Resolved from**: Q2 clarification (2026-08-26).

**Rationale**: E25 is the dedicated API Integration Platform; routing inbound external callbacks through a single, E25-owned HTTP surface is architecturally consistent with the rail-agnostic flow (OKI-ARCH-2026-003). E1000 providing the interface without an HTTP controller keeps it as a pure contracts-and-infrastructure module — no Laravel routing, no HTTP coupling.

**Implication for E25 planning**: E25 must plan and implement a webhook receiver controller that calls `ProviderWebhookContract::parse()` on the appropriate concrete adapter (selected by provider key from the request path or headers). E25 must honour FR-E1000-016 through FR-E1000-019 in its controller implementation.

---

## R-005: Migration Sequencing

**Decision**: Migration file name `2026_09_01_001000_create_provider_external_references.php`. The timestamp `2026_09_01` sequences the migration after all existing Platform Foundation migrations (which use `2026_08_01`). The sequence suffix `001000` signals the E1000 epic.

**Rationale**: Consistent with the existing Platform Foundation migration naming convention. Using a September date prevents any possibility of conflict with Foundation's August migrations, even if Foundation migrations are discovered/added in the future.

---

## R-006: Service Layer vs. ORM Enforcement for Immutability

**Decision**: Immutability of `provider_reference` is enforced at the `ExternalReferenceMappingService` layer, not inside the Eloquent model's `setAttribute()` override.

**Rationale**: Service-layer enforcement:
- Is testable in unit tests without a database connection.
- Is visible to code reviewers in a predictable location.
- Avoids obscure Eloquent lifecycle hook behavior that can be bypassed by direct `Model::query()->update()` calls.

**Mitigation for model-bypass risk**: The Eloquent model marks `provider_reference` and `reference_confirmed_at` as part of `$guarded = ['provider_reference', 'reference_confirmed_at']` to prevent mass-assignment. All legitimate writes go through `ExternalReferenceMappingService`.

---

## R-007: Test Double Strategy

**Decision**: `NullProviderAdapter` is a configurable in-code stub — not a mock framework mock. It is placed in `Infrastructure/Providers/` (importable by all consumers, not test-only). It implements `ProviderAdapterInterface` with a constructor that accepts a `ProviderResponse` to return (or a `ProviderException` to throw) on `send()` calls.

**Rationale**: Downstream epics need a concrete stub for container binding during local development — before any real provider adapter is available. A test-only stub would need to be duplicated across every downstream epic's test suite. Placing it in `Infrastructure/Providers/` makes it importable by any epic's `ServiceProvider` test binding.

**Security note**: `NullProviderAdapter` MUST NOT be registered as the live container binding in a production environment. Its `getProviderIdentifier()` returns `'null'` — this serves as a self-documenting marker that it is a stub.

---

## R-008: Financial ProviderRequest Named Constructor

**Decision**: `ProviderRequest::forFinancialOperation(Money $amount, string $platformEntityId, string $platformEntityType, string $operationType, string $correlationId, array $metadata = []): self` enforces non-null, non-zero amount. `ProviderRequest::forNonFinancialOperation(string $platformEntityId, string $platformEntityType, string $operationType, string $correlationId, array $metadata = []): self` omits the financial fields.

**Rationale**: Named constructors express intent at the call site; they prevent accidentally creating a financial request with a null amount. Reuses existing `Money` and `Currency` value objects from Platform Foundation.

---

## R-009: ProviderReconciliationContract Placement

**Decision**: `ProviderReconciliationContract` is defined as an interface in E1000. Whether it migrates to E20 is TBD-E1000-012, to be resolved during E20 planning. E1000 provides the contract; E20 provides the implementation.

**Rationale**: The contract must exist for E1000 to be independently testable (the `ExternalReferenceMappingService::exception()` method signature must satisfy this interface). Deferring the interface definition to E20 would block E1000's completion.

---

## R-010: Correlation ID Propagation

**Decision**: All `ProviderRequest` instances carry a `correlationId` (string, UUID v7 format, generated by the calling domain service). This is the same correlation ID used in the platform's audit events, outbox events, and log context. The provider adapter passes it to the provider API in a standard header (`X-Correlation-ID` or equivalent) where the provider API supports it. The adapter does not generate its own correlation ID.

**Rationale**: Single correlation ID per platform operation enables end-to-end tracing from domain service → adapter → external provider → webhook callback → outbox → domain service. Generating a new correlation ID inside the adapter would break the trace chain.
