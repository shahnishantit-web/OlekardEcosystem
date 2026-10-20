# Feature Specification: E21 — Fees & Pricing

**Feature Branch**: `021-fees-pricing`

**Created**: 2026-09-28

**Status**: Draft

## Overview

The Fees & Pricing module provides the central, admin-configurable fee infrastructure for the OleKard wallet ecosystem. It enables operations administrators to define fee rules for every transaction type (transfer, funding, withdrawal, bill payment, QR payment, cash-in/cash-out, settlement), supporting both fixed-amount and percentage-based fees with optional floor/ceiling bounds and subject-level overrides (per-customer or per-agent). Customers see their exact fee before confirming any transaction. When a transaction completes, the platform deducts the fee from the customer's wallet and posts a balanced double-entry journal entry via the E07 Ledger module.

This module directly fulfils Constitution Principle 3 — fees, commissions, and settlement rules must be centrally configurable from the Administration surface, not hard-coded in PHP config. It also migrates E20's `default_fee_rate` PHP constant to a proper database-backed fee rule.

## Actors

- **Operations Admin** — Creates, updates, and deactivates fee rules for all transaction types; manages pricing versions; views fee audit history
- **Customer** — Initiates transactions; receives a fee preview before confirming; has the applicable fee deducted from their wallet on execution
- **Agent** — Facilitates cash-in and cash-out transactions; may be subject to agent-specific fee rules distinct from customer fees (note: agent commissions *earned* are managed by E14, not E21)
- **Platform (System)** — Resolves the applicable fee rule for each transaction, calculates the fee amount deterministically, and records the fee in the double-entry ledger when the transaction completes

## User Scenarios & Testing

### User Story 1 — Configure Transaction Fee Rule (US-FEE-001, Priority: P0)

An operations admin defines a fee rule for a given transaction type. The rule specifies whether the fee is calculated as a percentage of the transaction amount or as a fixed flat amount, the currency, the effective date range, and optionally the scope (global, per-customer, or per-agent). Only one active global rule may exist per (transaction_type, currency) combination. Rules are patchable: `PATCH /v1/fees/rules/{id}` updates `valid_to`, `min_fee_minor`, or `max_fee_minor` on the existing active rule in-place. Full rule versioning (creating a new active version and transitioning the previous to `superseded`) is a post-MVP enhancement tracked as a deferred task.

**Why this priority**: The `fee_rules` table is the prerequisite for all other stories — no fee can be calculated, previewed, or recorded without at least one active rule.

**Independent Test**: Admin creates a global percentage fee rule (rate: 1.0%) for transfer transactions; the rule is returned with a UUID v7 ID and `status = active`; a second global active transfer rule in the same currency is rejected with a 409 Conflict.

**Acceptance Scenarios**:
1. **Given** no active global transfer fee rule exists, **When** an admin POSTs a 1.0% percentage rule for `transaction_type = transfer` with `valid_from = now`, **Then** the rule is saved with `status = active`, a `FeeRuleCreated` audit event is emitted, and the rule ID is returned.
2. **Given** an active rule, **When** an admin PATCHes the rule to set `valid_to` to a past timestamp, **Then** the rule transitions to `expired`; a `FeeRuleDeactivated` audit event is emitted.
3. **Given** an active global transfer fee rule in BWP already exists, **When** an admin attempts to create a second active global transfer rule in BWP, **Then** a 409 Conflict is returned with error code `FEE_RULE_CONFLICT`.
4. **Given** an unauthorized actor (non-admin), **When** they attempt to create or modify a fee rule, **Then** a 403 Forbidden is returned.
5. **Given** a fee rule with `fee_type = percentage` and `rate <= 0` or `rate > 100`, **When** submitted, **Then** validation rejects with `INVALID_FEE_RATE`.
6. **Given** E20's reconciliation module uses `config('reconciliation.default_fee_rate')` (a PHP config constant), **When** E21 is deployed and a fee rule with `transaction_type = settlement` is created, **Then** the reconciliation settlement fee is sourced from the `fee_rules` table; the PHP config constant is deprecated and replaced by the DB lookup.

**API**: `POST /v1/fees/rules`, `GET /v1/fees/rules`, `GET /v1/fees/rules/{id}`, `PATCH /v1/fees/rules/{id}`, `DELETE /v1/fees/rules/{id}`

**DB Entities**: FeeRule, PricingVersion

---

### User Story 2 — Configure Percentage Fee (US-FEE-002, Priority: P0)

