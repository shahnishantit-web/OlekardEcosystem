# Feature Specification: P17 — Reconciliation Clearing and Provider Settlement

**Feature Branch**: `064-reconciliation-settlement`

**Created**: 2026-10-25

**Status**: Draft

**Input**: P17 — Reconciliation clearing and provider settlement. Mode: Implement. Source refs: FD09; LC13; AD08. Required predecessors: P08, P10, P15, P16.

**Predecessor evidence verified**:
- P08 COMPLETE — `LedgerModuleContract::postEntry()` live; `payment_intents.accounting_status` (pending_ledger/ledger_posted/ledger_failed) confirmed; `ReconciliationException(AccountingFailure)` wired
- P10 COMPLETE — Outbox/inbox delivery confirmed; `bank.dispute.callback` event routing live (E25 ingress pattern)
- P15 COMPLETE — `PaymentRefundModel`, `RefundService`, confirmed-refund sum on attempt, `ReconciliationException(DefinitiveRefundFailure|UnsupportedRefundMethod)` wired
- P16 COMPLETE — `PaymentDisputeCaseModel`, `DisputeHold`, `DisputeAccountingStatus`, correcting-journal idempotency keys confirmed; `ReconciliationException(UnmatchedDisputeEvent)` wired

**Accounting decision status** (reviewed 2026-10-25):
- AD-08 UNRESOLVED — Credit account for wallet payment journal still pending Finance Lead; P17 reconciliation reads ledger journal IDs but does not post new journals (handled by P08 repair job); no dependency blocker
- AD-09 UNRESOLVED — Bank payment chart of accounts still pending; P17 treats bank captures as unconfirmed until bank settlement matches; partial blocker on net-settlement reconciliation for bank payments (marked PENDING in clearing records)

---

## User Scenarios & Testing

### User Story 1 — Provider Statement Ingestion (Priority: P1)

A finance operator uploads or pushes an authenticated provider settlement statement. The system validates the statement metadata (provider account, environment, currency, reporting period/cutoff) and stores it as an immutable record. If an identical statement (same provider account + period + file hash) has already been imported, the system rejects the duplicate and returns the existing statement reference. Each line item in the statement is stored individually for matching.

**Why this priority**: All downstream matching, clearing, and payout qualification depend on having the provider's authoritative view of transactions. Without ingestion, no reconciliation can occur. Duplicate-import protection prevents double-counting of settlements.

**Independent Test**: Upload a valid statement → verify a `ProviderStatement` record is created with all metadata and all lines stored; upload the same statement again with the same source hash → verify 409 + existing statement ID returned; upload a statement with a missing required field → verify 422 with field-level errors.

**Acceptance Scenarios**:

1. **Given** a valid provider statement payload with account, environment, currency, period start/end, and line items, **When** the finance operator submits it to the ingestion endpoint, **Then** a `ProviderStatement` is created with `status = pending_match`; all line items are persisted as `ProviderStatementLine` records; an idempotency key based on (provider_code + account_id + period + source_hash) prevents duplicate creation; a `StatementIngestionCompletedEvent` is published to the outbox.

2. **Given** a statement with the same (provider_code + account_id + period + source_hash) already exists, **When** the finance operator submits it again, **Then** the system returns HTTP 409 with the existing `statement_id`; no new statement or line records are created.

3. **Given** a statement payload missing a required field (e.g. `currency_code`), **When** submitted, **Then** the system returns HTTP 422 with field-level validation errors; no partial records are persisted.

4. **Given** a statement is submitted from an unauthorised source (missing or invalid provider authentication), **When** processed, **Then** the system returns HTTP 401; no data is stored.

---

### User Story 2 — Statement Line Matching (Priority: P1)

The system automatically attempts to match each provider statement line against platform records: payment attempts (captures), fees, refunds, and reversals/chargebacks. A line is matched when a platform record with the same provider reference, amount, and currency is found. Unmatched lines are classified as a typed `ReconciliationException` (orphan capture, missing callback, amount mismatch, fee mismatch, unmatched reversal) with an assigned owner (Finance/Provider/Engineering) and an ageing timestamp. Matched lines advance the statement to `status = matched`.

**Why this priority**: Matching is the core reconciliation function. Without it, exceptions cannot be discovered, cleared funds cannot be qualified, and the payout window cannot be determined. This story delivers the minimum viable reconciliation loop.

