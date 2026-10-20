# Feature Specification: Provider Adapter Foundation (E1000)

**Feature Branch**: `1000-provider-adapter-foundation`

**Created**: 2026-08-26

**Status**: Draft

**Authority**: OKI-ARCH-2026-003 ("Strategic Architecture & Third-Party Integration Framework," v1.0, 25 August 2026) — Classification A (Confirmed Client Decisions) §4, §5, §6, §9, §11, §15

**ADR References**:
- ADR-008: Provider Adapter Architecture (accepted 2026-08-26)
- ADR-009: OTP/SMS Provider Routing and Failover (accepted 2026-08-26)

**Additive Extension of**: E999 Platform Foundation (154/154 tasks converged — NOT modified by this epic)

---

## Overview

OleKard operates as a technology and orchestration platform — not a bank. It must integrate with a replaceable set of external licensed partners: mobile money networks, banking partners, KYC providers, AML providers, fraud/risk engines, OTP/SMS gateways, payment gateways, utility payment rails, and future providers not yet identified.

This feature delivers the **Provider Adapter Foundation**: a family of abstract PHP contracts that define the integration boundary between OleKard's domain services and all external providers. It is the Integration & Adapter Layer required by OKI-ARCH-2026-003's rail-agnostic flow:

```
OleKard Domain Service
        ↓
  Port (abstract contract)
        ↓
  Provider Adapter (concrete — owned by each downstream epic)
        ↓
  External Provider API
```

**What this feature IS:**
- The abstract contract family that every downstream epic (E05 KYC, E08 Funding, E10 Withdrawal, E12/E13 Agent/Float, E15 Utility, E18 AML/Risk, E20 Reconciliation, E25 API Integration) depends on.
- An additive extension to Platform Foundation — new PHP interfaces, value objects, enums, and one database table (`platform.provider_external_references`), added to the existing `app/Modules/Platform/` module.
- The shared normalization shapes: `ProviderRequest`, `ProviderResponse`, `ProviderError`, `NormalizedWebhookEvent`, `ProviderHealthStatus`, `ProviderCapabilitySet`.

**What this feature IS NOT:**
- A concrete provider adapter (no Orange Money, Mascom, bank, KYC, AML, or OTP implementation).
- A modification of any existing E999 interface or implementation.
- A business logic layer (no wallet, ledger, KYC, AML, payment, or utility logic).
- A selection of any provider, vendor, secrets manager, or cloud platform.
- An HTTP routing layer. No Laravel controller for receiving provider webhooks is defined here. The HTTP webhook receiver endpoint is owned by E25 (API Integration Platform). E1000 defines the `ProviderWebhookContract` interface and `NormalizedWebhookEvent` shape that E25's controller will call.

---

## User Scenarios & Testing *(mandatory)*

*Note: This is infrastructure. The "users" of this feature are engineering teams implementing downstream epics. Stories are framed from their perspective.*

### User Story 1 — Epic Engineer Implements a Domain-Neutral Provider Adapter (Priority: P1)

An engineer implementing a downstream epic (e.g., E08 Wallet Funding) needs to integrate an external provider (e.g., an MNO for mobile money deposits). They must be able to build a concrete adapter against a stable, well-specified abstract contract — without knowing anything about other provider adapters that might exist.

**Why this priority**: Every downstream epic from E05 onward depends on the abstract contract family existing before that epic's planning begins. Without P1 delivery, all downstream planning is blocked or must invent divergent patterns.

**Independent Test**: Can be tested by having an engineer write a stub class implementing `ProviderAdapterInterface` and `ProviderCapabilityInterface`, injecting it into a domain service test, and confirming all contract obligations are satisfied without any concrete provider code.

**Acceptance Scenarios**:

1. **Given** a downstream epic needs to integrate an external provider, **When** the engineer creates a PHP class implementing `ProviderAdapterInterface`, **Then** the class satisfies the full contract: `send()` accepting a `ProviderRequest` and returning a `ProviderResponse`, `getCapabilities()` returning a `ProviderCapabilitySet`, and `getProviderIdentifier()` returning a configuration-driven string key.

2. **Given** a domain service must call an external provider, **When** the domain service resolves `ProviderAdapterInterface` from the container, **Then** it receives the concrete adapter bound by configuration without referencing any provider class name in domain code.

3. **Given** an adapter is correctly implemented, **When** a unit test substitutes a test double (implementing `ProviderAdapterInterface`) for the real adapter, **Then** the domain service under test cannot distinguish the test double from a real adapter and all business logic is exercised correctly.

