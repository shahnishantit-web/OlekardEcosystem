# Feature Specification: P04 — Purpose Validation, Quotes and Fee Snapshots

**Feature Branch**: `053-purpose-validation-fee-snapshots`

**Created**: 2026-10-21

**Status**: Draft

**Work Package**: P04 (per `OleKard_Claude_SpecKit_Managed_Prompts.docx` v1.0)

**Predecessor**: P02 (`specs/051-payment-schema-api-contracts/spec.md`) — COMPLETE/RELEASE READY ✅  
**Predecessor**: P03 (`specs/052-tenant-payment-permissions/spec.md`) — COMPLETE/RELEASE READY ✅

**Mode**: Implement

**Responsible role**: Backend and domain engineers

**Source references**: FD02; LC02; sections 6 and 8

> **Source document availability**: `OleKard_Shared_Payment_Architecture.docx v1.1` is NOT in the repository. FD02 (payment data model), LC02 (lifecycle document), and sections 6/8 cannot be fully verified. Decisions derived from those sections are recorded as AWAITING_DOCUMENT. P04 proceeds on repository evidence (E48 `BusinessPurposeResolverInterface`, `PayableObligation`, `PaymentOrchestrationService`, E21 fee rules, P01 architecture contracts, P02 schema contracts, and P03 permission seeding).

**Boundary**: No split tender, FX, recurring mandates, or domain-specific pricing inside Payments. One currency and one payment option per intent in this package. No wallet balance holds or ledger postings (those belong to P05/P06). Fee rounding is within Payments; fee rule authoring belongs to E21.

---

## Overview

E48 established that payment amounts are always resolved server-side from a registered `BusinessPurposeResolverInterface`; clients may never supply amounts. P04 closes four remaining gaps in that model:

1. **Obligation depth gap**: The existing `PayableObligation` records amount, currency, organisation, and payer, but does not record: expiry timestamp, resource hold reference, or beneficiary allocations. Downstream modules (P05 hold, P07 ledger) need these fields from a single authoritative record.

2. **Quote gap**: No immutable quote entity exists. Without one, the consumer cannot see the total (base + fee) before confirming, and the system has no record of what the consumer agreed to pay. Quote immutability also prevents post-facto fee disputes.

3. **Fee snapshot gap**: Fees are computed at execution time from live fee rules (E21). If rules change between intent creation and execution, the consumer may pay a different amount than shown. A snapshot binds the consumer to the fee rule in effect when they saw and confirmed the total.

4. **Stale-quote gap**: Intents can outlast their purpose expiry. Without a freshness check before execution, a payment could proceed against a purpose that has since been fulfilled, cancelled, or expired by another session.

---

## User Scenarios & Testing

### User Story 1 — Purpose Payable Validation and Enriched Obligation (Priority: P1)

A consumer initiates a payment for a business purpose (e.g., a funeral contribution, a savings top-up). The system resolves the obligation server-side and validates that the purpose exists, belongs to the correct actor, is in a payable state, and has not expired. The validated obligation — including expiry timestamp, any resource hold reference, and beneficiary allocation summary — is stored with the intent for use by downstream modules.

**Why this priority**: All other P04 stories depend on the enriched obligation being available on the intent record. Without it, quote creation (US2) and stale-quote revalidation (US3) have no authoritative source of truth.

**Independent Test**: Create an intent for a valid purpose → obligation is stored on the intent record with correct amount, currency, payer, organisation, expiry, and at least a stub beneficiary entry. Attempt to create an intent for an expired purpose → `PurposeNotPayableException` is raised and no intent is created. Attempt to create an intent with a purpose owned by a different actor → `PurposeNotPayableException` or `AccessDeniedException`.

**Acceptance Scenarios**:

1. **Given** a registered purpose resolver for `funeral_contribution`, **When** a consumer creates a payment intent with a valid, payable funeral contribution reference, **Then** the stored intent contains: base amount (not from client), currency, payer actor, organisation, expiry timestamp, and at least a placeholder for beneficiary allocations.