An operations admin creates a fee rule where the fee amount is computed as a percentage of the transaction amount. The rate is a positive decimal with up to four decimal places (e.g., 0.5000%). The resulting fee respects any configured min/max bounds and is rounded using half-up at the minor-unit level. Percentage-fee calculation must be deterministic: given identical inputs, the computed fee is always the same.

**Why this priority**: Percentage fees are the primary commercial model for transfers and withdrawals; without percentage fee rules the fee engine cannot serve the majority of transaction types.

**Independent Test**: Admin creates a 0.5% withdrawal fee rule; a BWP 1,000 withdrawal produces a fee of exactly BWP 5.00 (500 minor units); a BWP 500 withdrawal produces exactly BWP 2.50 (250 minor units).

**Acceptance Scenarios**:
1. **Given** an active 0.5% withdrawal fee rule, **When** a BWP 1,000 withdrawal triggers fee calculation, **Then** the fee is 500 minor units (BWP 5.00).
2. **Given** an active 0.5% withdrawal fee rule, **When** a BWP 7 withdrawal triggers fee calculation (raw result: 0.035 BWP), **Then** the fee rounds to 4 minor units (BWP 0.04) using half-up rounding.
3. **Given** a percentage fee rule submission with `rate = 0`, **When** submitted, **Then** validation rejects with `INVALID_FEE_RATE` (zero-rate not permitted; deactivate the rule to remove a fee).
4. **Given** a percentage fee rule submission with `rate = 100.0001`, **When** submitted, **Then** validation rejects with `INVALID_FEE_RATE` (rate must be <= 100).

**API**: `POST /v1/fees/rules` with `fee_type = percentage`.

**DB Entities**: FeeRule

---

### User Story 3 — Configure Fixed Fee (US-FEE-003, Priority: P0)

An operations admin creates a fee rule where the fee amount is a fixed monetary value regardless of transaction size. Fixed fees are denominated in the platform currency (BWP), stored as integer minor units, and applied uniformly to all transactions matching the rule's scope and `transaction_type`. Fixed fees do not use percentage rounding and always produce an exact amount.

**Why this priority**: Fixed fees are the commercial model for bill payments and QR payments where a flat per-transaction charge is required.

**Independent Test**: Admin creates a BWP 1.50 (150 minor units) fixed fee rule for `bill_payment` transactions; any bill payment — regardless of amount — produces a fee of exactly 150 minor units.

**Acceptance Scenarios**:
1. **Given** a BWP 1.50 fixed fee rule for `bill_payment`, **When** a BWP 200 bill payment triggers fee calculation, **Then** the fee is exactly 150 minor units.
2. **Given** a BWP 1.50 fixed fee rule for `bill_payment`, **When** a BWP 5,000 bill payment triggers fee calculation, **Then** the fee is still exactly 150 minor units.
3. **Given** a fixed fee rule submission with `amount_minor = 0`, **When** submitted, **Then** validation rejects with `INVALID_FEE_AMOUNT`.
4. **Given** a fixed fee rule submission with `amount_minor < 0`, **When** submitted, **Then** validation rejects with `INVALID_FEE_AMOUNT`.

**API**: `POST /v1/fees/rules` with `fee_type = fixed`.

**DB Entities**: FeeRule

---

### User Story 4 — Configure Minimum Fee Bound (US-FEE-004, Priority: P1)

An operations admin sets a minimum fee floor on a percentage-based fee rule, ensuring that even very small transactions incur at least a minimum charge. If the calculated percentage fee falls below `min_fee_minor`, the minimum fee is used instead. Minimum bounds apply only to percentage-based rules; fixed-fee rules already have a deterministic amount and cannot have a minimum bound.

**Why this priority**: Required before go-live to ensure micro-transactions cover platform costs under percentage-fee rules.

**Independent Test**: A 0.5% transfer rule has `min_fee_minor = 100` (BWP 1.00); a BWP 100 transfer (calculated fee: 50 minor units) is floored to 100 minor units (BWP 1.00).

**Acceptance Scenarios**:
1. **Given** a 0.5% transfer rule with `min_fee_minor = 100`, **When** a BWP 100 transfer triggers calculation, **Then** the fee is 100 minor units (not 50).
2. **Given** a 0.5% transfer rule with `min_fee_minor = 100`, **When** a BWP 2,000 transfer triggers calculation (fee = 1,000 minor units), **Then** the fee is 1,000 minor units (minimum does not constrain large transactions).
3. **Given** an existing percentage rule, **When** an admin PATCHes `min_fee_minor = 50`, **Then** subsequent calculations respect the new floor; a `FeeRuleUpdated` audit event is emitted.
4. **Given** a fixed-fee rule, **When** an admin attempts to set `min_fee_minor`, **Then** a 422 Unprocessable Entity is returned with `MIN_FEE_NOT_APPLICABLE_TO_FIXED_RULES`.
5. **Given** a submission where `min_fee_minor > max_fee_minor` on the same rule, **When** submitted, **Then** validation rejects with `FEE_BOUNDS_CONFLICT`.