4. **Given** the adapter receives a `ProviderRequest`, **When** the external provider returns an error, **Then** the adapter returns a `ProviderResponse` with a normalized `ProviderError` (carrying `normalized_code`, `is_transient`, and a safe `message`) — the raw provider error code is preserved for audit but never returned to the domain service.

---

### User Story 2 — Epic Engineer Stores and Resolves Provider References (Priority: P1)

An engineer building a financial integration (e.g., E08 Funding, E10 Withdrawal, E20 Reconciliation) needs a durable, immutable, bidirectional mapping between OleKard's internal entity IDs and the provider-assigned reference IDs, so that reconciliation can match platform state to provider settlement statements without re-querying the provider.

**Why this priority**: Without immutable external reference records, reconciliation is impossible and financial integrity cannot be guaranteed across provider boundaries. This is a safety-critical requirement per OKI-ARCH-2026-003 §10.

**Independent Test**: Can be tested by creating an external reference record via `ExternalReferenceMappingService`, verifying the record is stored in `platform.provider_external_references`, attempting an update (which must fail), and then resolving the platform entity from a provider reference and vice versa.

**Acceptance Scenarios**:

1. **Given** an outbound provider call succeeds, **When** the adapter returns a `ProviderResponse` containing a `providerReference`, **Then** the calling application layer persists an `ExternalReferenceMapping` record linking the platform entity ID, the provider identifier key, and the provider reference — this record is append-only.

2. **Given** an `ExternalReferenceMapping` record exists, **When** any code attempts to update the `providerReference` or `platformEntityId` fields, **Then** the attempt is rejected (a domain exception is thrown and no mutation occurs).

3. **Given** a reconciliation process needs to match a provider statement line to a platform transaction, **When** it queries by `providerReference` and `providerKey`, **Then** it receives the associated `platformEntityId` without requiring a live call to the provider.

4. **Given** multiple adapters use different provider key identifiers, **When** querying external references, **Then** each record is unambiguously scoped to its provider by the `providerKey` field.

---

### User Story 3 — Epic Engineer Uses Webhook Normalization (Priority: P2)

An engineer implementing a concrete provider adapter (e.g., for E08 payment status callbacks, E05 KYC verification results) needs a stable contract for normalizing raw provider webhook payloads into a platform-neutral shape — so that the E25-owned HTTP endpoint can call `parse()` without knowing anything about the provider's wire format, and domain services never receive the raw payload.

**Why this priority**: Webhook normalization is required before any epic that receives provider callbacks can be built safely. It must exist and be testable before E08, E20, or E25 webhooks are implemented, but it is not as immediately blocking as the base adapter contract (P1 stories).

**Independent Test**: Can be tested by submitting a recorded raw webhook payload through a `ProviderWebhookContract` test double, verifying the output is a `NormalizedWebhookEvent` with a resolved platform entity ID (via `ExternalReferenceMapping`), and confirming idempotency (submitting the same payload twice produces one queued event, not two).

**Acceptance Scenarios**:

1. **Given** an inbound provider webhook arrives at a platform webhook endpoint, **When** the `ProviderWebhookContract::parse()` implementation validates the provider signature successfully, **Then** it returns a `NormalizedWebhookEvent` containing: a resolved platform entity ID (from `ExternalReferenceMapping`), a normalized event type (platform enum, not provider string), status, amount/currency if financial, provider reference, correlation ID, and the raw payload preserved for audit.

2. **Given** a webhook arrives with an invalid or missing signature, **When** `ProviderWebhookContract::parse()` is called, **Then** it throws a `WebhookSignatureException` (or equivalent), no `NormalizedWebhookEvent` is produced, and the rejection is recorded in the audit log.

3. **Given** the same webhook notification ID is received twice (provider retry), **When** the second instance is processed, **Then** the idempotency layer (ADR-004) prevents a duplicate `NormalizedWebhookEvent` from being queued — the second attempt is acknowledged (HTTP 200) but not re-processed.

4. **Given** a `NormalizedWebhookEvent` is produced, **When** it is queued to the outbox (ADR-005), **Then** the raw provider payload is also stored in the event record for audit, enabling replay and forensic investigation without re-querying the provider.

---

### User Story 4 — Epic Engineer Uses Provider Health and Routing Seams (Priority: P2)

An engineer building an OTP delivery router (ADR-009) or a multi-provider payment orchestrator needs to discover provider capabilities, check health status, and select or fail over between providers — without writing custom routing logic for each integration category.

**Why this priority**: The routing and failover seams are required before OtpDeliveryRouter (ADR-009) can be implemented and before any multi-provider epic can be planned. However, they are not required before single-provider adapters (US1) can be built.

