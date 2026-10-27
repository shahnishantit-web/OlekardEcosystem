# Feature Specification: P02 — Payment Schema and API Contracts

**Feature Branch**: `051-payment-schema-api-contracts`

**Created**: 2026-10-21

**Status**: COMPLETE / RELEASE READY

**Work Package**: P02 (per `OleKard_Claude_SpecKit_Managed_Prompts.docx` v1.0)

**Predecessor**: P01 (`specs/050-payment-architecture-contracts/spec.md`) — COMPLETE/RELEASE READY ✅

**Mode**: Implement

**Responsible role**: Backend engineer

**Source references**: Section 6; FD02, FD04, FD08, FD09

> **Source document availability**: `OleKard_Shared_Payment_Architecture.docx v1.1` is NOT in the repository. Source references FD02 (payment data model), FD04 (fee and pricing data), FD08 (API contracts), and FD09 (event schemas) cannot be fully verified. Decisions derived from those sections are recorded as AWAITING_DOCUMENT. P02 proceeds on repository evidence (E48 migrations, E48 domain code, ADR-E48-001/002/003).

**Boundary**: No live charge or broad caller migration. All schema changes MUST be additive. No existing column may be dropped, renamed, or have its constraint tightened.

---

## Overview

P02 extends the Payment module schema and API contracts established in E48 to meet the requirements of the full Shared Payment Architecture. Three gaps remain in the E48 implementation:

1. **State gap**: `payment_intents.status` encodes finance, accounting, and fulfilment states in one column. P08 (ledger acknowledgement) and downstream domain modules cannot independently query their lifecycle dimension without risk of misinterpreting a shared enum.

2. **Structure gap**: The `payment_allocations` table (how a captured amount is divided among org, OleKard, and agents) and the fee snapshot at intent creation time (for auditability and dispute comparison) are absent.

3. **Contract gap**: The existing `openapi/v1/payment.yaml` is a partial draft. Typed `next_action` schemas, complete error code enumeration, pagination, and event schemas are missing — blocking P03–P08 and mobile integration.

P02 delivers:
- Additive migrations to `payment_intents` (accounting_status, fulfilment_status, optimistic-lock version, fee snapshot columns)
- New `payment_allocations` table
- Extended and validated OpenAPI 3.1.0 spec for the payment module

---

## User Scenarios & Testing

### User Story 1 — Separate Finance, Accounting, and Fulfilment States (Priority: P1)

A P08 engineer reads a payment intent and knows unambiguously whether the ledger acknowledgement for this payment has been posted, without needing to parse the combined `status` field or join to the attempts table.

**Why this priority**: Without a separate `accounting_status` column, P08 cannot safely determine whether to post or skip a ledger entry for a given intent. A combined status field makes idempotent ledger posting fragile.

**Independent Test**: Given the additive migration has run, an engineer can insert a row with `status = 'succeeded'`, `accounting_status = 'pending_ledger'`, `fulfilment_status = 'pending'` and query each column independently. A CHECK constraint rejects an invalid `accounting_status` value.

**Acceptance Scenarios**:

1. **Given** a payment intent is captured and the orchestration layer sets `status = 'succeeded'`, **When** P08 queries the intent, **Then** `accounting_status` is `'pending_ledger'` (default) and `fulfilment_status` is `'pending'` (default) — these columns are independent of `status`.
2. **Given** P08 successfully calls `LedgerModuleContract::postEntry()` for an intent, **When** the orchestration layer updates `accounting_status = 'ledger_posted'`, **Then** the intent row has `status = 'succeeded'`, `accounting_status = 'ledger_posted'`, `fulfilment_status = 'pending'` simultaneously — three independent state dimensions.
3. **Given** an engineer attempts to insert `accounting_status = 'invalid_value'`, **Then** the PostgreSQL CHECK constraint rejects the insert with a constraint violation error.
4. **Given** `payment.confirmed` is published (accounting done), **When** the domain module fulfils the obligation and sets `fulfilment_status = 'fulfilled'`, **Then** no change to `status` or `accounting_status` is required — fulfilment is its own state dimension.

---

### User Story 2 — Fee Snapshot and Optimistic Locking (Priority: P1)

A backend engineer can store the fee amount quoted at intent creation time alongside the intent record, and concurrent updates to the same intent use a version counter to detect lost-update races.

