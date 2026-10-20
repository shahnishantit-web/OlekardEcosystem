# ADR-008: Provider Adapter Architecture

**Status**: Accepted

**Date**: 2026-08-26

**Deciders**: OleKard Architecture Team

**Authority**: OKI-ARCH-2026-003, "Strategic Architecture & Third-Party Integration Framework," v1.0, 25 August 2026 (§4, §5, §6, §9, §11, §15 — classification A: Confirmed Client Decisions)

**Supersedes**: None

**Related ADRs**: ADR-003 (schema-per-module), ADR-004 (idempotency strategy), ADR-005 (outbox pattern), ADR-009 (OTP/SMS provider routing)

---

## 1. Context

OKI-ARCH-2026-003 requires OleKard to operate as a technology/orchestration platform — not a bank — interacting with a set of external, replaceable, licensed partners:

- **Mobile money networks**: Orange Money (Botswana), Mascom MyZaka, BTC Smega — and any future MNO rails
- **Banking partners**: multi-bank (no single institution locked in)
- **KYC providers**: vendor-neutral orchestration (provider TBD — classification D)
- **AML/sanctions providers**: vendor-neutral (provider TBD — classification D)
- **Fraud/risk engine**: optional external capability (provider TBD)
- **OTP/SMS providers**: primary/fallback/alternate-channel (provider TBD-001)
- **Utility payment rails**: BPC/WUC or equivalent; protocol pending confirmation (STS/DLMS-COSEM TBD — classification D)
- **Payment gateway/settlement providers**: rail-agnostic (providers TBD)

The client's required rail-agnostic flow is:

```
Customer → App → Identity & Security → Wallet/Transaction Orchestration →
Integration & Adapter Layer → Bank/MNO/Payment Provider/Utility →
Settlement & Reconciliation → Customer Confirmation
```

**The Integration & Adapter Layer in this flow is entirely absent** from Platform Foundation, E01, and all other completed epics. No `ProviderAdapterInterface`, webhook normalization contract, health/status model, failover seam, or routing abstraction exists anywhere in the codebase.

Without a shared adapter architecture:

- Each downstream epic (E05 KYC, E08 Funding, E10 Withdrawal, E12/E13 Agent/Float, E15 Utility, E18 AML/Risk, E20 Reconciliation, E25 API Integration) would independently invent divergent adapter shapes, creating reconciliation inconsistency, inconsistent webhook handling, divergent error taxonomies, and extraction friction.
- Replacing any provider would require surgery across multiple epics rather than swapping a single concrete implementation behind a stable contract.

This ADR resolves the architectural question raised in the impact analysis (§6, §19): *where should the shared provider-adapter contracts be defined — Platform Foundation, E25, or a hybrid?*

---

## 2. Decision

**Apply the hexagonal (ports & adapters) pattern to all external provider integrations.**

**Abstract contracts are defined in Platform Foundation** (as an additive extension to the existing contracts layer). Concrete adapter implementations live in the epic that owns the domain capability.

### 2.1 Rationale for Foundation Location

Platform Foundation is the natural home for the abstract provider-adapter contract family because:

1. Multiple downstream epics (E05, E08, E10, E12, E15, E18, E20, E25) need the same abstract shapes *before* E25 (API Integration Platform) is itself specced and planned. If the contracts live only in E25, every epic planned before E25 must either wait or diverge.
2. ADR-003 (schema-per-module) and ADR-005 (outbox pattern) follow the same pattern: abstract contract in Foundation, concrete implementations in each epic's Infrastructure layer.
3. Platform Foundation already defines `EventPublisherInterface`, `AuditServiceInterface`, `ConfigurationServiceInterface`, and `IdempotencyServiceInterface` using this model successfully. Provider adapter contracts are the same kind of cross-cutting infrastructure concern.
4. Defining abstract contracts in Foundation does **not** require reopening Foundation's implementation; it is an additive extension to `specs/999-platform-foundation/contracts/module-contracts.md`.

### 2.2 Port/Adapter Model