**API**: `PATCH /v1/fees/rules/{id}` with `min_fee_minor` field.

**DB Entities**: FeeRule

---

### User Story 5 — Configure Maximum Fee Bound (US-FEE-005, Priority: P1)

An operations admin sets a maximum fee ceiling on a percentage-based fee rule, protecting customers from disproportionate fees on large transactions. If the calculated percentage fee exceeds `max_fee_minor`, the ceiling value is charged instead. Maximum bounds apply only to percentage-based rules.

**Why this priority**: Required before go-live to maintain customer trust on large-amount transactions.

**Independent Test**: A 0.5% transfer rule has `max_fee_minor = 5000` (BWP 50.00); a BWP 50,000 transfer (calculated fee: 25,000 minor units) is capped at 5,000 minor units (BWP 50.00).

**Acceptance Scenarios**:
1. **Given** a 0.5% transfer rule with `max_fee_minor = 5000`, **When** a BWP 50,000 transfer triggers calculation, **Then** the fee is 5,000 minor units (not 25,000).
2. **Given** a 0.5% transfer rule with `max_fee_minor = 5000`, **When** a BWP 500 transfer triggers calculation (fee = 25 minor units), **Then** the fee is 25 minor units (ceiling has no effect).
3. **Given** a submission where `max_fee_minor < min_fee_minor`, **When** submitted, **Then** validation rejects with `FEE_BOUNDS_CONFLICT`.
4. **Given** a fixed-fee rule, **When** an admin attempts to set `max_fee_minor`, **Then** a 422 Unprocessable Entity is returned with `MAX_FEE_NOT_APPLICABLE_TO_FIXED_RULES`.

**API**: `PATCH /v1/fees/rules/{id}` with `max_fee_minor` field.

**DB Entities**: FeeRule

---

### User Story 6 — Configure Customer-Specific Fee Override (US-FEE-006, Priority: P2)

An operations admin configures a fee override for a specific customer. The customer-specific rule, when active, takes precedence over the global fee rule for the same transaction type and currency. This supports VIP pricing, reduced-rate scenarios, and negotiated rates. At most one active per-customer rule may exist for any given (customer_id, transaction_type, currency) combination.

**Why this priority**: Commercial flexibility feature not required for MVP; deferred until after core fee infrastructure is live.

**Independent Test**: Admin creates a customer-specific 0.1% transfer override for customer A; customer A's transfer fee is 0.1% while all other customers pay the global 0.5%.

**Acceptance Scenarios**:
1. **Given** a global 0.5% transfer rule and a per-customer 0.1% transfer rule for customer A, **When** customer A initiates a BWP 1,000 transfer, **Then** the fee is 10 minor units (BWP 0.10), not 50 minor units.
2. **Given** a per-customer rule for customer A, **When** customer B initiates a transfer, **Then** the global 0.5% rule applies; customer A's override has no effect on customer B.
3. **Given** a per-customer rule already exists for (customer A, transfer, BWP), **When** a second active per-customer rule for the same combination is created, **Then** a 409 Conflict is returned with `FEE_RULE_CONFLICT`.
4. **Given** a per-customer rule submission with `rate = 0`, **When** submitted, **Then** validation rejects with `INVALID_FEE_RATE` (deactivate the rule to revert to global fee).

**API**: `POST /v1/fees/rules` with `subject_type = customer`, `subject_id = {customer_id}`.

**DB Entities**: FeeRule

---

### User Story 7 — Configure Agent Fee Rule (US-FEE-007, Priority: P1)

An operations admin configures fee rules specific to agents. Agent fee rules govern what agents are charged when facilitating transactions on the platform, independent of the agent commissions they *earn* (E14 domain). Agent-specific rules take precedence over global rules for the same transaction type. At most one active per-agent rule may exist for any given (agent_id, transaction_type, currency) combination.

**Why this priority**: Required before agent network go-live to differentiate agent cost structures from customer fees.