**Independent Test**: Can be tested by creating two test-double adapters with different capability sets and health statuses, injecting both into a `ProviderRoutingInterface` implementation, and verifying that the router selects the healthy, capable provider and skips the DOWN or incapable one.

**Acceptance Scenarios**:

1. **Given** a `ProviderRoutingInterface` implementation has multiple providers registered, **When** it is asked to select a provider for a given request context, **Then** it returns only a provider whose `ProviderCapabilitySet` includes the requested operation and whose `ProviderHealthStatus` is not DOWN.

2. **Given** the selected primary provider returns a `ProviderError` with `is_transient=true`, **When** `ProviderFailoverInterface::shouldFailover()` is called, **Then** it returns `true`; when `selectFallback()` is called, it returns the next available provider in the configured chain (or `null` if the chain is exhausted).

3. **Given** the selected primary provider returns a `ProviderError` with `is_transient=false`, **When** `ProviderFailoverInterface::shouldFailover()` is called, **Then** it returns `false` and no fallback is attempted — the permanent failure is returned to the caller immediately.

4. **Given** a `ProviderHealthInterface` implementation reports `ProviderHealthStatus::DOWN` for a provider, **When** `ProviderRoutingInterface` selects providers, **Then** the DOWN provider is never selected, even if it is first in the configured chain.

---

### User Story 5 — Reconciliation Engineer Uses Normalized Reconciliation State (Priority: P3)

An engineer implementing E20 Reconciliation needs a normalized, provider-neutral reconciliation state model: each external reference can be MATCHED, UNMATCHED, PENDING, or EXCEPTION — and no exception record is ever silently resolved without a status, reason, owner, and timestamp.

**Why this priority**: Required before E20 can be planned, but not immediately blocking E05–E18.

**Independent Test**: Can be tested by creating `ExternalReferenceMapping` records and reconciliation state transitions, verifying that EXCEPTION state requires reason/owner/timestamp fields, and confirming that no record can transition from EXCEPTION to MATCHED without all required exception fields being set.

**Acceptance Scenarios**:

1. **Given** a reconciliation process matches a platform transaction to a provider settlement statement line, **When** the match is recorded, **Then** the `ExternalReferenceMapping` record transitions to state MATCHED with a match timestamp.

2. **Given** a provider settlement line has no corresponding platform transaction, **When** the reconciliation process records the discrepancy, **Then** an EXCEPTION record is created — it MUST carry: status, reason (non-empty), owner identifier, and timestamp. A missing reason or owner is a validation error.

3. **Given** a PENDING external reference exists (outbound call made, provider response not yet confirmed), **When** a webhook or polling update confirms the provider outcome, **Then** the record transitions to MATCHED or EXCEPTION accordingly — it does not remain PENDING indefinitely.

4. **Given** an existing EXCEPTION record, **When** any process attempts to transition it to a resolved state (MATCHED) without providing all required fields, **Then** the transition is rejected and the exception record remains in EXCEPTION state.

---

### Edge Cases

- A downstream epic binds `ProviderAdapterInterface` to a test double that intentionally omits certain capability declarations: routing MUST NOT select this adapter for operations not in its capability set.
- A webhook arrives before the corresponding `ExternalReferenceMapping` record exists (race condition between outbound call and inbound callback): `ProviderWebhookContract::parse()` MUST return a `NormalizedWebhookEvent` with an unresolved platform entity ID rather than throwing — deferred resolution via a background job.
- `getProviderIdentifier()` returns a key that is not in the current configuration: the adapter MUST still construct (the key is for identification purposes), but the routing layer MUST NOT select an unconfigured provider.
- A `ProviderResponse` arrives with a missing or empty `providerReference`: the calling code MUST create an `ExternalReferenceMapping` with a platform-generated surrogate reference and log an audit warning — the absence of a provider reference must not block the platform operation.
- A provider returns both a success response AND a duplicate notification (provider side-effect): idempotency on the `correlationId` prevents double-posting.
- `ProviderCapabilitySet` is empty: the routing layer MUST treat this as an adapter that supports no operations and never select it.

---

## Requirements *(mandatory)*

### Functional Requirements

#### Contract Family

- **FR-E1000-001** | Contract | The platform MUST define `ProviderAdapterInterface` in `app/Modules/Platform/Domain/Contracts/Providers/` with exactly the following responsibilities: `send(ProviderRequest $request): ProviderResponse`, `getCapabilities(): ProviderCapabilitySet`, `getProviderIdentifier(): string`. No domain service MUST reference a concrete adapter class by name.