Each integration category is a **port** — a named boundary in the domain model. The port is defined as a PHP interface in `app/Modules/Platform/Domain/Contracts/Providers/`. The concrete adapter that crosses the network boundary is an **adapter**, living in the Infrastructure layer of the epic that owns the domain capability.

```
Domain Service (e.g., FundingService)
    ↓ calls
ProviderAdapterInterface (port — abstract, in Platform Foundation)
    ↑ injected by
ConcreteAdapter (adapter — e.g., OrangeMoneySendAdapter, implements the port)
    ↓ calls
External Provider API / Webhook Listener
```

No domain service ever directly references a named provider (Orange Money, Mascom, etc.). Domain services reference only the port interface. Provider identity is resolved at container-binding time via configuration.

### 2.3 Abstract Contract Family

The following abstract contracts are approved for definition in Platform Foundation. No implementation is prescribed here; none is to be written before the Foundation extension ADR (separate work item, not part of this ADR). Contract names are illustrative — precise method signatures belong to the Foundation spec extension task.

| Contract | Responsibility | Home |
|---|---|---|
| `ProviderAdapterInterface` | Base contract for all provider adapters: `send(ProviderRequest): ProviderResponse`, `getCapabilities(): ProviderCapabilitySet`, `getProviderIdentifier(): string` | Platform Foundation contracts |
| `ProviderCapabilityInterface` | Declares what a bound provider instance can do (e.g., can_initiate_payment, can_query_balance, supports_webhook); enables capability discovery without reflection | Platform Foundation contracts |
| `ProviderHealthInterface` | `getHealthStatus(): ProviderHealthStatus` (UP / DEGRADED / DOWN / UNKNOWN) | Platform Foundation contracts |
| `ProviderRoutingInterface` | Selects which bound provider to use for a given request context; decouples routing logic from domain services | Platform Foundation contracts |
| `ProviderFailoverInterface` | Defines the seam where a routing failure triggers a fallback selection strategy; does not implement the strategy | Platform Foundation contracts |
| `ExternalReferenceMapping` | Bidirectional mapping between provider-side reference ID and platform-side transaction/entity ID; stored immutably per transaction | Platform Foundation contracts |
| `ProviderWebhookContract` | Webhook payload normalization: `parse(rawPayload, signature, headers): NormalizedWebhookEvent`; signature-verified, provider-neutral event shape | Platform Foundation contracts |
| `ProviderReconciliationContract` | Matched/unmatched/pending/exception states for settlement; enforces no-silent-resolution (OKI-ARCH-2026-003 §10) | Platform Foundation contracts |

**Domain-specific contracts** — where the integration requires a shape genuinely incompatible with a generic base (e.g., KYC verification involving document/selfie metadata that has no analogue in a payment adapter) — are defined within the owning epic's `Domain/Contracts/Providers/` layer and may extend `ProviderAdapterInterface` or stand alone. The criterion is: *if two categories share fewer than 50% of their method signatures, they should be separate contracts, not forced into a universal interface*.

### 2.4 Provider Identity and Reference Mapping

- Provider identity is **configuration-driven**, not hard-coded. The container resolves the correct concrete adapter at boot time based on the active configuration value (e.g., `funding.mno_provider`, `kyc.provider`). No domain code references a provider class by name.
- The `ExternalReferenceMapping` record (one per outbound provider call) stores: platform entity ID, provider-assigned reference, provider identifier key, operation type, timestamp (UTC), and immutability flag. It is append-only and never updated after the provider acknowledges the reference.
- Provider references MUST be preserved in the platform's event and audit records so that reconciliation can match platform state to provider statement without re-calling the provider API.

### 2.5 Normalized Request/Response Model

Each `ProviderAdapterInterface` implementation:

- Accepts a **normalized** `ProviderRequest` (platform-side data, no provider-specific fields).
- Translates internally to the provider's wire format.
- Returns a **normalized** `ProviderResponse` (platform-side result: status, reference, amount, currency, timestamp, raw provider status code).
- The raw provider status code is preserved in the response for diagnostic/audit purposes but is never used for conditional business logic outside the adapter.