**Independent Test**: Admin creates a per-agent fixed fee of BWP 0.50 for cash-in transactions for agent B; when agent B is the direct transaction subject, the fee is BWP 0.50; other agents and customers continue using the global fee rule.

**Acceptance Scenarios**:
1. **Given** a global 1.0% cash-in fee and a per-agent BWP 0.50 fixed rule for agent B, **When** agent B is the transaction subject on a cash-in, **Then** the fee applied is 50 minor units (BWP 0.50).
2. **Given** no per-agent rule for agent C, **When** agent C initiates a qualifying transaction, **Then** the global fee rule applies.
3. **Given** an existing active per-agent rule for (agent B, cash_in, BWP), **When** a second active per-agent rule for the same combination is created, **Then** a 409 Conflict is returned with `FEE_RULE_CONFLICT`.

**API**: `POST /v1/fees/rules` with `subject_type = agent`, `subject_id = {agent_id}`.

**DB Entities**: FeeRule

---

### User Story 8 — Display Fee Before Transaction Confirmation (US-FEE-008, Priority: P0)

Before confirming any transaction, the requesting actor submits the transaction context (type, amount, currency, subject) to the fee preview endpoint. The platform resolves the applicable rule, calculates the exact fee that will be charged if the transaction proceeds, and returns it along with the rule ID and fee type used. The preview must be deterministic — the same inputs always return the same fee. The previewed fee must match the fee deducted during actual execution.

**Why this priority**: Regulatory and trust requirement — actors must see the exact cost before committing funds.

**Independent Test**: A fee preview request for a BWP 500 transfer returns `fee_amount_minor = 25`, `fee_type = percentage`, and the applicable `rule_id`; submitting the same request a second time returns the same result.

**Acceptance Scenarios**:
1. **Given** an active 0.5% transfer rule, **When** a fee preview for a BWP 500 transfer is requested, **Then** the response returns `fee_amount_minor = 25`, `fee_type = percentage`, `rule_id`.
2. **Given** a 0.5% transfer rule with `min_fee_minor = 100` and a BWP 100 transfer (raw fee: 50 minor units), **When** a fee preview is requested, **Then** `fee_amount_minor = 100` (minimum floor applied).
3. **Given** no active fee rule for the requested `transaction_type`, **When** a fee preview is requested, **Then** `fee_amount_minor = 0` is returned with `rule_id = null`.
4. **Given** a customer with a customer-specific override, **When** a fee preview is requested with `subject_type = customer`, `subject_id = {customer_id}`, **Then** the customer-specific rule is applied, not the global rule.
5. **Given** the same fee preview request is submitted twice concurrently, **When** both responses arrive, **Then** both return the same `fee_amount_minor` — fee calculation is deterministic and stateless.
6. **Given** an invalid `transaction_type` value, **When** a fee preview is requested, **Then** a 422 Unprocessable Entity is returned with `UNSUPPORTED_TRANSACTION_TYPE`.

**API**: `POST /v1/fees/calculate`

**DB Entities**: FeeRule (read-only during calculation)

---

### User Story 9 — Record Fee in Ledger (US-FEE-009, Priority: P0)

When a transaction executes successfully, the platform deducts the calculated fee from the customer's wallet and records a balanced double-entry journal entry via the E07 Ledger module. A `Fee` record is created linking the originating transaction ID, the applied fee rule, the calculated amount, and the ledger entry. Fee posting is atomic with transaction execution — ledger failure rolls back the entire transaction. Duplicate fee posting is prevented by a UNIQUE constraint on `fees.transaction_id`.

**Why this priority**: Core financial integrity — unrecorded fees create revenue leakage; partial fee posting (ledger entry without Fee record) must never occur.

**Independent Test**: A BWP 500 transfer with a 0.5% fee rule completes; a `Fee` record is created with `amount_minor = 25`, `status = posted`; a `JournalEntry` (entry_type = 'fee') debits the customer wallet and credits the platform fee revenue account by 25 minor units.