- **FR-E1000-002** | Contract | The platform MUST define `ProviderCapabilityInterface` with a method that returns the static capability declarations for a bound adapter instance (`getCapabilities(): ProviderCapabilitySet`). Capabilities MUST be declared statically by the adapter, not fetched from the provider API at runtime.

- **FR-E1000-003** | Contract | The platform MUST define `ProviderHealthInterface` with `getHealthStatus(): ProviderHealthStatus`. `ProviderHealthStatus` MUST carry: `status` (enum: UP / DEGRADED / DOWN / UNKNOWN), `last_checked_at` (UTC timestamp), and an optional `reason` string. The mechanism for updating health status is TBD (D).

- **FR-E1000-004** | Contract | The platform MUST define `ProviderRoutingInterface` with at minimum `selectProvider(ProviderRequest $context, array $candidates): ProviderAdapterInterface`. Routing MUST NOT select a provider whose health is DOWN or whose capability set does not include the requested operation. The routing strategy (ordered, round-robin, weighted) is injected; this interface defines the seam only.

- **FR-E1000-005** | Contract | The platform MUST define `ProviderFailoverInterface` with `shouldFailover(ProviderError $error): bool` and `selectFallback(ProviderAdapterInterface $current, array $candidates, ProviderRequest $context): ?ProviderAdapterInterface`. Only `ProviderError` instances with `is_transient=true` MUST return `true` from `shouldFailover()`. Permanent errors MUST return `false`.

- **FR-E1000-006** | Contract | The platform MUST define `ProviderWebhookContract` with `parse(string $rawPayload, string $signature, array $headers): NormalizedWebhookEvent`. Signature validation is provider-specific and implemented by each concrete adapter behind this contract. Invalid or missing signatures MUST throw a `WebhookSignatureException`.

- **FR-E1000-007** | Contract | The platform MUST define `ProviderReconciliationContract` exposing the normalized reconciliation state machine. E1000-managed transitions via contract methods: PENDING → MATCHED (via `match()`); PENDING → EXCEPTION (via `exception()`); EXCEPTION → MATCHED (via `match()` — requires `reconciliation_reason` and `reconciliation_owner` already set on the record). EXCEPTION state MUST carry reason, owner, and timestamp. No EXCEPTION record MUST be silently resolved (OKI-ARCH-2026-003 §10, no-silent-resolution rule). Transitioning a record to MATCHED state is terminal within E1000 — no further E1000 contract methods transition a MATCHED record. The `UNMATCHED` state is reserved in the `ReconciliationState` enum for future reconciliation epics; no E1000 contract method transitions a record to UNMATCHED. The UNMATCHED semantics and the PENDING→UNMATCHED and UNMATCHED→EXCEPTION transitions are deferred to the epic that owns provider-specific settlement reconciliation (planned: E20).

- **FR-E1000-008** | Value Objects | The platform MUST define the following shared value objects and enums used across all contracts:
  - `ProviderRequest` — normalized outbound request: operation type, platform entity ID, amount/currency (if financial), correlation ID, metadata map. MUST NOT contain provider credentials or raw PII.
  - `ProviderResponse` — normalized inbound response: status (SUCCESS / FAILURE), `providerReference` (nullable), amount/currency (if financial), timestamp, `ProviderError` (nullable on success), raw provider status code (for audit only).
  - `ProviderError` — normalized error: `normalized_code` (enum: INSUFFICIENT_FUNDS / PROVIDER_UNAVAILABLE / INVALID_REFERENCE / INVALID_DESTINATION / TIMEOUT / RATE_LIMITED / CREDENTIAL_REJECTED / CONTENT_REJECTED / UNKNOWN — all nine cases; transient codes eligible for failover: PROVIDER_UNAVAILABLE, TIMEOUT, RATE_LIMITED; all others are permanent), `provider_specific_code` (string, for audit only), `is_transient` (bool), `message` (safe string, never raw provider message).
  - `ProviderCapabilitySet` — set of capability keys (enum or string constants); an empty set means the adapter supports no operations.
  - `ProviderHealthStatus` — as defined in FR-E1000-003.
  - `NormalizedWebhookEvent` — normalized inbound event: resolved platform entity ID (nullable on unresolved), normalized event type (platform enum), status, amount/currency (if financial), provider reference, correlation ID, raw payload (for audit), provider key, idempotency key.
  - `ReconciliationState` — enum: PENDING / MATCHED / UNMATCHED / EXCEPTION.
  - `WebhookSignatureException` — thrown when signature validation fails.
  - `ProviderException` — thrown when a provider call produces an unrecoverable error that the adapter cannot normalize.

#### External Reference Mapping