The domain layer only ever sees the normalized model. Adapter-internal serialization is the adapter's concern.

### 2.6 Webhook and Callback Normalization

- All inbound provider webhooks are received by a single platform-owned controller (or E25-owned controller) that validates the signature, acknowledges the webhook (HTTP 200), and enqueues a `NormalizedWebhookEvent` to the outbox.
- Signature validation algorithm is provider-specific; each adapter supplies its own signature-validation implementation behind `ProviderWebhookContract::parse()`.
- The `NormalizedWebhookEvent` carries: platform entity ID (resolved via `ExternalReferenceMapping`), event type (normalized enum, not provider-specific string), status, amount, currency, provider reference, correlation ID, and raw payload (for audit).
- Downstream domain services consume the `NormalizedWebhookEvent` from the outbox — they never parse the raw provider webhook payload.
- Idempotency: webhook receipt uses the existing platform idempotency infrastructure (ADR-004) with the provider-supplied notification ID or a content hash as the idempotency key.

### 2.7 Provider Error Normalization

Provider-specific HTTP error codes, response bodies, and error strings are mapped to a normalized `ProviderError` within the adapter:

```
ProviderError {
    normalized_code: ProviderErrorCode  // INSUFFICIENT_FUNDS | PROVIDER_UNAVAILABLE | INVALID_REFERENCE | TIMEOUT | UNKNOWN
    provider_specific_code: string      // raw provider error code, for audit/logging only
    is_transient: bool                  // true = eligible for retry/failover; false = permanent failure
    message: string                     // normalized message, safe to surface in audit; never raw provider message
}
```

`is_transient` is the key signal for the failover seam (§2.11). Domain logic must not inspect `provider_specific_code` for business decisions.

### 2.8 Timeout and Failure Abstraction

- Timeout configuration (connect timeout, read timeout) is provided per provider via `platform.configuration_values` keys (e.g., `provider.{category}.timeout_ms`). Values are **TBD** — not invented here.
- The adapter is responsible for catching HTTP client timeouts and returning a `ProviderError(normalized_code=TIMEOUT, is_transient=true)`.
- Domain services never set HTTP timeout values directly; they are an adapter/infrastructure concern.
- Circuit-breaker behavior (if any) is an infrastructure-layer concern; no circuit-breaker interface is mandated by this ADR, but the `ProviderHealthInterface` provides the integration point for a circuit-breaker implementation to report and query health state.

### 2.9 Capability Discovery

- `ProviderCapabilityInterface::getCapabilities(): ProviderCapabilitySet` allows the routing layer to select the correct provider for a request when multiple providers support different subsets of operations.
- Capabilities are declared statically by each concrete adapter, not fetched from the provider's API at runtime. They reflect what the platform's integration with that provider supports, not a live API health query.
- Capability discovery is used at provider selection time, not at domain-service invocation time. Domain services request an operation; the routing layer finds a capable bound provider.

### 2.10 Health and Status Model

`ProviderHealthStatus` carries three fields: `status` (UP / DEGRADED / DOWN / UNKNOWN), `last_checked_at` (UTC timestamp), and `reason` (optional string, TBD format). The mechanism for updating health status (polling, circuit-breaker signal, webhook from provider, or all three) is TBD and outside the scope of this ADR. The contract only specifies the shape of the health report.

### 2.11 Failover Seam

`ProviderFailoverInterface` is the seam — not the implementation — for failover. It declares:

```
shouldFailover(ProviderError): bool
selectFallback(currentProvider, availableProviders, context): ProviderAdapterInterface|null
```

The failover strategy (e.g., ordered list, round-robin, weighted) is supplied as a concrete implementation injected by the container. This ADR does not prescribe a strategy; it mandates the seam exists. Each concrete strategy is a separate injectable class.

Failover rules:
- Only `is_transient=true` errors trigger failover. Permanent failures (INSUFFICIENT_FUNDS, INVALID_REFERENCE) do not retry.
- Failover is attempted within a single request context, not as an asynchronous background retry (which is the outbox/retry infrastructure concern).
- The number of failover attempts, and whether to fail-open or fail-closed, are **TBD** — not set here. They will be configuration-driven when specified.