**Independent Test**: Ingest a statement with 3 lines (one matching a known capture, one with an amount mismatch, one with no platform record); run matching; verify one line is matched, one creates an `AmountMismatch` exception, one creates an `OrphanProvider` exception; verify `ProviderStatement.status = partially_matched`; verify exception ageing timestamps are set.

**Acceptance Scenarios**:

1. **Given** a `ProviderStatement` with `status = pending_match`, **When** the matching job runs, **Then** each `ProviderStatementLine` is compared against `PaymentAttemptModel` records by `provider_reference_id`; lines with a matching attempt in `succeeded` state and an exact amount match are marked `matched`; `ProviderStatementLine.payment_attempt_id` is set.

2. **Given** a statement line references a known provider reference but the amount differs, **When** matching runs, **Then** the line is marked `amount_mismatch`; a `ReconciliationException(AmountMismatch)` is created with `detected_amount_minor`, `expected_amount_minor`, owner = Finance, `detected_at` set.

3. **Given** a statement line references a provider reference with no corresponding platform record, **When** matching runs, **Then** the line is marked `orphan`; a `ReconciliationException(OrphanProvider)` is created with owner = Finance/Provider.

4. **Given** a payment attempt exists in `succeeded` state but has no corresponding statement line after the statement's reporting period closes, **When** matching runs, **Then** a `ReconciliationException(MissingCallback)` is created for the orphaned attempt.

5. **Given** a refund or reversal line in the statement, **When** matching runs, **Then** the line is matched against `PaymentRefundModel` or `PaymentDisputeCaseModel` records respectively; unmatched refund lines create `ReconciliationException(FailedRefund)`.

6. **Given** all lines in a statement are matched, **When** matching completes, **Then** `ProviderStatement.status` advances to `matched`.

---

### User Story 3 — Capture Clearing vs Provider Settlement (Priority: P2)

Capture clearing and actual provider bank settlement are maintained as separate lifecycle states. A matched capture is "cleared" (the platform has confirmed the transaction occurred), but it is not "settled" until the provider's bank settlement batch has been received and matched. Wallet-to-wallet internal transfers never create bank settlement records — only provider-intermediated captures do. Cleared amounts represent qualified availability for payout scheduling; settlement amounts represent what has actually been received from the provider bank.

**Why this priority**: Confusing clearing with settlement creates incorrect payout schedules and overstates available funds. Separating them enforces the architecture boundary (AD-08: wallet balances owned by Wallet module; settlement belongs to the provider/bank layer).

**Independent Test**: Record 5 matched captures (cleared); verify a `ClearingRecord` exists for each; record a bank settlement batch matching 3 of them; verify 3 are marked `settled`, 2 remain `cleared_not_settled`; verify wallet-to-wallet transfers have no clearing records.

**Acceptance Scenarios**:

1. **Given** a `ProviderStatementLine` is matched to a capture, **When** clearing runs, **Then** a `ClearingRecord` is created with `status = cleared`; the capture's `cleared_at` is set; `net_cleared_amount_minor` is computed (capture amount − fee lines); no wallet balance is mutated.

2. **Given** a provider bank settlement batch is received, **When** the batch is matched against clearing records, **Then** clearing records in the batch are updated to `status = settled`; `settled_at` is set; the difference between `net_cleared_amount_minor` and `bank_settlement_amount_minor` is computed and flagged if non-zero.

3. **Given** a payment was made via wallet-to-wallet transfer (internal Wallet module operation), **When** clearing runs for statement lines, **Then** no `ClearingRecord` is created for that transfer; the statement line is classified as `internal_transfer` and excluded from provider settlement totals.

4. **Given** a clearing record exists but no bank settlement batch arrives within the configured ageing window, **When** the ageing job runs, **Then** a `ReconciliationException(PartialSettlement)` is raised flagging the unmatched cleared amount.

---

### User Story 4 — Exception Management and Correction Approval (Priority: P2)

Finance operators investigate reconciliation exceptions and record resolution evidence. Corrections that require a ledger adjustment are submitted via the approved accounting correction workflow — the correction is only applied after a second authorised operator approves the evidence. The system enforces the constraint that no exception can be marked resolved without documented evidence and that no journal is directly edited — only compensating entries are created via `LedgerModuleContract`.

**Why this priority**: Without a governed correction path, finance teams resort to manual workarounds outside the system, breaking the ledger's integrity. The evidence-first, dual-approval workflow protects against fraudulent or erroneous manual corrections.