**Why this priority**: The fee charged at payment-attempt time (stored on `payment_attempts.fee_minor`) may differ from the fee quoted at intent-creation time if fee rules change between creation and execution. Auditability and dispute resolution require both figures. Optimistic locking prevents silent lost-update bugs in high-concurrency scenarios.

**Independent Test**: Given the additive migration has run, `fee_quote_minor` and `version` columns exist on `payment_intents`. An attempt to update with `WHERE version = 1` succeeds once and fails (0 rows affected) on a second concurrent call with the same stale version.

**Acceptance Scenarios**:

1. **Given** a payment intent is created with fee rules active, **When** the orchestration layer creates the intent, **Then** `fee_quote_minor` stores the fee that would apply at this moment (0 for wallet payments per AD-03; AWAITING_DOCUMENT for bank payments per AD-12).
2. **Given** fee rules change after intent creation, **When** the intent is later executed, **Then** `payment_attempts.fee_minor` stores the actual execution-time fee; `fee_quote_minor` on the intent is immutable from creation.
3. **Given** two concurrent requests attempt to update the same intent with `version = 1`, **When** the first succeeds and increments version to 2, **Then** the second update (also with `version = 1`) affects 0 rows — the application layer detects the race and retries.

---

### User Story 3 — Payment Allocation Table (Priority: P1)

A settlement engineer can query which portion of each captured payment is designated for each recipient (organisation, OleKard, agent) without reading the ledger or wallet tables.

**Why this priority**: Without a structured allocation record at the payment level, P17 (Settlement) and P18 (Payout) must derive allocation amounts from fee rules at settlement time, introducing dependency on mutable rule state. An immutable allocation snapshot per intent enables auditable, reproducible settlement.

**Independent Test**: Given the `payment_allocations` migration has run, an engineer inserts an allocation record with valid fields and verifies the CHECK constraint rejects `amount_minor ≤ 0`. A unique constraint on `(payment_intent_id, allocation_type, recipient_reference)` prevents duplicate allocation entries.

**Acceptance Scenarios**:

1. **Given** a payment intent is created, **When** the orchestration layer snapshots the allocation plan, **Then** one or more `payment_allocations` rows are created (one per recipient type) with `status = 'allocated'` and immutable `amount_minor` per row.
2. **Given** an allocation row exists, **When** an engineer attempts to create a second row with the same `(payment_intent_id, allocation_type, recipient_reference)`, **Then** a unique constraint violation is raised — duplicate allocations are prevented.
3. **Given** a refund is processed, **When** the allocation status is updated to `'refunded'`, **Then** the original allocation rows remain (immutable amount_minor) and a new complementary allocation row is created for the refund amount — no modification of original rows.

---

### User Story 4 — Complete Versioned API and Event Contracts (Priority: P2)

A mobile engineer implementing the payment checkout flow on Flutter can reference `openapi/v1/payment.yaml` and find: fully typed `next_action` schemas with all supported action types, pagination on list endpoints, the complete error code enumeration, and event payload schemas for `payment.confirmed` and `payment.refund.confirmed`.

**Why this priority**: Without a complete, validated OpenAPI spec, mobile engineers must guess at response shapes and error codes, leading to brittle client implementations. The spec must be complete before P03–P08 implement endpoints that mobile consumes.

**Independent Test**: The `openapi/v1/payment.yaml` file validates against an OpenAPI 3.1.0 linter with 0 errors. All `next_action_type` values defined in `PaymentAttemptModel` have a corresponding schema. All error codes used in existing controllers have a corresponding entry in the `ErrorCode` enum in the spec.

**Acceptance Scenarios**:

1. **Given** a bank payment attempt returns `next_action_type = 'redirect'`, **When** a mobile client parses the response, **Then** the OpenAPI schema defines `next_action_data.redirect_url` (string, URI) and `next_action_data.expires_at` (string, datetime) — no guessing required.
2. **Given** a payment intent list endpoint is called with pagination, **When** the response is parsed, **Then** `data`, `meta.current_page`, `meta.per_page`, `meta.total`, and `links.next` are all present and typed in the schema.
3. **Given** an API call fails with an insufficient funds error, **When** the client parses the error, **Then** `error.code = 'INSUFFICIENT_FUNDS'` matches an entry in the OpenAPI `ErrorCode` enum — no undocumented codes appear.
4. **Given** a `payment.confirmed` event is published, **When** an event consumer reads the payload, **Then** it matches the event schema in `openapi/v1/payment-events.yaml` (or equivalent event schema document) — event fields are stable and versioned.

---

### Edge Cases

