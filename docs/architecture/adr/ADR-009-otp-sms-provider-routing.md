# ADR-009: OTP/SMS Provider Routing and Failover

**Status**: Accepted

**Date**: 2026-08-26

**Deciders**: OleKard Architecture Team

**Authority**: OKI-ARCH-2026-003, "Strategic Architecture & Third-Party Integration Framework," v1.0, 25 August 2026 (§5, §8, §9, §19 — classification A: Confirmed Client Decisions)

**Supersedes**: None

**Related ADRs**: ADR-001 (Platform Foundation), ADR-004 (idempotency strategy), ADR-005 (outbox pattern), ADR-008 (provider adapter architecture)

---

## 1. Context

### 1.1 Current State

E01 Central Identity & Authentication defines OTP delivery via a single interface:

```php
// App\Modules\Identity\Domain\Contracts\OtpDeliveryContract
interface OtpDeliveryContract {
    public function deliver(
        string $destination,
        string $otp,
        string $channelType,
        string $correlationId
    ): OtpDeliveryResult;
}
```

`OtpDeliveryResult` carries three fields: `bool $delivered`, `string $providerReference`, `?string $failureReason`.

The current implementation binds one concrete class to `OtpDeliveryContract` in the service container. There is no routing, no fallback, and no alternate-channel logic.

### 1.2 OKI-ARCH-2026-003 Requirement

OKI-ARCH-2026-003 §5 and §9 require a primary → fallback → alternate-channel routing model for all OTP and SMS delivery. The client explicitly requires:

1. **Provider neutrality**: No OTP/SMS provider is ever hard-coded or treated as permanently selected without formal client approval. Current provider identity is TBD (TBD-001 in the impact analysis register).
2. **Fallback capability**: If the primary provider fails, delivery should automatically attempt a fallback provider before surfacing an error.
3. **Alternate channel**: If both primary and fallback fail, the platform must route to a non-SMS channel (e.g., voice, USSD, email) where applicable.
4. **Rail-agnostic API behavior**: E01 API endpoints (`/v1/auth/otp/request`, `/v1/auth/otp/verify`, etc.) must not expose provider identity to callers. Provider selection is internal to the delivery layer.

### 1.3 OTP Use Cases Requiring Routing

The following E01 flows must all benefit from the routing/fallback model — no OTP use case is exempt:

| Use Case | E01 Endpoint(s) |
|---|---|
| Registration OTP | `POST /v1/auth/register/request` |
| Login verification | `POST /v1/auth/login` (OTP-based credential) |
| Device verification | `POST /v1/auth/devices/verify` |
| PIN reset | `POST /v1/auth/pin/reset/*` |
| Step-up authentication | `POST /v1/auth/step-up/verify` |
| High-risk transaction confirmation | Downstream from wallet/transaction epics via Identity step-up |

### 1.4 Problem

Introducing a routing layer above `OtpDeliveryContract` must be done in a way that:

- Does NOT break the existing `OtpDeliveryContract` interface — it is already the stable boundary between `OtpService` and the delivery infrastructure.
- Does NOT require any change to `OtpService` calling code.
- Does NOT expose provider identity in audit events, logs, or API responses.
- Preserves the existing idempotency contract (ADR-004): delivery idempotency is keyed on `correlationId`; a routing retry with the same `correlationId` must not re-deliver an OTP already confirmed delivered.

---

## 2. Decision

**Introduce `OtpDeliveryRouter` as an implementation of `OtpDeliveryContract`** that sits between `OtpService` and the concrete provider adapters. The existing `OtpDeliveryContract` interface is **not changed**. The existing `OtpService` calling code is **not changed**.

### 2.1 Layered Model

```
OtpService
    ↓ calls deliver(destination, otp, channelType, correlationId)
OtpDeliveryRouter          ← implements OtpDeliveryContract (this ADR)
    ↓ selects provider(s) and attempts delivery in order
ProviderAdapter[primary]   ← implements OtpDeliveryContract (one per bound provider)
ProviderAdapter[fallback]  ← implements OtpDeliveryContract (if primary fails)
AlternateChannelAdapter    ← implements OtpDeliveryContract (if both fail; channel TBD)
```