2. **Given** a funeral contribution that has already been paid, **When** a consumer attempts to create a payment intent for it, **Then** the system returns an error with code `PURPOSE_NOT_PAYABLE` and no intent record is created.

3. **Given** a funeral contribution owned by actor A, **When** actor B (not the registered payer) attempts to create an intent, **Then** the system returns an error and no intent record is created.

4. **Given** a purpose with an expiry timestamp in the past, **When** any actor attempts to create an intent, **Then** the system returns `PURPOSE_NOT_PAYABLE` and records the rejection reason as `expired`.

---

### User Story 2 — Immutable Payment Quote with Fee Snapshot (Priority: P1)

When a consumer requests a payment intent, the system creates an immutable, version-stamped quote that records the base amount, the applicable fee (computed from the fee rules in effect at that moment), the total the consumer will be charged, and all inputs used to compute the fee. The consumer can retrieve this quote at any time before confirming. Once the consumer confirms by initiating an attempt, the quote is consumed and the agreed total is locked.

**Why this priority**: The quote is the consumer's informed-consent record. Without it, the system cannot prove what amount the consumer agreed to pay, cannot defend against fee disputes, and cannot correctly charge on execution (US3 depends on the stored total).

**Independent Test**: Create an intent → retrieve the quote (`GET /payment/intents/{id}/quote`) → verify that `total_amount_minor = base_amount_minor + fee_amount_minor`, that the fee snapshot contains the fee rule version, effective date, and rounding mode. Modify the live fee rule → verify the quote still shows the original fee (snapshot is immutable).

**Acceptance Scenarios**:

1. **Given** a valid intent is created, **When** the consumer retrieves the quote, **Then** the response contains: `base_amount_minor`, `fee_amount_minor`, `total_amount_minor`, `quote_version`, `expires_at`, and a `fee_snapshot` object with `rule_id`, `effective_date`, `rate_or_flat_amount`, and `rounding_mode`.

2. **Given** a quote exists for an intent, **When** the fee rules are updated by an admin, **Then** the existing quote is unaffected — it retains the original fee snapshot.

3. **Given** a quote with `total_amount_minor = 10500`, **When** the consumer initiates a payment attempt referencing this quote, **Then** the attempt is created using `10500` as the authoritative charge amount.

4. **Given** fee computation involves sub-minor-unit fractions, **When** the fee is rounded, **Then** rounding follows the currency-configured mode (floor or round-half-up) and the applied mode is recorded in the fee snapshot.

---

### User Story 3 — Stale Quote Revalidation Before Execution (Priority: P2)

Before the system executes a payment attempt, it checks whether the active quote is still within its configured validity window (`payment.quote_ttl_seconds`). If the quote has expired, the system re-resolves the obligation from the originating domain. If the new total matches the stored total, the quote is silently refreshed and execution continues. If the new total differs (e.g., fees changed, purpose amount was adjusted), execution is blocked and the consumer receives a `QUOTE_STALE_CONFIRM_REQUIRED` error with the updated quote, requiring them to explicitly confirm the new amount.

**Why this priority**: Without this guard, a consumer could confirm a BWP 100 amount and hours later execute a payment that has since become BWP 105 (due to a fee rule change) without their knowledge. This is a financial integrity requirement.

**Independent Test**: Create an intent and quote → wait (or simulate) past the TTL → attempt to initiate a payment → verify the system re-resolves → if purpose unchanged, attempt proceeds; if purpose amount changed, system returns `QUOTE_STALE_CONFIRM_REQUIRED` with the new quote details.

**Acceptance Scenarios**:

1. **Given** an active quote whose age exceeds `payment.quote_ttl_seconds`, **When** the consumer initiates a payment attempt, **Then** the system re-resolves the obligation before proceeding.

2. **Given** the re-resolved obligation has the same total as the stored quote, **When** validation completes, **Then** the quote is refreshed silently (new `expires_at`, same version) and the attempt proceeds.

3. **Given** the re-resolved obligation has a higher total than the stored quote, **When** validation completes, **Then** execution is blocked, the old quote is marked `superseded`, a new quote is created, and the consumer receives `QUOTE_STALE_CONFIRM_REQUIRED` with the new quote.