- **FR-E1000-009** | Data | The platform MUST define an `ExternalReferenceMapping` entity (stored in `platform.provider_external_references`) with the following fields: `id` (UUID v7), `platform_entity_id` (UUID v7, the OleKard entity being tracked), `platform_entity_type` (string, e.g., `transaction`, `kyc_verification`), `provider_key` (string, the configuration key, not the credential), `provider_reference` (**nullable** string — null when the record is created speculatively before the provider call; populated once when the provider responds), `reference_confirmed_at` (nullable UTC timestamp — null while speculative; set when `provider_reference` is first written), `operation_type` (string), `reconciliation_state` (enum: ReconciliationState), `reconciliation_reason` (nullable string — required when state is EXCEPTION), `reconciliation_owner` (nullable string — required when state is EXCEPTION), `reconciled_at` (nullable UTC timestamp), `created_at` (UTC timestamp, immutable after creation).

- **FR-E1000-010** | Data Integrity | `platform_entity_id`, `provider_key`, and `platform_entity_type` MUST be immutable after the record is created. `provider_reference` follows a one-time-write rule: transitioning from NULL to a provider-assigned value is permitted exactly once (at the moment the provider acknowledges the call); any subsequent write to `provider_reference` after a non-null value is set MUST throw a domain exception. The table MUST have a unique constraint on `(provider_key, provider_reference)` where NULL values are excluded (standard PostgreSQL behaviour for partial uniqueness on nullable columns).

- **FR-E1000-011** | Data Integrity | A surrogate platform-generated reference MUST be used and flagged in the audit log when a provider response does not include a `providerReference`. This situation MUST NOT block the platform operation.

- **FR-E1000-012** | Query | The `ExternalReferenceMappingService` MUST support: lookup by `(provider_key, provider_reference)` returning the `platformEntityId`; lookup by `(platform_entity_id, platform_entity_type)` returning all associated provider references; listing all records in PENDING state older than a configurable threshold (for timeout detection); listing all records in EXCEPTION state for reconciliation review.

#### Provider Identity and Configuration

- **FR-E1000-013** | Config | Provider identity MUST be configuration-driven. No domain contract, value object, or service class MUST reference a specific provider name (bank name, MNO name, vendor name). Provider identifier keys are lowercase strings derived from configuration (e.g., `mno_primary`, `kyc_vendor`, `otp_gateway`). The key naming convention is defined by each consuming epic.

- **FR-E1000-014** | Config | Timeout values (connect, read), retry counts, chain length, and failover strategy selection MUST be sourced from `platform.configuration_values` via `ConfigurationServiceInterface`. No timeout value, retry count, or strategy identifier MUST be hard-coded. These values remain TBD (D) at the contract level. **Ownership**: This obligation is owned by concrete adapter implementations in downstream epics (E05, E08, E10, E12, E13, E15, E18, E25). E1000's role is limited to mandating the obligation via PHPDoc on `ProviderAdapterInterface::send()`. E1000 does not implement configuration retrieval for provider-specific timeouts and does not create implementation tasks for this behavior.

- **FR-E1000-015** | Security | Provider credentials MUST NOT appear in any contract interface, value object, `ProviderRequest`, `ProviderResponse`, audit event, log entry, outbox event, or metric label. Concrete adapters retrieve credentials via `SecretProviderInterface` (existing, `App\Modules\Platform\Infrastructure\Security\SecretProviderInterface`) using configuration-defined secret name keys. The secret name key (e.g., `provider.otp_gateway.api_key_secret_name`) MAY appear in configuration but MUST NOT appear in domain code.

#### Webhook and Idempotency

- **FR-E1000-016** | Webhook | All inbound provider webhooks MUST be processed through a `ProviderWebhookContract` implementation before any domain service sees the payload. The HTTP endpoint that receives the raw webhook POST is owned by E25 (API Integration Platform) — E1000 defines only the `ProviderWebhookContract` interface, the `NormalizedWebhookEvent` value object, and the idempotency and outbox obligations that any caller of `parse()` must honour. The normalized `NormalizedWebhookEvent` is enqueued to the outbox (ADR-005). Domain services consume from the outbox only.

- **FR-E1000-017** | Idempotency | Webhook receipt MUST be idempotent. The idempotency key for a webhook is the provider-supplied notification ID when present, or a deterministic hash of the raw payload and provider key when not. The existing `IdempotencyServiceInterface` (ADR-004) MUST be used.

- **FR-E1000-018** | Webhook | The raw provider webhook payload MUST be preserved in the `NormalizedWebhookEvent` record for audit, forensic investigation, and replay. The raw payload MUST NOT be processed by domain logic directly.