### 2.12 Provider Routing Seam

`ProviderRoutingInterface` selects the initial provider for a given request. The routing strategy (e.g., "always use configured primary", "round-robin", "affinity to last-successful provider") is a concrete implementation injected by the container. This ADR mandates the seam, not the strategy.

Routing is aware of: provider capability set, health status, and request context (currency, amount, region if applicable). Routing MUST NOT select a DOWN or capability-mismatched provider.

### 2.13 Security and Credential Boundary

- **No provider credential appears in any contract**, normalized model, PHP interface, or domain service.
- Provider credentials (API key, secret, certificate, OAuth client secret) are externalized to the secret manager (technology TBD — see TBD register).
- The concrete adapter retrieves credentials at initialization via `SecretProviderInterface` (already defined in Platform Foundation), keyed by configuration-defined secret names (e.g., `provider.orange_money.api_key_secret_name`). The adapter never stores the credential value; it retrieves it per-request or on adapter boot, per the secret manager's security guidance.
- No secret name, key identifier, or secret value is included in `ProviderRequest`, `ProviderResponse`, audit events, logs, or outbox events.
- Provider-facing TLS is handled at the adapter's HTTP client layer; no TLS certificate pinning is mandated by this ADR (it is a deployment/infrastructure concern).

### 2.14 Audit and Observability

Every outbound provider call MUST produce an audit event via Platform Foundation's `AuditServiceInterface` containing:

- Platform entity ID and operation type
- Provider identifier key (config key name, not credential)
- Provider reference (from `ExternalReferenceMapping`)
- Normalized outcome (success / transient-failure / permanent-failure)
- Provider-specific raw status code (for reconciliation/diagnostic use)
- Correlation ID
- HMAC-SHA256 IP representation (per E01 FR-E01-050 precedent)
- Duration in milliseconds

No audit event MUST contain: provider credential, raw OTP, PII beyond the correlation/entity ID.

Metrics: each adapter emits a counter and latency histogram per provider call outcome, labeled by `provider_key` (the config key name, not the credential), `operation`, and `outcome`. Labels MUST be low-cardinality and MUST NOT carry user ID, account number, raw IP, or any credential.

### 2.15 Extraction and Service-Boundary Implications

The adapter contract family is explicitly designed to support extraction:

- The `ProviderAdapterInterface` and its contract family live in `Platform/Domain/Contracts/Providers/`. They have zero dependency on any domain-specific entity. They can be moved to a shared library or a dedicated `IntegrationPlatformModule` without touching any domain service.
- Each concrete adapter is already isolated in the Infrastructure layer of its owning epic. Moving an epic to a separate service means moving its adapters with it; the abstract contracts stay shared.
- The `ExternalReferenceMapping` store is the only stateful cross-cutting concern; it must be accessible to both the originating domain module and the future reconciliation module (E20). Its schema placement (in the `platform` schema or a dedicated `integration` schema) is a TBD decision for the Foundation extension task.

### 2.16 Testing Strategy

- Each concrete adapter ships with a **test double** (implementing the abstract port interface) in its test infrastructure. Domain services are tested only against test doubles, never against real provider APIs.
- **Contract tests** verify that each concrete adapter correctly translates a given `ProviderRequest` to the expected wire format and correctly parses a given wire response into a `ProviderResponse`. Contract tests run against recorded HTTP fixtures or a provider sandbox, not the production API.
- **Integration tests** test the full adapter → real provider sandbox round-trip (optional, environment-dependent; skipped in CI if sandbox unavailable).
- **Failover/routing tests** use multiple test doubles and verify that `ProviderRoutingInterface` and `ProviderFailoverInterface` implementations select and sequence correctly under simulated transient failures.

---

## 3. Consequences

### Positive

- Any provider can be replaced by swapping one concrete adapter implementation without touching domain services, business logic, or event schemas.
- Downstream epics (E05, E08, E10, E12, E15, E18, E20, E25) get a consistent adapter shape from day one of their planning, preventing divergence.
- Webhook normalization, error normalization, and reference mapping are solved once for all epics.
- Extraction-ready by construction — adapters are already in the wrong layer to create a circular dependency.
- Audit and observability are consistent across all provider integrations.