4. **Given** the re-resolved obligation finds the purpose is no longer payable (cancelled, expired, fulfilled), **When** validation completes, **Then** the intent is cancelled with reason `PURPOSE_NO_LONGER_PAYABLE` and no charge is made.

---

### User Story 4 — Quote Retrieval API (Priority: P2)

Consumers and admin staff can retrieve the current quote for a payment intent through a dedicated endpoint. The quote response includes the full fee breakdown so consumers can see exactly what they will be charged before confirming. Admin users with the appropriate permission can view quotes for any intent in their scope.

**Why this priority**: Without a quote API, the consumer UI has no standardised way to display the breakdown before confirmation, leading to surprise charges and support requests.

**Independent Test**: Create an intent → call `GET /payment/intents/{id}/quote` as the payer → receive 200 with complete fee breakdown. Call the same endpoint as a different actor (not the payer and not an admin) → receive 403. Call after the intent is consumed/cancelled → receive the quote with its terminal status.

**Acceptance Scenarios**:

1. **Given** an active payment intent, **When** the payer calls `GET /payment/intents/{id}/quote`, **Then** the response includes `base_amount_minor`, `fee_amount_minor`, `total_amount_minor`, `fee_snapshot`, `quote_version`, and `expires_at`.

2. **Given** an active payment intent, **When** an actor without `payment.intents.read` permission calls the quote endpoint, **Then** the system returns 403.

3. **Given** an intent that has been cancelled, **When** the payer retrieves the quote, **Then** the quote is returned with status `cancelled` or `superseded` (not deleted — audit history is preserved).

---

### User Story 5 — Purpose Handler Registration Pattern and Dev Stub (Priority: P3)

Domain modules (Funeral, Savings, Community Wallet, etc.) register their purpose resolver implementations into the Payments module via the `BusinessPurposeResolverRegistry`. The registration pattern is documented in a contract guide so future domain engineers can add new purpose types without modifying Payment internals. A development-only stub resolver is provided so local development and tests can exercise the full quote/validation flow without a live domain module.

**Why this priority**: Without a clear documented pattern and a usable dev stub, each new domain integration risks diverging from the contract, leading to maintenance debt and potential financial logic errors.

**Independent Test**: Register the dev stub resolver for `dev_test_purpose` in a non-production environment → create an intent for that purpose type → verify the full quote creation flow completes correctly. Attempt to register or invoke the dev stub in a production environment → it must be unavailable (configuration guard).

**Acceptance Scenarios**:

1. **Given** the dev stub resolver is registered for `dev_test_purpose`, **When** an intent is created with `purpose_type = dev_test_purpose` in a non-production environment, **Then** the full obligation resolve → quote create → stale-revalidation flow executes correctly.

2. **Given** a production environment, **When** any attempt is made to use the dev stub resolver, **Then** it either throws `ProductionBlockedException` or is not registered.

3. **Given** a domain engineer follows the registration pattern guide, **When** they add a new purpose resolver for a new domain, **Then** they do not need to modify any Payment module source file — only register their resolver in their own ServiceProvider.

---

### Edge Cases

- What happens if two concurrent sessions attempt to pay the same purpose reference simultaneously? (Concurrent resolution — one should succeed, the other should fail with `PURPOSE_NOT_PAYABLE`; concurrency test required.)
- What happens if the fee rule snapshot references a rule that is later deleted from E21? (Snapshot is self-contained — no FK dependency on live fee rule records.)
- What happens if `payment.quote_ttl_seconds` is set to 0? (Treat as "always revalidate" — every attempt triggers re-resolution before execution.)
- What happens if the purpose resolver raises an unhandled exception during revalidation? (Log + audit the failure, surface as `OBLIGATION_RESOLUTION_FAILED`, block execution, do not proceed with stale data.)
- What if fee computation results in 0 (free payment)? (Valid — fee snapshot records `fee_amount_minor = 0`, `rate = 0`. Quote is still created and stored.)
- What if the beneficiary allocation list from the resolver is empty? (Accept for P04 — beneficiary list is AWAITING_DOCUMENT:FD02. Store empty array. Block only if resolver explicitly rejects.)