- What if a migration is run while intents are being written (live data)? All new columns default-valued or nullable — additive migration is non-breaking even under live write load.
- What if `accounting_status` is never updated (e.g., P08 not deployed)? Default `'pending_ledger'` is safe; intent still works as before.
- What if the sum of allocation `amount_minor` rows exceeds the intent `amount_minor`? Service layer MUST enforce this invariant before inserting allocations; the schema does not enforce it with a DB-level constraint (a deferred constraint across rows is impractical in standard PostgreSQL).
- What if `fee_quote_minor` is null when fee rules are AWAITING_DOCUMENT? Null is valid — the column is nullable for bank payment fee quotes pending AD-12 resolution.
- What if `version` overflows? `bigint` (64-bit) supports 9.2 × 10¹⁸ increments — not a practical concern.
- What if the OpenAPI spec includes an undocumented next_action_type added by a future adapter? The spec MUST use an open enum with `x-extensible: true` annotation so clients don't break on unknown types.

---

## Requirements

### Functional Requirements

- **FR-01**: The `payment_intents` table MUST have `accounting_status` (varchar(32), default `'pending_ledger'`, NOT NULL) added via additive migration with a CHECK constraint permitting only `'pending_ledger'`, `'ledger_posted'`, `'ledger_failed'`.
- **FR-02**: The `payment_intents` table MUST have `fulfilment_status` (varchar(32), default `'pending'`, NOT NULL) added via additive migration with a CHECK constraint permitting only `'pending'`, `'fulfilled'`, `'fulfilment_failed'`.
- **FR-03**: The `payment_intents` table MUST have `version` (bigint, default 1, NOT NULL) added via additive migration for optimistic locking.
- **FR-04**: The `payment_intents` table MUST have `fee_quote_minor` (bigint, default 0, NOT NULL, CHECK ≥ 0) and `fee_quote_provider_code` (varchar(64), nullable) added for immutable fee snapshot at intent creation.
- **FR-05**: A new `payment.payment_allocations` table MUST be created with: `id` (UUID v7, PK), `payment_intent_id` (UUID, FK → payment_intents.id), `allocation_type` (varchar(64), NOT NULL), `recipient_type` (varchar(64), NOT NULL), `recipient_reference` (varchar(255), nullable), `amount_minor` (bigint, CHECK > 0), `currency_code` (char(3), NOT NULL), `status` (varchar(32), CHECK IN `'allocated','settled','refunded','cancelled'`), `idempotency_key` (varchar(255), NOT NULL), `created_at`, `updated_at`.
- **FR-06**: `payment_allocations` MUST have: UNIQUE INDEX on `(idempotency_key)`; UNIQUE INDEX on `(payment_intent_id, allocation_type, recipient_reference)` WHERE status NOT IN ('cancelled'); INDEX on `(payment_intent_id, status)`; FK constraint to `payment_intents.id`.
- **FR-07**: All new migrations MUST include a `down()` method that reverses only the new columns/tables — MUST NOT drop tables created by earlier E48 migrations.
- **FR-08**: The `openapi/v1/payment.yaml` MUST be extended with: typed `next_action` discriminated union (redirect, otp_verification, polling_required, wallet_step_up); `PaginatedMeta` and `PaginatedLinks` components added and applied to the two existing list endpoint 200-response schemas (`GET /admin/payment/providers` and `GET /admin/payment/reconciliation/exceptions` — both currently return bare `data: array` with no meta/links); complete `ErrorCode` enum covering all error codes returned by existing controllers; security scope annotations for each endpoint.
- **FR-09**: An event schema document (`openapi/v1/payment-events.yaml` or equivalent) MUST be created defining the payload of: `payment.confirmed`, `payment.failed`, `payment.refund.confirmed`, `payment.reversed` — each with version, event_id, occurred_at, correlation_id, and typed data object.
- **FR-10**: The `PaymentIntentModel` Eloquent model MUST have `accounting_status`, `fulfilment_status`, `version`, `fee_quote_minor`, and `fee_quote_provider_code` added to the `$fillable` array and `$casts` where applicable. `PaymentAttemptModel` is not modified in P02 — all new columns reside on `payment_intents` only.

### BLOCKED / AWAITING_DOCUMENT Requirements

The following requirements depend on `OleKard_Shared_Payment_Architecture.docx v1.1` (DEC-07 from P00) and MUST be marked BLOCKED until the document is available:

- **FR-B01 [AWAITING_DOCUMENT: FD02]**: Allocation type enum values (e.g., `'org_disbursement'`, `'olekard_fee'`, `'agent_commission'`) — interim: use open varchar with documented examples; no DB-level constraint until FD02 confirmed.
- **FR-B02 [AWAITING_DOCUMENT: FD04]**: Fee snapshot calculation rules for bank payments (`fee_quote_minor` for non-wallet providers) — interim: `fee_quote_minor = 0` for wallet; bank payment fee quote set to NULL until AD-12 resolved.
- **FR-B03 [AWAITING_DOCUMENT: FD08]**: Complete API contract specification for P03–P11 endpoints not yet implemented (bank callback v2, settlement status endpoint, payout initiation) — interim: only E48-existing endpoints are specced in FR-08.
- **FR-B04 [AWAITING_DOCUMENT: FD09]**: Event schema version negotiation strategy and event bus configuration — interim: event schemas are static documents without runtime negotiation.

### Key Entities

- **PaymentIntent** (extended): Adds `accounting_status`, `fulfilment_status`, `version`, `fee_quote_minor`, `fee_quote_provider_code` to the existing record. Three independent lifecycle dimensions.
- **PaymentAllocation**: A single recipient share of a captured payment. Immutable once created except for status. Sum of allocations for an intent MUST NOT exceed intent.amount_minor (service-layer invariant).
- **NextAction**: A typed discriminated union describing what the client must do after a payment attempt is initiated. Types: `redirect` (URL + expiry), `otp_verification` (masked destination), `polling_required` (interval + max attempts), `wallet_step_up` (step-up auth trigger).
- **PaymentEvent**: A versioned event payload published after a durable payment state change. Contains: event_id (UUID v7), event_type, version (schema version), occurred_at (UTC), correlation_id, data (typed per event_type).

---

## Success Criteria

### Measurable Outcomes

- **SC-01**: All new migrations run and roll back cleanly on a test PostgreSQL database with existing E48 data — verified by running `migrate:fresh` (test only), confirming `payment.payment_allocations` exists and `payment_intents` has all 5 new columns.
- **SC-02**: Schema validation rejects invalid values for `accounting_status`, `fulfilment_status`, and `amount_minor ≤ 0` — verified by unit tests that attempt invalid inserts and assert constraint violations.
- **SC-03**: Optimistic locking works under concurrent test simulation — verified by a concurrency test that fires two simultaneous update queries with identical `version = 1` and confirms only one succeeds.
- **SC-04**: The `payment.yaml` OpenAPI file validates with 0 linter errors against OpenAPI 3.1.0 — verified by running `npx @redocly/cli lint` (or equivalent) on the file.
- **SC-05**: All four `next_action_type` values (redirect, otp_verification, polling_required, wallet_step_up) have typed schemas in the OpenAPI spec — verified by reading the spec and confirming discriminated union entries.
- **SC-06**: The event schema document exists and defines payload shapes for `payment.confirmed` and `payment.refund.confirmed` — verified by reading the document.
- **SC-07**: Zero existing E48 PHPStan baseline errors introduced — verified by running PHPStan at level 8 after model updates.

---

## Assumptions

- The E48 migrations have already run in any environment where P02 migrations will be applied. P02 migrations are numbered higher (e.g., `2026_10_21_*`) and will not conflict with the E48 `2026_10_18_*` migrations.
- `accounting_status` and `fulfilment_status` defaults (`'pending_ledger'` and `'pending'` respectively) are correct for all existing rows — all historical intents are pre-P08 and have not had ledger entries posted yet.
- The `payment_allocations` table does not have a DB-level SUM constraint (total allocations ≤ intent.amount_minor). This invariant is enforced by `PaymentOrchestrationService` before inserting allocations.
- Allocation `recipient_reference` is nullable — some allocations (e.g., OleKard fee share) may not have an external reference ID at creation time.
- The `fee_quote_provider_code` matches the provider_code on the subsequent `payment_attempts` record. If the user changes provider between intent creation and attempt, the fee_quote may not match the attempt fee — this mismatch is recorded for audit only, not an error.
- OpenAPI spec extensions to `payment.yaml` are additive to the existing E48 draft. No existing paths or schemas are removed or made incompatible.
- Event schemas use synchronous in-process events (Laravel events) as the delivery mechanism; outbox/broker integration is P09 scope.
- `OleKard_Shared_Payment_Architecture.docx v1.1` is NOT available. FR-B01 through FR-B04 are BLOCKED pending DEC-07 resolution.