**Acceptance Scenarios**:
1. **Given** an active 0.5% transfer rule, **When** a BWP 500 transfer completes, **Then** a `Fee` record is created with `amount_minor = 25`, `status = posted`, references to `transaction_id` and `ledger_entry_id`; the journal entry has `entry_type = fee`, `debit_account = wallet:{customerId}`, `credit_account = revenue:fees:{currency}`.
2. **Given** `fee_amount_minor = 0` (no applicable rule for this transaction type), **When** a transaction completes, **Then** no `Fee` record is created and no ledger posting is made.
3. **Given** a fee amount of 25 minor units but insufficient balance to cover transfer plus fee, **When** execution is attempted, **Then** the entire transaction rolls back; no `Fee` record is created; the customer's balance is unchanged.
4. **Given** the same `transaction_id` triggers fee posting twice (at-least-once delivery), **When** the second trigger arrives, **Then** no duplicate `Fee` record is created (UNIQUE constraint on `fees.transaction_id`); the operation is idempotent.
5. **Given** a successfully posted fee and the originating transaction is subsequently reversed by an admin, **When** the reversal completes, **Then** a compensating journal entry (`entry_type = 'fee'`, `debit_account = revenue:fees:{currency}`, `credit_account = wallet:{customerId}`) is posted; the `Fee` record transitions to `status = reversed`. **Note**: This scenario requires an admin-initiated completed-transaction reversal service in each consuming module (E08/E09/E10/E15/E16). Pre-execution cancellation services do not trigger `reverseFee()` because the fee has not been recorded at that point. Implementation of `reverseFee()` in consuming modules is deferred to when admin reversal services are built (tracked as T044b–T048b).