- **FR-E1000-019** | Webhook | Unresolved webhook events (where `ExternalReferenceMapping` lookup returns no match) MUST be queued as `NormalizedWebhookEvent` with `platform_entity_id=null` rather than rejected. A background resolution process (implementation TBD, D) will attempt resolution once the corresponding external reference record exists.

#### Observability and Audit

- **FR-E1000-020** | Audit | Every outbound provider call MUST produce an audit event via `AuditServiceInterface` containing: operation type, platform entity ID, provider key (not credential), normalized outcome (success / transient-failure / permanent-failure), provider-specific raw status code (for diagnostic use), provider reference, correlation ID, IP hash (HMAC-SHA256 per E01 precedent), duration in milliseconds. Raw OTP, credentials, and PII MUST NOT appear in any audit event.

- **FR-E1000-021** | Metrics | Every provider adapter MUST emit a call counter and latency histogram labeled by `provider_key`, `operation`, and `outcome`. Labels MUST be low-cardinality and MUST NOT carry user ID, mobile number, raw IP, account number, token ID, session ID, or any credential or secret name. `provider_key` refers to the configuration key identifier, not the provider's brand name.

- **FR-E1000-022** | Logging | Structured logs for provider calls MUST include correlation ID and provider key. Logs MUST NOT include raw provider error messages verbatim if they contain PII, credentials, or secrets.

- **FR-E1000-023** | Correlation | All provider calls, webhooks, and reconciliation events MUST carry a correlation ID that links the platform action to the provider operation across all log, audit, and event records.

#### Financial Safety

- **FR-E1000-024** | Financial Safety | Provider failures MUST NOT bypass idempotency, outbox delivery, or audit. A `ProviderException` thrown during a financial operation MUST NOT leave a partial financial state without a compensating event or explicit rollback. The contract layer itself does not implement financial rollback — it mandates the seam (via `ProviderException`) that calling financial services must handle.

- **FR-E1000-025** | Financial Safety | `ProviderRequest` for financial operations MUST carry `amount` and `currency` as typed, non-nullable fields. No financial `ProviderRequest` MUST be constructed with a null or zero amount (this is a validation contract obligation, enforced by the value object constructor).

- **FR-E1000-026** | Financial Safety | `ExternalReferenceMapping` records for financial operations MUST be created before the provider call is initiated (speculative record, with `provider_reference = NULL`), not after the response arrives. The `provider_reference` field MUST be written exactly once when the provider acknowledges the call (setting `reference_confirmed_at` at the same time). This ensures the reference is traceable even if the response is lost or the process crashes mid-call. `createSpeculative()` MUST be idempotent for the same logical operation: if a PENDING record already exists for the same `(platform_entity_id, platform_entity_type, provider_key, operation_type)` combination, the service MUST return the existing record rather than inserting a duplicate. This invariant is enforced at the database layer by a partial unique index on `(platform_entity_id, platform_entity_type, provider_key, operation_type) WHERE reconciliation_state = 'PENDING'`, and at the application-service layer by a find-or-create pattern on unique-constraint violation. Two logically distinct concurrent outbound calls of the same `operation_type` from the same `platform_entity_id` to the same provider MUST be differentiated by distinct `platform_entity_id` values in the calling financial service — they are never concurrent on the same entity.

#### Extraction Readiness

- **FR-E1000-027** | Extraction | The contract family MUST have zero dependency on any domain-specific entity (no wallet types, KYC entities, ledger types). All contracts depend only on Foundation-layer types. This enables the entire contract family to be moved to a shared library or dedicated module without touching any domain service.

- **FR-E1000-028** | Extraction | `platform.provider_external_references` MUST be queryable by both the originating domain module and E20 Reconciliation without cross-module schema coupling. The table belongs to the `platform` schema (existing) or a new `integration` schema — this decision MUST be made during implementation and documented as an ADR addendum.

### Key Entities

- **ExternalReferenceMapping**: Immutable, append-only record linking a platform entity to a provider-assigned reference. One record per provider call per platform entity per operation type. Carries reconciliation state lifecycle.
- **ProviderRequest**: Normalized, outbound, typed value object. No provider-specific fields. Carries correlation ID and platform entity ID.
- **ProviderResponse**: Normalized, inbound, typed value object. Carries `ProviderError` on failure, `providerReference` on success (nullable with surrogate fallback).
- **NormalizedWebhookEvent**: Provider-neutral inbound event. Carries resolved or unresolved platform entity ID, raw payload for audit, and idempotency key.
- **ProviderError**: Normalized error shape. `is_transient` is the only field consumed by routing/failover logic; all other fields are for audit.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-E1000-001**: Every downstream epic (E05, E08, E10, E12, E13, E15, E18, E20, E25) that integrates an external provider can do so by implementing one or more interfaces from the Provider Adapter Foundation contract family, without defining its own adapter abstraction. Zero downstream epics invent a divergent adapter pattern.