**Independent Test**: Create an exception; attempt to resolve without evidence → rejected; add evidence note; submit correction request; approve from a second account; verify `ReconciliationException.status = resolved`, correction ledger entry posted via `LedgerModuleContract`.

**Acceptance Scenarios**:

1. **Given** an open `ReconciliationException`, **When** a finance operator attempts to mark it resolved without providing an evidence note, **Then** the system returns HTTP 422 with "Evidence note required for resolution".

2. **Given** an open exception with a required ledger correction (amount mismatch), **When** a finance operator submits a correction request with evidence, **Then** the request enters `correction_pending_approval` status; a notification is sent to authorised approvers; no ledger entry is posted yet.

3. **Given** a `correction_pending_approval` request, **When** a second authorised finance operator approves it, **Then** the correction ledger entry is posted via `LedgerModuleContract::postEntry()` with idempotency key `recon:{exceptionId}:correction`; `ReconciliationException.status` advances to `resolved`; the correction is audited.

4. **Given** an exception is of type `OrphanProvider` (no platform record found), **When** a finance operator investigates and confirms the orphan is a valid charge, **Then** a new platform record is NOT auto-created; the operator records an evidence note referencing an external investigation; the exception is marked `acknowledged_external` to indicate it has been reviewed.

5. **Given** a correction approval request is rejected by the approver, **When** the rejection is submitted, **Then** the correction request moves to `correction_rejected`; no ledger entry is posted; the original exception remains `open` for further investigation.

---

### User Story 5 — Payout Qualification and Totals Publishing (Priority: P2)

The system computes and publishes a reconciled summary for each statement/period: total cleared amount, total settled amount, total exceptions by type, and net qualified-for-payout amount. This summary is published as an integration event for the downstream payout/scheduling module. The published amounts reflect only reconciled, cleared captures and explicitly exclude unresolved exceptions, wallet-to-wallet transfers, and any amounts under active dispute.

**Why this priority**: The payout module needs an authoritative, finance-verified signal of what funds are available. Without a qualified-totals event, the payout module must make assumptions that may overstate or understate available funds.

**Independent Test**: Complete a statement match with 8 cleared captures, 2 exceptions, 1 dispute hold; verify the qualified-totals event contains the correct net (8 cleared − 1 disputed − 0 unresolved exceptions); verify wallet-to-wallet transfers are excluded; verify event is published to the outbox.

**Acceptance Scenarios**:

1. **Given** a `ProviderStatement` reaches `status = matched` (or partially matched), **When** the payout qualification job runs, **Then** a `PayoutQualificationSummary` is computed: total_cleared_minor, total_settled_minor, total_disputed_hold_minor, total_exception_count, net_qualified_minor = total_cleared − total_disputed_hold − sum(unresolved exception amounts).

2. **Given** the qualification summary is computed, **When** published, **Then** a `PayoutQualifiedEvent` is published to the outbox with the summary payload and statement_id; the event has an idempotency key `payout.qualified:{statementId}:{versionHash}` where `versionHash` is derived from the current match state, enabling safe re-publication when exceptions are later resolved without duplicating a prior event with identical amounts.

3. **Given** a subsequent matching run resolves additional exceptions for the same statement, **When** the qualification job re-runs, **Then** the updated summary is published as a new `PayoutQualifiedEvent`; the previous event is not mutated.

4. **Given** amounts under active `DisputeHold` exist for an account, **When** the qualification summary is computed, **Then** the disputed amounts are subtracted from `net_qualified_minor`; the `total_disputed_hold_minor` field reflects the held amount.

---

### Edge Cases

- What happens when a statement line amount is in a different minor-unit precision than the platform currency? → Statement is rejected at ingestion with a validation error.
- How does the system handle a statement period that overlaps with a previous statement for the same account? → The overlapping lines are flagged for review; duplicate detection uses (provider_code + account_id + provider_reference_id + period).
- What if a correction approval is submitted for an exception that has already been resolved by a concurrent approver? → Idempotent — already resolved exceptions return the existing resolution; no second ledger entry is posted.
- What if a provider statement contains fee lines with no corresponding capture? → Classified as `ReconciliationException(OrphanFee)` — a new exception sub-type.
- What if the bank settlement batch amount differs from the sum of clearing records? → A `ReconciliationException(PartialSettlement)` is raised for the delta; no automatic adjustment is made.
- What if `LedgerModuleContract::postEntry()` fails during correction approval? → Correction remains in `correction_pending_approval`; the failure is logged at `error` level; operator must retry.