**API**: Internal service call — no HTTP endpoint. `FeesModuleContract::recordFee(transactionId, amountMinor, ?feeRuleId, currency, debitAccountRef, creditAccountRef, idempotencyKey, correlationId)` is invoked by consuming modules (E08, E09, E10, E15, E16) after their transaction commits. `feeRuleId` is nullable — null when no matching rule existed at recording time. Each module passes its **intermediate fee account** as `debitAccountRef` (not `wallet:{customerId}` directly — the customer debit already occurred via the parent transaction's journal entry); `revenue:fees:{currency}` is always `creditAccountRef`. This fee-clearing entry moves the fee from the module's intermediate account to the central fee revenue account without double-charging. Per-module debitAccountRef: E09 Transfer → `config.transfer.fee_account.{currency}`; E08 Funding → `ledger.float_account.{currency}`; E10 Withdrawal → `ledger.fee_revenue.{currency}`; E15 Service → service payable ref; E16 QR → `ledger.fee_wallet.{currency}`. The caller derives `idempotencyKey` as `Uuid::uuid5(NAMESPACE_URL, transactionId.':fee')`. Posts via `LedgerModuleContract::postEntry(entry_type='fee')`.

**DB Entities**: Fee, FeeRule (read reference)

---

## Data Model

### FeeRule
| Field | Type | Notes |
|---|---|---|
| `id` | UUID v7 | Primary key |
| `transaction_type` | enum | `transfer`, `funding`, `withdrawal`, `bill_payment`, `qr_payment`, `cash_in`, `cash_out`, `settlement` |
| `fee_type` | enum | `fixed`, `percentage` |
| `rate` | decimal(10,4) | Required when `fee_type = percentage`; null otherwise |
| `amount_minor` | bigint | Required when `fee_type = fixed`; null otherwise |
| `min_fee_minor` | bigint | Nullable; percentage rules only |
| `max_fee_minor` | bigint | Nullable; percentage rules only |
| `currency` | char(3) | ISO 4217; default `BWP` |
| `subject_type` | enum | `global`, `customer`, `agent` |
| `subject_id` | UUID | Nullable; FK to customer or agent ID when subject_type is not global |
| `valid_from` | timestamptz | Inclusive effective start |
| `valid_to` | timestamptz | Nullable; inclusive effective end |
| `status` | enum | `active`, `expired`, `deactivated`, `superseded` |
| `pricing_version_id` | UUID | Nullable FK to PricingVersion |
| `created_by` | UUID | FK to admin user |
| `created_at`, `updated_at` | timestamptz | |

Unique constraint: `(transaction_type, currency, subject_type, subject_id)` WHERE `status = 'active'`

### Fee
| Field | Type | Notes |
|---|---|---|
| `id` | UUID v7 | Primary key |
| `transaction_id` | UUID | UNIQUE; FK to originating transaction — idempotency key |
| `fee_rule_id` | UUID | Nullable FK to FeeRule; null when no applicable rule existed at recording time |
| `amount_minor` | bigint | Calculated fee in minor units |
| `currency` | char(3) | ISO 4217 |
| `ledger_entry_id` | UUID | FK to LedgerEntry (E07) |
| `status` | enum | `posted`, `reversed` |
| `created_at`, `updated_at` | timestamptz | |

### PricingVersion
| Field | Type | Notes |
|---|---|---|
| `id` | UUID v7 | Primary key |
| `label` | varchar(128) | Human-readable label (e.g., "Q4 2026 Standard Pricing") |
| `effective_from` | timestamptz | |
| `effective_to` | timestamptz | Nullable |
| `is_published` | boolean | Published versions are immutable |
| `created_by` | UUID | FK to admin user |
| `created_at` | timestamptz | |

---

## Non-Functional Requirements

- **Idempotency**: `POST /v1/fees/rules` and `PATCH /v1/fees/rules/{id}` require `Idempotency-Key` header (Constitution P8).
- **Determinism**: `POST /v1/fees/calculate` must return identical results for identical inputs; fee calculation is stateless and read-only.
- **Atomicity**: Fee ledger posting (US-FEE-009) must be atomic with the parent transaction; partial fee posting is prohibited (Constitution P4).
- **Auditability**: All fee rule mutations emit audit events; `Fee` records link to ledger entry IDs for full traceability (Constitution P13).
- **Performance**: `POST /v1/fees/calculate` must respond within 200ms at the 99th percentile; fee rule resolution must not require more than 2 database round-trips.
- **Currency precision**: All monetary amounts stored as integer minor units; percentage calculations round using half-up at the minor-unit boundary.
- **Event reliability**: `FeePosted` and `FeeReversed` events use the outbox pattern for reliable downstream delivery (Constitution P9).

---

## Dependencies

| Epic | Dependency |
|---|---|
| E04 | `fees.manage` permission for admin CRUD; `fees.calculate` for preview |
| E07 | `LedgerModuleContract::postEntry(entry_type='fee')`; `JournalEntryType::Fee = 'fee'` enum extension + DB migration |
| E08 | Wallet Funding consumes FeeService for funding fees |
| E09 | Wallet-to-Wallet Transfer consumes FeeService for transfer fees |
| E10 | Withdrawal Cash-Out consumes FeeService for withdrawal fees |
| E11 | Transaction context (amount, type, actor) passed to FeeService |
| E12 | Agent ID used for per-agent fee rule scoping |
| E14 | Distinction: E14 CommissionRule governs agent earnings; E21 FeeRule governs platform fees charged on transactions |
| E15 | Service & Bill Payments consumes FeeService for bill payment fees |
| E16 | QR & Merchant Payments consumes FeeService for QR payment fees |
| E20 | `config('reconciliation.default_fee_rate')` PHP constant deprecated; settlement fees migrated to `fee_rules` with `transaction_type = settlement` |

---

## Constitution Check

| Principle | Status | Notes |
|---|---|---|
| P2 — TBD Governance | PASS | No fee amounts or rates are hard-coded; all rates are admin-configurable at runtime; E20's PHP config constant is deprecated |
| P3 — Configuration Over Hard-Coding | PASS | Fee rules stored in DB and managed via admin API — satisfies the constitution's explicit requirement for centrally configurable fees/commissions/settlement rules |
| P4 — Financial Integrity | PASS | US-FEE-009 uses E07 LedgerModuleContract double-entry; fee posting is atomic with parent transaction; UNIQUE constraint on `fees.transaction_id` prevents duplicate posting |
| P8 — API Contracts | PASS | Fee rule mutation endpoints require `Idempotency-Key`; `POST /v1/fees/calculate` is deterministic |
| P9 — Event Reliability | PASS | `FeePosted` and `FeeReversed` use outbox pattern; `FeeRuleCreated` / `FeeRuleDeactivated` emit audit events |
| P12 — Test-First Quality | PASS | Each story defines acceptance criteria, independent test, and multiple acceptance scenarios |
| P14 — No Silent Architecture Changes | PASS | No stack deviations; uses E07 LedgerModuleContract interface as designed |

---

## Epic-level Definition of Done

- All 9 stories implemented and passing their acceptance criteria.
- Fee calculation is deterministic and tested for concurrent execution (no race conditions).
- All fee rule mutations are idempotent, version-tracked, and audit-logged.
- `POST /v1/fees/calculate` returns the correct fee for percentage, fixed, min-bounded, max-bounded, customer-specific, and agent-specific scenarios.
- Fee ledger posting (US-FEE-009) is atomic with parent transaction execution; rollback on ledger failure is tested.
- E20 `config('reconciliation.default_fee_rate')` PHP constant is deprecated; `transaction_type = settlement` rule created in seeder.
- `JournalEntryType::Fee = 'fee'` verified pre-existing in E07 enum and DB CHECK constraint — no migration required (confirmed in research.md Decision 1).
- OpenAPI documentation covers all endpoints.
- PHPStan level-6 clean.
- All tests passing in CI.