- **SC-E1000-002**: Any provider in any category can be replaced by swapping the concrete adapter class and updating the container binding configuration — no domain service, business rule, or event schema requires modification during a provider replacement.

- **SC-E1000-003**: An `ExternalReferenceMapping` record created during a provider call can be resolved to its platform entity by E20 Reconciliation without a live call to the provider — verified by querying by `(provider_key, provider_reference)`.

- **SC-E1000-004**: A webhook submitted twice with the same provider notification ID produces exactly one `NormalizedWebhookEvent` in the outbox, with no duplicate domain processing — idempotency verified under concurrent submission.

- **SC-E1000-005**: No audit event, log entry, metric label, or outbox message produced by the Provider Adapter Foundation contracts contains a provider credential, raw OTP, plaintext PII, or secret name — verified by code review and automated secret-scanning tests.

- **SC-E1000-006**: All contracts pass a static analysis check (PHPStan/Psalm at the configured strictness level) with zero errors. All value objects enforce their invariants (e.g., non-null amount for financial requests) at construction time, so invalid states cannot be represented.

- **SC-E1000-007**: A provider adapter test double (implementing `ProviderAdapterInterface` only) can be injected into a domain service without the service knowing it is a test double — verified by the domain service's unit tests passing with the test double in place.

- **SC-E1000-008**: The `platform.provider_external_references` table withstands concurrent writes from multiple processes without duplicate records for the same `(provider_key, provider_reference)` pair — verified by a concurrency test.

- **SC-E1000-009**: The full contract family (all interfaces, value objects, enums, and the `ExternalReferenceMappingService`) has 100% unit test coverage of all normalization, validation, and state-transition logic, and zero tests that touch a real provider API.

- **SC-E1000-010**: The contracts are demonstrably extraction-ready: no contract interface imports a class from any domain module other than Platform Foundation — verified by a static dependency analysis.

---

## Assumptions

- Platform Foundation (E999, 154/154 tasks converged) is not modified. The Provider Adapter Foundation adds new files to `app/Modules/Platform/` alongside existing files.
- All existing Platform Foundation interfaces (`AuditServiceInterface`, `EventPublisherInterface`, `IdempotencyServiceInterface`, `ConfigurationServiceInterface`, `SecretProviderInterface`) are consumed by the new contracts as stable dependencies — they are not changed.
- The `platform` PostgreSQL schema (already exists) is the default home for `platform.provider_external_references` unless a migration to a new `integration` schema is decided during implementation and recorded as an ADR addendum.
- Concrete provider adapters are implemented exclusively in the Infrastructure layers of their owning epics (E05, E08, E15, E18, E25, etc.) — not in this feature.
- Provider identity (bank names, MNO names, KYC vendors, AML vendors, OTP/SMS providers, payment gateways) is TBD-D for all categories and MUST NOT be asserted or assumed in this feature.
- Secrets manager technology is TBD-D. The existing `SecretProviderInterface` / `EnvSecretProvider` is used for development; production binding is a deployment concern.
- Timeout values, retry counts, failover chain length, and circuit-breaker thresholds are TBD-D (see TBD-E1000-004, TBD-E1000-005); all MUST be sourced from `platform.configuration_values` with no hard-coded values. Absence of a configured timeout MUST be treated as a configuration error, not silently replaced with a default.
- The `ExternalReferenceMapping` surrogate reference behavior (when provider omits a reference) is an implementation-level detail to be specified during planning.
- K6 performance targets for this feature are TBD-D (no provider integration latency targets can be set without provider SLA input).
- This feature does not implement the OtpDeliveryRouter (ADR-009) — that is an E01 implementation task that depends on this feature's contracts being available.
- The `UNMATCHED` case is present in the `ReconciliationState` enum as a reserved value. No E1000 contract method transitions a record to UNMATCHED. The PENDING→UNMATCHED and UNMATCHED→EXCEPTION transitions, and the business rules that govern them (e.g., provider settlement statement mismatch logic), are deferred to the epic that owns provider-specific settlement reconciliation (planned: E20). Downstream epics MUST NOT implement UNMATCHED transitions until E20 defines the semantics and supplies an approved contract method or service extension.

---

## TBD Register

The following items remain TBD per the OKI-ARCH-2026-003 A/B/C/D/E governance model. Classification D = Pending Decision. These MUST NOT be invented or assumed during implementation.