### Negative

- Platform Foundation requires an additive extension to `specs/999-platform-foundation/contracts/module-contracts.md` and a new implementation pass — additional planned work (scoped separately, not in this ADR).
- Introducing `ProviderRoutingInterface` and `ProviderFailoverInterface` adds complexity that is unnecessary until more than one provider exists. Early epics (E05 KYC, E08 Funding) may bind only a single provider initially and derive little immediate benefit from the routing/failover seam; however, the seam is low cost and prevents retrofit when the second provider arrives.

### Neutral

- Domain-specific adapter contracts (KYC, AML) that cannot share the generic base are fully permitted by this ADR. The goal is provider neutrality within each domain, not a universal interface that flattens genuine domain differences.
- Provider SLAs, retry counts, and timeout values are TBD (§4) — this ADR establishes the seams for them but does not assert values.

---

## 4. Alternatives Considered

| Alternative | Reason Rejected |
|---|---|
| **Generic contracts in E25 only; domain epics wait for E25** | E08/E10/E12/E15 are sequenced before E25. All would need to invent their own adapter patterns independently, creating the divergence this ADR is designed to prevent. |
| **One universal `ProviderAdapterInterface` for all categories** | KYC verification (documents, selfie metadata), AML sanctions screening, MNO mobile-money transfers, and utility payment (STS token generation) have fundamentally different request shapes and response semantics. Forcing them into a single universal interface produces a lowest-common-denominator contract that is useless for domain services. Domain-specific extensions are explicitly permitted. |
| **No shared contracts; each epic defines its own** | Creates reconciliation inconsistency, divergent webhook handling, and blocks E20 from matching transactions across epics without bespoke translation layers. Directly contradicts OKI-ARCH-2026-003 §15 rail-agnostic requirement. |
| **Generic contracts in a new dedicated `IntegrationModule` (not Foundation)** | Feasible, but creates a sequencing dependency: E25 must be specced before E05/E08 can plan. Foundation's existing contract layer avoids this dependency. Can be moved to a dedicated module later (extraction-ready). |

---

## 5. TBD Items

The following are explicitly NOT resolved by this ADR and MUST NOT be assumed or invented:

| Item | Classification | Notes |
|---|---|---|
| Provider identity for any category (MNO, banking, KYC, AML, OTP/SMS, utility, payment gateway) | D — Pending Decision | No provider is selected. All remain TBD. |
| Secrets manager technology | D — Pending Decision | `SecretProviderInterface` abstraction exists; concrete binding is TBD |
| Provider SLAs (availability, latency targets) | D — Pending Decision | Not invented; will be set when provider contracts are signed |
| Retry counts per operation and per provider | D — Pending Decision | Configuration-driven; values TBD |
| Circuit-breaker strategy and thresholds | D — Pending Decision | Seam provided via `ProviderHealthInterface`; strategy TBD |
| Failover strategy (ordered-list, round-robin, weighted) | D — Pending Decision | `ProviderFailoverInterface` seam defined; concrete strategy TBD |
| `ExternalReferenceMapping` schema placement (`platform` vs. `integration` schema) | D — Pending Decision | Foundation extension task will decide |
| Maximum provider chain length | D — Pending Decision | Configuration-driven; value TBD |
| Cloud provider, region, or deployment topology | D — Pending Decision | Provider-neutral and cloud-neutral by design |
| Utility payment protocol (STS vs. DLMS-COSEM) | D — Pending Decision | Requires confirmation against BPC/WUC target environment |
| Whether `ProviderReconciliationContract` belongs in Foundation or E20 | D — Pending Decision | To be resolved when E20 is planned |

---

*This ADR was authored 2026-08-26 in response to gap A identified in the OKI-ARCH-2026-003 impact analysis (`docs/architecture/client-response-impact-analysis-2026-08-25.md` §6, §15, §19).*
