# Research: P17 — Reconciliation Clearing and Provider Settlement

**Date**: 2026-10-25

---

## Decision 1: Content-Hash Algorithm for Statement Deduplication

**Decision**: SHA-256 hex digest of the canonical string `{provider_code}|{account_id}|{environment}|{period_start_iso}|{period_end_iso}|{sorted_provider_reference_ids_csv}`.

**Rationale**: SHA-256 is collision-resistant, widely supported in PHP (`hash('sha256', ...)`), and produces a fixed 64-char hex string suitable for a UNIQUE INDEX column. Sorting and canonicalising the reference IDs before hashing ensures the same statement content produces the same hash regardless of line order.

**Alternatives considered**:
- MD5: Rejected — known collision vulnerabilities, insufficient for financial dedup.
- SHA-1: Rejected — deprecated for collision resistance.
- Full payload hash (including amounts): Considered but rejected — a reformatted file with amounts in different decimal precision would produce a different hash for the same content. Hashing reference IDs + metadata (not amounts) is more robust.

---

## Decision 2: Statement Line Matching Algorithm

**Decision**: Exact 3-field match: `(provider_reference_id, amount_minor, currency_code)` against the appropriate platform record type. Line type determines which table to query:
- `capture` → `payment_attempts` WHERE `provider_reference_id` AND `amount_minor` AND `currency_code` AND `status = succeeded`
- `fee` → `payment_attempts` via fee columns; if no fee column match → `OrphanFee` exception
- `refund` → `payment_refunds` WHERE `provider_submitted_ref = provider_reference_id` AND `amount_minor` AND `currency_code` AND `status IN ('confirmed','settled')`
- `reversal`/`chargeback` → `payment_dispute_cases` WHERE `provider_dispute_reference = provider_reference_id` AND `disputed_amount_minor` AND `currency_code`
- `internal_transfer` → classified immediately; no platform record match attempted; excluded from clearing

**Rationale**: Provider reference IDs are the canonical dedup key for financial records. Exact amount matching prevents false positives. Internal transfer lines must be explicitly excluded to prevent fictitious bank settlement records.

**Alternatives considered**:
- Fuzzy amount matching (±1 minor unit): Rejected — creates ambiguity; amount mismatches should be exceptions, not silent matches.
- Two-pass matching (first by reference, then by amount): Could be implemented as a future improvement for `amount_mismatch` resolution, but out of scope for P17.

---

## Decision 3: Dual-Approval Correction Workflow Pattern

**Decision**: Reuse the maker-checker pattern established in P12 (`PaymentConfigChangeDraftAdminController`). `ReconciliationCorrectionRequest` has `requested_by` and `reviewed_by` with the constraint `reviewed_by != requested_by` enforced in `ReconciliationCorrectionService::approve()`.

**Rationale**: P12 established the precedent for high-risk financial configuration changes requiring two actors. Correction approval is a higher-stakes operation (direct ledger impact) and must use the same governance model.

**Alternatives considered**:
- Single approver: Rejected — Constitution Principle 5 requires privileged financial operations to have step-up/MFA or equivalent governance; dual approval is the appropriate control.
- Workflow engine (state machine library): Rejected — the existing pattern of explicit status fields with service-layer guards is sufficient and keeps the codebase consistent.

---

## Decision 4: Settlement Ageing Window

**Decision**: The ageing threshold (default: 7 business days) is configurable via `ConfigurationServiceInterface` with key `payment.reconciliation.settlement_ageing_days`. The `SettlementAgeingCheckJob` runs daily and creates `ReconciliationException(PartialSettlement)` for clearing records that exceed the threshold without a matching bank settlement batch.

**Rationale**: Constitution Principle 3 prohibits hard-coded thresholds. The `ConfigurationServiceInterface` pattern is already used across the codebase for similar timeout/threshold values.

**Alternatives considered**:
- Hard-coded 7 days: Rejected — Constitution Principle 3 violation.
- Configurable per-provider: Possible future enhancement but not required for P17 scope.

---

## Decision 5: `ClearingRecord` vs E20 `SettlementBatch`

**Decision**: P17's `ClearingRecord` is a NEW entity within the Payment module's `payment` schema. It is NOT the same as E20's `SettlementBatch` in the `reconciliation` schema. They serve different purposes:
- E20 `SettlementBatch`: internal OleKard settlement between agents and the platform
- P17 `ClearingRecord`: provider-bank clearing of captured transactions

**Rationale**: E20 owns internal reconciliation between platform and agent/wallet participants. P17 owns provider-side reconciliation between the payment provider (bank) and the platform's captured transactions. These are distinct financial events at different boundaries.

**Alternatives considered**:
- Extend E20's settlement tables: Rejected — would create cross-module database coupling and violate domain boundary (Principle 7).

---

## Decision 6: ReconciliationExceptions DB Constraint Repair

**Decision**: Migration `2026_10_25_000018` must `DROP CONSTRAINT recon_exceptions_type_check` and recreate it with ALL current exception types (original 7 + P07 ContradictoryEvidence + P07 ExcessCapture + P07 DuplicateCapture + P08 AccountingFailure + P15 UnsupportedRefundMethod + P15 DefinitiveRefundFailure + P16 UnmatchedDisputeEvent + P17 OrphanFee + P17 PartialSettlement).

**Rationale**: The DB CHECK constraint was created in the original P-series migration but never updated as new exception types were added in P07, P08, P15, and P16. This is a data-integrity gap — new types can be saved (PHP enum doesn't validate against DB constraint at the application layer) but the CHECK constraint is inconsistent with reality.

**Alternatives considered**:
- Leave constraint unrepaireD: Rejected — inconsistent DB constraints are a maintenance risk.
- DROP constraint entirely (no DB-level type validation): Rejected — belt-and-suspenders is preferred for financial tables.

---

## Decision 7: Wallet Internal Transfer Classification

**Decision**: Statement lines with `line_type = internal_transfer` are matched against `PaymentIntentModel` by `provider_reference_id` and verified to have `payment_type = wallet_to_wallet` (or similar Wallet module origin signal). If the match confirms a wallet operation, the line is classified `internal_transfer` and no `ClearingRecord` is created. If the line cannot be matched as internal, it falls through to `OrphanProvider` exception.

**Rationale**: FR-007 requires wallet-to-wallet transfers to never generate clearing records. The classification must be verifiable against platform records — not just asserted by the provider statement.

---

## Decision 8: PayoutQualifiedEvent Timing

**Decision**: `PayoutQualificationJob` is dispatched automatically after `StatementMatchingJob` completes (via chaining or outbox event). The qualification computation runs on the final match state (even if `partially_matched`) to give the payout scheduler an up-to-date signal. A re-run publishes a new `PayoutQualifiedEvent` — the previous event is not mutated (append-only event log).

**Rationale**: The payout scheduler should not wait for 100% match rate — it needs to know cleared amounts promptly, even with outstanding exceptions. Each qualification event has an idempotency key that includes the match state version.