| ID | Item | Classification | Resolution Path |
|---|---|---|---|
| TBD-E1000-001 | Provider identity for all categories (MNO, banking, KYC, AML, OTP/SMS, utility, payment gateway) | D — Pending Decision | Explicit client approval required per OKI-ARCH-2026-003 §8 |
| TBD-E1000-002 | Secrets manager technology (Vault, AWS Secrets Manager, Azure Key Vault, GCP Secret Manager) | D — Pending Decision | Cloud/infrastructure decision; `SecretProviderInterface` abstraction covers until resolved |
| TBD-E1000-003 | Provider SLAs, availability targets, and latency commitments | D — Pending Decision | Set when provider contracts are signed |
| TBD-E1000-004 | Timeout values (connect, read) per provider category | D — Pending Decision | Derived from provider SLAs; configuration-driven |
| TBD-E1000-005 | Retry count per operation and per provider category | D — Pending Decision | Configuration-driven |
| TBD-E1000-006 | Circuit-breaker strategy and thresholds | D — Pending Decision | `ProviderHealthInterface` seam defined; concrete strategy TBD |
| TBD-E1000-007 | Failover strategy (ordered-list, round-robin, weighted, affinity) | D — Pending Decision | `ProviderFailoverInterface` seam defined; concrete strategy TBD |
| TBD-E1000-008 | Maximum provider chain length | D — Pending Decision | Configuration-driven |
| TBD-E1000-009 | Schema placement for `provider_external_references` (`platform` vs. new `integration` schema) | D — Pending Decision | Implementation planning decision; requires ADR addendum |
| TBD-E1000-010 | Mechanism for updating `ProviderHealthStatus` (polling, circuit-breaker, provider webhook, or combination) | D — Pending Decision | Operational concern; contract defines the seam |
| TBD-E1000-011 | Background resolution process for unresolved webhooks (scheduler, queue listener, or both) | D — Pending Decision | Infrastructure concern; implementation TBD |
| TBD-E1000-012 | Whether `ProviderReconciliationContract` should remain in Foundation or migrate to E20 | D — Pending Decision | Resolved when E20 is planned |
| TBD-E1000-013 | Utility payment protocol (STS vs. DLMS-COSEM) | D — Pending Decision | Requires confirmation against BPC/WUC target environment |
| TBD-E1000-014 | K6 performance targets for provider-adapter layer | D — Pending Decision | Derived from provider SLAs |
| TBD-E1000-015 | `ProviderCapabilitySet` value format (PHP enum, string constants, or bit-flag set) | C — Technical Recommendation | Resolved during implementation planning; recommendation: PHP 8.1 backed enum |

---

## Clarifications

### Session 2026-08-26

- Q: How should `ExternalReferenceMapping` represent its pre-call speculative state given that `provider_reference` must be immutable after creation? → A: `provider_reference` is nullable at creation. The immutability rule is one-time-write: NULL → provider-value is permitted once (at acknowledgement time); any subsequent write is rejected. A `reference_confirmed_at` timestamp is set when the reference is written. The unique constraint on `(provider_key, provider_reference)` excludes NULLs by PostgreSQL default. (FR-E1000-009, FR-E1000-010, FR-E1000-026)
- Q: Should `ExternalReferenceMapping` schema live in `platform` schema or a new `integration` schema? → A: `platform` schema by default; migration to a dedicated `integration` schema is a TBD-D decision recorded as ADR-008 addendum during implementation. (TBD-E1000-009)
- Q: Should domain-specific adapter contracts (KYC document/selfie, AML sanctions query) be part of this feature or owned by each domain epic? → A: Domain-specific contracts are owned by their domain epic. This feature delivers only the generic shared base contracts. Domain epics MAY extend `ProviderAdapterInterface` where the generic base fits, or define a standalone contract where it does not. The criterion is: fewer than 50% of method signatures shared with the generic base → standalone contract. (ADR-008 §2.3)
- Q: Is `OtpDeliveryRouter` (ADR-009) in scope for this feature? → A: No. `OtpDeliveryRouter` is an E01 implementation task. This feature's contracts provide the `ProviderAdapterInterface` and `ProviderRoutingInterface` / `ProviderFailoverInterface` seams that `OtpDeliveryRouter` will use. E01 implements the router after this feature converges.
- Q: Should the HTTP controller that receives raw provider webhook POST requests be part of E1000 or owned by E25? → A: E25 (API Integration Platform) owns the HTTP webhook receiver endpoint. E1000 defines only `ProviderWebhookContract`, `NormalizedWebhookEvent`, and the idempotency/outbox obligations — no Laravel controller. This keeps E1000 free of HTTP framework coupling. (FR-E1000-016, Overview IS NOT)