`OtpDeliveryRouter` is injected into the service container in place of a single concrete adapter wherever `OtpDeliveryContract` is resolved. `OtpService` is unaware of the routing; it sees only `OtpDeliveryContract`.

### 2.2 OtpDeliveryRouter Responsibilities

`OtpDeliveryRouter`:

1. Holds an ordered list of `OtpDeliveryContract` provider adapter instances (primary, fallback, alternate-channel).
2. Attempts delivery against each provider in order.
3. Stops and returns a successful `OtpDeliveryResult` as soon as any provider succeeds.
4. Classifies each failure as transient or permanent before deciding to proceed to the next provider (§2.5).
5. Returns a permanent failure `OtpDeliveryResult` immediately if the failure is non-transient (there is no point attempting fallback for INVALID_DESTINATION).
6. Returns a failed `OtpDeliveryResult` (with a normalized failure reason) only after all providers in the chain have been exhausted.
7. Emits an audit event per attempt (§2.8).
8. Does NOT modify the OTP value, the destination, or the `channelType`.
9. Does NOT retry the same provider more than once per `deliver()` call — retries within a provider are the adapter's internal concern (if any).

### 2.3 Provider Selection and Ordering

The ordered provider chain is assembled at container-binding time, driven by configuration. Configuration keys follow the pattern:

```
identity.otp.provider_chain = [primary, fallback, alternate]
```

The values are provider identifier keys — not class names or credentials. The container resolves the identifier key to a bound `OtpDeliveryContract` adapter. No provider class name or credential appears in the chain configuration value.

Order in the chain determines priority. The router iterates the chain index from 0 to n; it does not re-rank at runtime based on observed latency or success rate (that is a future optimization, not mandated here).

Provider identifier key values are **TBD**. No provider names (Twilio, Orange, Infobip, etc.) are hard-coded here or anywhere in the codebase.

### 2.4 Delivery Result Normalization

Each provider adapter returns a standard `OtpDeliveryResult`. The router does not transform the result it returns — when a provider succeeds, the router returns that provider's `OtpDeliveryResult` verbatim (including `providerReference`). When all providers fail, the router returns a synthesized `OtpDeliveryResult` with `delivered=false` and a normalized `failureReason` (e.g., `"OTP delivery failed after all configured providers were exhausted"`). The synthesized reason MUST NOT enumerate provider names or per-provider error strings.

### 2.5 Provider Failure Classification

Failures are classified before the router decides whether to advance to the next provider:

| Failure Class | `is_transient` | Router Behavior |
|---|---|---|
| Provider network timeout | `true` | Advance to next provider |
| Provider HTTP 5xx / service unavailable | `true` | Advance to next provider |
| Provider rate-limited (HTTP 429) | `true` | Advance to next provider |
| Provider rejected destination (invalid number, unreachable) | `false` | Return permanent failure immediately — no fallback |
| Provider rejected OTP content (policy violation) | `false` | Return permanent failure immediately |
| Provider credential invalid (HTTP 401/403 from provider) | `false` | Return permanent failure immediately; alert/audit |

The concrete adapter is responsible for mapping provider-specific error codes to the `is_transient` classification (following ADR-008 §2.7). The router reads only the `is_transient` signal.

### 2.6 Alternate Channel Model

The third slot in the provider chain is the alternate-channel adapter. The alternate channel represents a non-SMS delivery mechanism (e.g., voice call, USSD prompt, email). The alternate-channel adapter also implements `OtpDeliveryContract` with the same interface — the router does not distinguish between it and an SMS provider at the interface level.

The `channelType` parameter passed by `OtpService` indicates the caller's requested channel (e.g., `SMS`, `EMAIL`). If `channelType` is already `EMAIL`, the router MUST NOT route through an SMS provider (or vice versa). The router must skip providers in the chain whose delivery channel is incompatible with `channelType`. Compatibility mapping (which provider identifier keys support which `channelType` values) is declared statically in each adapter's capability definition (ADR-008 §2.9) and consulted at routing time.