---

## Requirements

### Functional Requirements

- **FR-01**: The system MUST resolve payment obligations server-side via the registered `BusinessPurposeResolverInterface`; client-supplied amount fields in payment creation requests MUST be silently ignored and never used as financial truth.
- **FR-02**: Each resolved obligation MUST include: `amount_minor`, `currency_code`, `organisation_id`, `payer_actor_id`, `expires_at` (nullable — null means no expiry), `hold_reference` (nullable — AWAITING_DOCUMENT:LC02), and `beneficiary_allocations` (array — AWAITING_DOCUMENT:FD02, may be empty).
- **FR-03**: The system MUST validate purpose ownership: if the resolved `payer_actor_id` does not match the authenticated actor, the intent MUST be rejected.
- **FR-04**: The system MUST validate purpose expiry: if the resolved `expires_at` is in the past, the intent MUST be rejected with `PURPOSE_NOT_PAYABLE`.
- **FR-05**: On intent creation, the system MUST create an immutable, version-stamped quote record containing: `base_amount_minor`, `currency_code`, `selected_provider_option` (from options discovery), `fee_amount_minor`, `rounding_mode`, `fee_rule_snapshot` (JSONB — self-contained copy of rule inputs), `total_amount_minor`, `quote_version` (starts at 1), and `expires_at` (= `now() + payment.quote_ttl_seconds`).
- **FR-06**: `total_amount_minor` MUST equal `base_amount_minor + fee_amount_minor`. Any arithmetic discrepancy MUST raise an invariant error before persistence.
- **FR-07**: Fee rounding MUST follow the currency-configured rounding mode stored in the fee snapshot. The computed fee MUST be a whole number of minor units (no sub-minor fractions persisted).
- **FR-08**: Before executing a payment attempt, the system MUST check the quote's `expires_at`. If the quote is expired, the obligation MUST be re-resolved.
- **FR-09**: If re-resolution yields the same `total_amount_minor` as the stored quote, the system MUST silently refresh the quote's `expires_at` (increment version) and allow execution.
- **FR-10**: If re-resolution yields a different `total_amount_minor`, the system MUST: mark the existing quote as `superseded`, create a new quote at the new total, and return `QUOTE_STALE_CONFIRM_REQUIRED` (HTTP 409 / domain error) — execution is BLOCKED until the consumer acknowledges the new quote.
- **FR-11**: If re-resolution raises `PurposeNotPayableException`, the system MUST cancel the intent with reason `PURPOSE_NO_LONGER_PAYABLE` — no charge or hold is initiated.
- **FR-12**: Consumers MUST be able to retrieve the active quote for their intent via `GET /payment/intents/{id}/quote`. The quote MUST be accessible to the payer actor and to admin actors with `payment.intents.read`.
- **FR-13**: The `payment.quote_ttl_seconds` configuration value MUST be centrally configurable and MUST NOT be hard-coded. A value of `0` means always revalidate.
- **FR-14**: A development-only stub resolver labeled `DevPurposeStubResolver` MUST be provided; it MUST only be registered and callable in non-production environments. Attempts to use it in production MUST throw `ProductionBlockedException`.
- **FR-15**: All quote creation, revalidation, supersession, and cancellation events MUST be audited with actor, timestamp, action, resource ID, and outcome.
- **FR-16**: Concurrent identical-purpose payment attempts MUST be safe: one succeeds, the other receives `PURPOSE_NOT_PAYABLE`. Idempotency key guards existing intents.
- **FR-B01**: Exact beneficiary allocation structure (AWAITING_DOCUMENT: FD02) — system stores `beneficiary_allocations` as JSONB array, content schema TBD.
- **FR-B02**: Resource hold reference lifecycle (AWAITING_DOCUMENT: LC02) — `hold_reference` is stored on the obligation but its activation protocol belongs to P05/P06.

### Key Entities