---

## Requirements

### Functional Requirements

- **FR-001**: The system MUST accept provider statement imports authenticated by the platform's E25 ingress credential model; unauthenticated imports MUST be rejected with HTTP 401.
- **FR-002**: The system MUST deduplicate statement imports using a content-based hash (provider_code + account_id + reporting_period + file/payload hash); duplicate submissions MUST return HTTP 409 with the existing statement ID.
- **FR-003**: The system MUST store all statement lines atomically with the parent statement; a partial-line import failure MUST result in a full rollback.
- **FR-004**: The system MUST match statement lines against platform records (payment attempts, fees, refunds, reversals/chargebacks) by provider reference ID, amount, and currency.
- **FR-005**: The system MUST classify unmatched statement lines as typed `ReconciliationException` records with an assigned owner (Finance/Provider/Engineering) and an `aged_at` timestamp.
- **FR-006**: The system MUST maintain capture clearing as a distinct lifecycle state from provider bank settlement; cleared amount and settled amount MUST be stored as separate fields.
- **FR-007**: Wallet-to-wallet internal transfers MUST NOT generate `ClearingRecord` entries or contribute to provider settlement totals.
- **FR-008**: The system MUST require documented evidence for any exception resolution; resolution without an evidence note MUST be rejected.
- **FR-009**: Ledger correction entries MUST be posted only via `LedgerModuleContract::postEntry()` with idempotency key `recon:{exceptionId}:correction`; direct journal editing is prohibited.
- **FR-010**: Ledger correction posting MUST require a second authorised finance operator's approval; the same operator who submitted the correction request MUST NOT be the approver.
- **FR-011**: The system MUST publish a `PayoutQualifiedEvent` to the outbox when a statement's reconciled totals are computed; the event MUST include net_qualified_minor, total_cleared_minor, total_disputed_hold_minor, and exception counts.
- **FR-012**: Active `DisputeHold` amounts (from P16) MUST be deducted from the net qualified payout amount for the associated account.
- **FR-013**: The system MUST expose an admin API for querying statements by status, account, currency, and period; for querying and filtering reconciliation exceptions by type, owner, and ageing; and for submitting/approving correction requests.
- **FR-014**: All correction request submissions and approvals MUST be recorded in the platform audit log with before/after state.
- **FR-015**: The system MUST emit structured metrics: statement import count, match rate per statement, exception count by type, correction approval lag, and payout qualification latency.

### Key Entities

- **ProviderStatement**: The imported provider reconciliation statement. Attributes: id (UUID v7), provider_code, account_id, environment (sandbox/production), currency_code, period_start, period_end, source_hash (content dedup key), status (pending_match → partially_matched → matched → exported), total_lines, matched_lines, imported_by, imported_at.

- **ProviderStatementLine**: A single line item from a statement. Attributes: id, statement_id (FK), line_type (capture/fee/refund/reversal/internal_transfer), provider_reference_id, amount_minor, currency_code, value_date, match_status (pending/matched/amount_mismatch/orphan/unmatched_refund/unmatched_reversal/internal_transfer), payment_attempt_id (nullable FK), matched_at (nullable).

- **ClearingRecord**: Records that a capture has been cleared. Attributes: id, payment_attempt_id (FK, UNIQUE), statement_line_id (FK), cleared_amount_minor (provider-confirmed gross amount), attempt_amount_minor (original attempt amount for mismatch detection), clearing_status (cleared → bank_settled — tracks provider-bank receipt lifecycle), accounting_status (pending_ledger_config | qualified | excluded — tracks qualification readiness; pending_ledger_config for AD-09 bank providers), cleared_at, bank_settled_at (nullable), bank_settlement_batch_ref (nullable), aged_at (nullable). Note: net amounts (cleared − fees − dispute_holds) are computed at payout qualification time via `PayoutQualificationService`; they are not stored as columns on `ClearingRecord`.

- **ReconciliationCorrectionRequest**: An evidence-backed request to post a correcting ledger entry. Attributes: id, exception_id (FK), requested_by, evidence_note (required free-text explanation — minimum 10 characters), evidence_reference (optional URL/document reference for external evidence), debit_account, credit_account, correction_amount_minor, status (pending_approval → approved → rejected → posted → posting_failed), submitted_at, reviewed_by (nullable), reviewed_at (nullable), ledger_entry_id (nullable — returned by LedgerModuleContract), idempotency_key (recon:{exceptionId}:correction).