Alternate-channel activation logic (e.g., "only use voice if destination is a mobile number in a specific country") is a concrete `ProviderRoutingInterface` concern (ADR-008 §2.12). This ADR defines the seam; the strategy is TBD.

### 2.7 Idempotency Interaction

The platform idempotency mechanism (ADR-004) is applied at the `OtpService` level — the `correlationId` is the idempotency key for the entire OTP delivery request. From the perspective of the router:

- If idempotency is resolved at the service level before `deliver()` is called (i.e., a cached `OtpDeliveryResult` is returned without calling the router), the router is never invoked. This is the preferred happy path.
- If the router is invoked with a `correlationId` that has already produced a `delivered=true` result, the idempotency layer should prevent the second invocation entirely. The router does not implement idempotency itself — it trusts the caller.
- If an attempt to provider A fails transiently and the router advances to provider B, both attempts share the same `correlationId`. The first failed attempt is recorded in the audit log; the successful attempt's `providerReference` is stored against the `correlationId`. A subsequent replay of the same `correlationId` returns the previously stored `OtpDeliveryResult` without re-invoking the router.
- The router MUST NOT deliver an OTP to more than one provider successfully for a single `correlationId`. Once any provider returns `delivered=true`, the router immediately returns and does not attempt the next provider in the chain.

### 2.8 Audit and Observability

**Per-attempt audit event** (emitted via `AuditServiceInterface`):

| Field | Value |
|---|---|
| `event_type` | `OTP_DELIVERY_ATTEMPTED` |
| `correlation_id` | the `correlationId` passed by `OtpService` |
| `provider_key` | provider identifier key (config key name, never credential) |
| `provider_reference` | the provider-assigned reference if available; null on failure |
| `channel_type` | the `channelType` argument |
| `outcome` | `DELIVERED` / `FAILED_TRANSIENT` / `FAILED_PERMANENT` |
| `chain_position` | integer (0 = primary, 1 = fallback, 2 = alternate) |
| `duration_ms` | wall-clock milliseconds for this provider attempt |

**Per-request final audit event**:

| Field | Value |
|---|---|
| `event_type` | `OTP_DELIVERY_COMPLETED` or `OTP_DELIVERY_EXHAUSTED` |
| `correlation_id` | the `correlationId` |
| `successful_provider_key` | provider identifier key of the succeeding provider, or null |
| `chain_positions_attempted` | integer count |
| `total_duration_ms` | total wall-clock milliseconds across all attempts |

**Metrics** (counters and histograms per provider_key and outcome):

- `otp_delivery_attempt_total{provider_key, channel_type, outcome}`
- `otp_delivery_duration_ms{provider_key, channel_type}` (histogram)
- `otp_delivery_chain_exhausted_total{channel_type}` (counter — all providers failed)

Labels MUST NOT carry: OTP value, destination phone number, raw IP, user ID, session ID, or any credential. `provider_key` is the config key name (bounded, low-cardinality).

### 2.9 OTP Security Constraints

These constraints are unconditional and apply regardless of routing behavior:

- The OTP value (`$otp`) MUST NOT appear in any audit event, metric label, log line, or event body — at any layer of the routing stack.
- The destination (`$destination`) MUST NOT appear in audit events or logs in raw form. If the destination must be referenced for diagnostic purposes, use a consistent hash (HMAC-SHA256) keyed on the platform secret.
- Provider credentials MUST NOT appear in any audit event, log, routing configuration value, or error message propagated above the adapter layer.
- The `providerReference` returned by a provider (the provider's delivery receipt ID) MAY appear in audit events for reconciliation purposes — it is not a credential.
- A failed OTP delivery attempt MUST NOT produce a user-facing error message that discloses the provider's identity, the reason for failure at the provider level, or whether an alternate channel was attempted.

### 2.10 Provider-Independent API Behavior

All E01 API endpoints (`/v1/auth/otp/request`, `/v1/auth/otp/verify`, etc.) have fixed request/response schemas that are stable regardless of which provider in the chain delivered the OTP. Specifically:

- API responses MUST NOT include: provider name, provider reference, chain position, or which fallback was used.
- API response HTTP status codes for OTP delivery failure (e.g., `503 Service Unavailable`) are determined by the final `OtpDeliveryResult.delivered` value, not by the individual provider error. The E01 spec §11 error semantics are unchanged.
- The caller cannot distinguish between "primary provider delivered" and "fallback delivered" from the API response. Routing is transparent to the API caller.
- `OtpService` returns the same business-level events and API responses regardless of which chain position succeeded. The `correlationId` used by `OtpService` is unchanged.

### 2.11 Configuration Boundary

All routing configuration is externalized to `platform.configuration_values` and read via `ConfigurationSecurityService` (or an equivalent service-layer accessor). No routing configuration value is compiled into code.

Configuration keys (illustrative; exact keys TBD in Foundation extension task):

```
identity.otp.provider_chain        = ["primary_key", "fallback_key", "alternate_key"]
identity.otp.channel_compatibility = { "primary_key": ["SMS"], "fallback_key": ["SMS"], "alternate_key": ["EMAIL"] }
```

The maximum chain length is bounded by configuration, not by code. The default chain length and whether the alternate channel slot is mandatory or optional are **TBD**.

No hardcoded provider class references appear in any configuration key value. The mapping from provider identifier key to concrete adapter class is handled exclusively by the service container's binding map, itself driven by configuration.

### 2.12 Future Provider Replacement

Replacing a provider requires:

1. Writing a new concrete adapter class implementing `OtpDeliveryContract`.
2. Registering the new adapter against a new or existing provider identifier key in the container binding map.
3. Updating `identity.otp.provider_chain` configuration to reference the new key.

No changes to `OtpService`, `OtpDeliveryRouter`, or any E01 business logic are required. No migration of OTP codes or delivery records is required. Existing in-flight `OtpDeliveryResult` records (referenced by `providerReference`) remain valid and are not affected.

### 2.13 Testing Strategy

**Unit tests for `OtpDeliveryRouter`**:

- Primary succeeds → result from primary is returned, no fallback attempted.
- Primary fails transiently → fallback is attempted; fallback succeeds → result from fallback returned.
- Primary fails transiently → fallback fails transiently → alternate-channel adapter attempted; succeeds → result returned.
- Primary fails permanently → no fallback attempted, permanent failure returned immediately.
- All providers fail transiently → exhausted result returned with normalized failure reason.
- `delivered=true` from primary → router returns immediately without calling fallback (no double-delivery).
- Audit events emitted for each attempt (verify count and field values using a spy).
- Idempotent: calling `deliver()` with the same `correlationId` twice (when the idempotency layer is bypassed in unit test) does not double-deliver.

All tests use test doubles for the concrete provider adapters (no real network calls). Each test double is a class implementing `OtpDeliveryContract` with controllable `deliver()` outcomes.

**Integration tests** (environment-dependent, may be skipped in local CI):

- Full router + real provider sandbox adapter (primary only) → confirm real provider accepts the normalized `deliver()` call.
- Simulated primary timeout → confirm fallback adapter is invoked.

**Contract tests per concrete adapter**:

- Verify that a `ProviderError(is_transient=true)` is returned for each expected transient error code from that provider.
- Verify that `ProviderError(is_transient=false)` is returned for permanent errors.
- Verify that `OtpDeliveryResult.providerReference` is populated on success.

---

## 3. Consequences

### Positive

- `OtpService` and all E01 calling code is unchanged — routing is transparently added below the existing stable boundary.
- Any SMS/OTP provider can be replaced or re-ordered without touching business logic.
- Delivery resilience improves: transient provider failures no longer surface as user-facing errors if a fallback is available.
- Audit trail captures full delivery chain for compliance and reconciliation.
- Provider identity is never exposed in API responses, logs, or user-facing errors.

### Negative

- `OtpDeliveryRouter` adds a new class that must be implemented, tested, and maintained.
- Routing with multiple providers introduces latency in the failure path: if primary fails with a transient error after a full HTTP timeout, the delivery chain may take multiple timeout durations before exhausting. Timeout values must be set appropriately (TBD) to avoid unacceptably long user-visible delays on OTP request endpoints.
- Container-binding wiring becomes slightly more complex: the `identity-public` throttle bucket and the OTP idempotency key must still correctly identify a delivery attempt even when routing introduces internal retries.

### Neutral

- `OtpDeliveryContract` interface signature is unchanged. This ADR adds no new methods and deprecates nothing.
- Existing test doubles that implement `OtpDeliveryContract` remain valid test doubles for `OtpDeliveryRouter` configuration in unit tests.
- The alternate-channel slot may be left unconfigured (empty) without violating this ADR; the router simply stops after primary and fallback if no alternate is bound.

---

## 4. Alternatives Considered

| Alternative | Reason Rejected |
|---|---|
| **Single concrete adapter with internal retry and fallback logic** | Conflates provider integration (adapter concern) with routing policy (orchestration concern). Makes it impossible to add a new provider without modifying the existing adapter. Violates ADR-008 separation principle. |
| **Retry at `OtpService` level (call `deliver()` again from service code)** | Forces `OtpService` to know about provider failure modes and routing policy — a domain service should not make infrastructure decisions. Also risks double-delivery if the idempotency layer is not perfectly coordinated. |
| **Event-driven delivery (publish OTP event; multiple consumers attempt delivery)** | Introduces significant complexity: at-least-once delivery semantics require deduplication at the consumer level; timing guarantees for OTP expiry become difficult. The existing synchronous delivery model is simpler and sufficient for the stated use cases. |
| **Keep single adapter; add routing only when second provider is onboarded** | Defers the architecture decision and creates a retrofit obligation. OKI-ARCH-2026-003 requires provider neutrality from the platform's inception, not only after the second provider arrives. The router seam is low-cost to build now; the alternative is a forced refactor under delivery pressure. |
| **Modify `OtpDeliveryContract` to return a list of attempts** | Changes the interface that `OtpService` depends on, requiring `OtpService` updates. Unnecessary — the router can encapsulate all routing detail and return a single final `OtpDeliveryResult`. |

---

## 5. TBD Items

The following are explicitly NOT resolved by this ADR and MUST NOT be assumed or invented:

| Item | Classification | Notes |
|---|---|---|
| OTP/SMS provider identity (primary, fallback, alternate channel) | D — TBD-001 | Requires explicit client approval; never hard-coded |
| Provider timeout values (connect, read) per provider | D — Pending Decision | Configuration-driven; values require provider SLA input |
| Number of providers in the chain (is the alternate channel mandatory?) | D — Pending Decision | Configuration-driven; default chain length TBD |
| Alternate-channel type for each use case (voice, USSD, email) | D — Pending Decision | Depends on provider capabilities and client approval |
| Provider rate limits and retry policies within a single adapter | D — Pending Decision | Not the router's concern; each adapter defines its own internal retry |
| Whether the router should implement circuit-breaking per provider | D — Pending Decision | `ProviderHealthInterface` (ADR-008 §2.10) provides the seam; strategy TBD |
| Configuration key names and format for `provider_chain` | D — Pending Decision | To be resolved in Platform Foundation extension task |
| Latency budget for the full routing chain (max acceptable delivery time) | D — Pending Decision | Requires product and provider SLA input |
| Whether `OtpDeliveryRouter` lives in Platform Foundation or E01 Infrastructure | D — Pending Decision | Tentatively E01 Infrastructure (because it composes `OtpDeliveryContract` specifically); to be confirmed in Foundation extension task |
| Country/region routing rules (if alternate channel depends on geography) | D — Pending Decision | Not in scope for E01; future routing strategy concern |

---

*This ADR was authored 2026-08-26 in response to gap B identified in the OKI-ARCH-2026-003 impact analysis (`docs/architecture/client-response-impact-analysis-2026-08-25.md` §5, §19).*