- **EnrichedPayableObligation**: Extended form of `PayableObligation` adding `expiresAt`, `holdReference`, and `beneficiaryAllocations[]`. Created by the domain resolver, stored on the intent as an obligation snapshot (JSONB). Never mutable after storage.
- **PaymentQuote**: Immutable, versioned record per intent per revalidation cycle. Contains: `id`, `intent_id`, `version`, `base_amount_minor`, `fee_amount_minor`, `total_amount_minor`, `currency_code`, `rounding_mode`, `fee_rule_snapshot` (JSONB), `obligation_snapshot` (JSONB), `quote_status` (active/expired/superseded/consumed/cancelled), `provider_code`, `expires_at`, `timestamps`.
- **FeeRuleSnapshot**: Embedded JSONB within `PaymentQuote.fee_rule_snapshot`. Self-contained copy of: `rule_id`, `rule_name`, `effective_date`, `rate_basis_points` (or `flat_amount_minor`), `minimum_fee_minor`, `maximum_fee_minor`, `rounding_mode`, `computed_fee_minor`, `computation_inputs`.
- **DevPurposeStubResolver**: Non-production implementation of `BusinessPurposeResolverInterface`. Returns a configurable obligation for `dev_test_purpose`. Blocked in production.

---

## Success Criteria

### Measurable Outcomes

- **SC-01**: Any payment attempt with a client-supplied amount is rejected — zero incidents of client-determined charges can reach execution.
- **SC-02**: Retrieving a quote for a fresh intent returns a correct total (base + fee) matching the fee rule in effect at intent creation time, with deterministic rounding.
- **SC-03**: A stale quote that has expired and whose re-resolved total differs is detected before execution and blocks the attempt — zero incidents of silent overcharge.
- **SC-04**: Purpose ownership validation prevents any actor from paying a purpose they do not own — zero cross-actor payments succeed.
- **SC-05**: Fee rounding is deterministic: identical inputs always produce identical output regardless of system state, concurrency, or call order.
- **SC-06**: Concurrent payment attempts against the same purpose reference result in at most one success — the second attempt is rejected with `PURPOSE_NOT_PAYABLE`.
- **SC-07**: PHPStan level 8 analysis on new files produces zero errors.
- **SC-08**: All new domain events emitted by P04 are audited in the audit log with correct actor, action, and resource context.

---

## Assumptions

- **A-01**: E21 fee rules are accessible via a `FeeRuleService` or `FeePricingService` that accepts a provider code, amount, and currency and returns computed fee details. If no such service exists, P04 will implement a minimal fee computation facade that reads from `platform.fee_rules` directly (same approach as E21).
- **A-02**: `payment.quote_ttl_seconds` default is 300 seconds (5 minutes) — chosen to balance mobile UX (sessions may be interrupted) against the risk of a consumer confirming a stale fee.
- **A-03**: Quotes are per-provider-option. When the consumer selects a provider (wallet vs bank), the fee snapshot reflects that provider's fee rules. If the consumer changes provider selection, a new quote version is created.
- **A-04**: `beneficiary_allocations` in the enriched obligation is an opaque JSONB array for P04 — its content schema is AWAITING_DOCUMENT:FD02. P04 stores whatever the resolver returns; P05+ will interpret it.
- **A-05**: `hold_reference` in the enriched obligation is nullable for P04 — its activation protocol is deferred to P05 (wallet hold) and P06 (bank hold). P04 only stores the reference.
- **A-06**: For P04, one currency per intent and one payment option per quote are enforced. Multi-currency (P10/E29) and multi-option selection are out of scope.
- **A-07**: Purpose resolvers are synchronous for P04. Async purpose resolution (webhooks, event-driven) is deferred to a later package.
- **A-08**: The `DevPurposeStubResolver` returns a fixed obligation: BWP 100.00 (10000 minor units), no expiry, empty beneficiary list, null hold reference. It is registered for purpose type `dev_test_purpose` and blocked in production.
- **A-09**: The existing `BusinessPurposeResolverInterface::resolve()` signature is extended to return `EnrichedPayableObligation` (superset of `PayableObligation`). Backward compatibility is maintained — existing resolvers returning `PayableObligation` will cause a type error at PHPStan level 8; a migration guide is provided.