- **PayoutQualificationSummary** (computed/published): statement_id, period, total_cleared_minor, total_settled_minor, total_disputed_hold_minor, total_exception_count, unresolved_exception_amount_minor, net_qualified_minor, computed_at.

---

## Success Criteria

### Measurable Outcomes

- **SC-001**: Duplicate statement imports are detected and rejected 100% of the time within the same reporting period for the same account.
- **SC-002**: Statement line matching completes within 60 seconds for statements containing up to 10,000 lines.
- **SC-003**: All unmatched statement lines produce a typed `ReconciliationException` with an owner and ageing timestamp within the same matching run.
- **SC-004**: Correction approval requires documented evidence and a second authorised approver; 0% of corrections bypass the dual-approval gate.
- **SC-005**: Wallet-to-wallet internal transfers are excluded from all provider settlement totals (0 false positives in clearing records for internal transfers).
- **SC-006**: `PayoutQualifiedEvent` is published within 5 minutes of a statement's matching run completing.
- **SC-007**: Disputed hold amounts (P16 `DisputeHold`) are correctly deducted from `net_qualified_minor` in 100% of qualification summaries.
- **SC-008**: The admin exception list API responds in under 500 ms for datasets up to 50,000 exceptions with standard pagination.

---

## Assumptions

- Provider statements are pushed via the E25 ingress infrastructure (same authenticated webhook channel as dispute callbacks); P17 adds new event types but does not introduce a new transport layer.
- `ReconciliationCorrectionRequest.evidence_note` is a required free-text field (minimum 10 characters); `evidence_reference` is an optional structured URL/document reference. Both serve FR-008: a correction cannot be submitted without documented rationale.
- `acknowledged_external` is an in-scope P17 status for `ReconciliationException`. It applies to `OrphanProvider` exceptions (US4-4) where a finance operator confirms the charge is valid but handled externally — no ledger correction is needed. This status is set via a distinct `acknowledgeExternal()` action; it does not post a ledger entry.
- `ClearingRecord` stores two independent status dimensions: `clearing_status` (cleared → bank_settled) for provider-bank lifecycle, and `accounting_status` (pending_ledger_config | qualified | excluded) for qualification readiness. Net amounts are computed at qualification time and are not stored as columns on `ClearingRecord`.
- Bottom-up MissingCallback detection (US2-4) is in P17 scope: after processing all statement lines, `StatementMatchingService` queries platform `PaymentAttemptModel` records within the statement period that have no corresponding matched line and raises `ReconciliationException(MissingCallback)` for each orphan.
- `LedgerModuleContract::postEntry()` is the only permitted mechanism for ledger corrections; P17 does not introduce any alternative journal path.
- Bank settlement batch ingestion uses the same E25 provider callback infrastructure (a `bank.settlement.batch` event type); P17 does not implement direct bank polling.
- Provider statement line amounts are provided in integer minor units matching the platform's currency precision; statements in other formats are rejected at ingestion.
- The payout module (out of scope for P17) consumes `PayoutQualifiedEvent` to schedule actual fund disbursement; P17 only publishes the qualification signal.
- AD-08 (credit account for wallet payment journal) being unresolved does not block P17 — reconciliation reads existing journal IDs from `PaymentAttemptModel.ledger_journal_id` but does not post new journals.
- AD-09 (bank payment chart of accounts) being unresolved means bank-payment clearing records will have `accounting_status = pending_ledger_config` and are excluded from `net_qualified_minor` until AD-09 is resolved.
- The `ClearingRecord` entity is new to P17; no equivalent exists in E20 (Reconciliation & Settlement module) for the payment-provider layer. E20 handles internal reconciliation; P17 handles provider-side reconciliation.
- `ReconciliationCorrectionRequest` dual-approval requires the `payment.reconciliation.approve_correction` permission, distinct from the existing `payment.reconciliation.manage` permission.
- Orphan fee lines (fee with no capture) are a new exception sub-type `OrphanFee` to be added to `ReconciliationExceptionType` enum.
- Partial settlement ageing window is configurable via Platform Configuration service (Principle 3) and defaults to 7 business days.
