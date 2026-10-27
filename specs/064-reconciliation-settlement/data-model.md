# Data Model: P17 — Reconciliation Clearing and Provider Settlement

**Schema**: `payment` (existing Payment module schema)

---

## Entity: ProviderStatement

Represents a complete settlement statement received from a payment provider.

**Table**: `payment.provider_statements`

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | `uuid` (v7) | NO | Primary key |
| `provider_code` | `varchar(50)` | NO | Provider identifier (e.g. `airtel_momo`, `equity_bank`) |
| `account_id` | `varchar(100)` | NO | Provider account or merchant reference |
| `environment` | `varchar(20)` | NO | `production` or `sandbox` |
| `currency_code` | `char(3)` | NO | ISO-4217 (e.g. `BWP`, `ZAR`) |
| `period_start` | `timestamptz` | NO | Settlement period start (inclusive) |
| `period_end` | `timestamptz` | NO | Settlement period end (inclusive) |
| `source_hash` | `char(64)` | NO | SHA-256 dedup hash — UNIQUE INDEX |
| `status` | `varchar(30)` | NO | Enum: `pending_match`, `matching`, `matched`, `partially_matched`, `exception_raised`, `closed` |
| `total_lines` | `integer` | NO | Total line count from statement |
| `matched_lines` | `integer` | NO | Lines successfully matched |
| `exception_lines` | `integer` | NO | Lines escalated to exceptions |
| `submitted_by` | `uuid` | YES | Actor who submitted (null = automated ingress via E25) |
| `ingested_at` | `timestamptz` | NO | When the statement was recorded |
| `matching_completed_at` | `timestamptz` | YES | When matching job finished |
| `created_at` | `timestamptz` | NO | |
| `updated_at` | `timestamptz` | NO | |

**Indexes**: `(provider_code, period_start, period_end)`, UNIQUE `(source_hash)`, `(status)`, `(created_at)`

**State Transitions** (`ProviderStatementStatus` enum):
```
pending_match → matching → matched
                         → partially_matched → exception_raised → closed
                                             → closed (after all exceptions resolved)
```

**Content Hash**: `SHA-256( "{provider_code}|{account_id}|{environment}|{period_start_iso}|{period_end_iso}|{sorted_provider_reference_ids_csv}" )`

---

## Entity: ProviderStatementLine

One line within a provider statement representing a single financial event.

**Table**: `payment.provider_statement_lines`

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | `uuid` (v7) | NO | Primary key |
| `statement_id` | `uuid` | NO | FK → `provider_statements.id` |
| `line_type` | `varchar(30)` | NO | Enum: `capture`, `fee`, `refund`, `reversal`, `chargeback`, `internal_transfer`, `adjustment` |
| `provider_reference_id` | `varchar(200)` | NO | Provider's reference for this transaction |
| `amount_minor` | `bigint` | NO | Amount in minor units (always positive) |
| `currency_code` | `char(3)` | NO | ISO-4217 |
| `direction` | `varchar(10)` | NO | `credit` or `debit` |
| `settled_at` | `timestamptz` | NO | When provider settled this line |
| `match_status` | `varchar(30)` | NO | Enum: `pending`, `matched`, `amount_mismatch`, `no_platform_record`, `internal_transfer`, `exception_raised` |
| `matched_entity_type` | `varchar(50)` | YES | e.g. `payment_attempt`, `payment_refund`, `payment_dispute_case`, null if unmatched |
| `matched_entity_id` | `uuid` | YES | FK-equivalent; null if unmatched |
| `clearing_record_id` | `uuid` | YES | FK → `clearing_records.id` (null for non-capture lines) |
| `exception_id` | `uuid` | YES | FK → `reconciliation_exceptions.id` (null if matched) |
| `metadata` | `jsonb` | YES | Provider-specific extra fields |
| `created_at` | `timestamptz` | NO | |
| `updated_at` | `timestamptz` | NO | |

**Indexes**: `(statement_id)`, `(provider_reference_id)`, `(match_status)`, `(clearing_record_id)`, `(matched_entity_id)`

---

## Entity: ClearingRecord

Records the fact that a payment capture has been acknowledged in a provider settlement statement. Distinct from bank fund receipt.

**Table**: `payment.clearing_records`

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | `uuid` (v7) | NO | Primary key |
| `payment_attempt_id` | `uuid` | NO | FK → `payment_attempts.id` (UNIQUE — one clearing record per attempt) |
| `statement_id` | `uuid` | NO | FK → `provider_statements.id` |
| `statement_line_id` | `uuid` | NO | FK → `provider_statement_lines.id` |
| `provider_code` | `varchar(50)` | NO | |
| `currency_code` | `char(3)` | NO | |
| `cleared_amount_minor` | `bigint` | NO | Provider-confirmed cleared amount |
| `attempt_amount_minor` | `bigint` | NO | Original attempt amount (for mismatch detection) |
| `clearing_status` | `varchar(30)` | NO | Enum: `cleared`, `bank_settled`; DEFAULT `cleared` — provider-bank lifecycle |
| `accounting_status` | `varchar(40)` | NO | Enum: `pending_ledger_config`, `qualified`, `excluded` — qualification readiness |
| `bank_settlement_batch_ref` | `varchar(200)` | YES | Provider bank settlement batch reference (null until received) |
| `bank_settled_at` | `timestamptz` | YES | Timestamp of bank settlement (null until received) |
| `aged_at` | `timestamptz` | YES | Timestamp when ageing exception was raised |
| `cleared_at` | `timestamptz` | NO | When this clearing record was created |
| `created_at` | `timestamptz` | NO | |
| `updated_at` | `timestamptz` | NO | |

**Indexes**: UNIQUE `(payment_attempt_id)`, `(statement_id)`, `(accounting_status)`, `(clearing_status)`, `(bank_settled_at)`

**clearing_status values** (provider-bank lifecycle):
- `cleared` — statement line matched; ClearingRecord created; funds not yet received from bank
- `bank_settled` — bank settlement batch received and matched; `bank_settled_at` set

**accounting_status values** (qualification readiness):
- `pending_ledger_config` — AD-08/AD-09 unresolved; excluded from `net_qualified_minor`
- `qualified` — provider-confirmed, eligible for payout qualification
- `excluded` — explicitly excluded (e.g. reversal, internal transfer origin)

Note: `clearing_status` and `accounting_status` are independent dimensions. A record can be `clearing_status=bank_settled` AND `accounting_status=pending_ledger_config` (bank received but still awaiting AD-09 resolution).

---

## Entity: ReconciliationCorrectionRequest

Dual-approval request to post a correcting journal entry via `LedgerModuleContract`.

**Table**: `payment.reconciliation_correction_requests`

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | `uuid` (v7) | NO | Primary key |
| `exception_id` | `uuid` | NO | FK → `reconciliation_exceptions.id` |
| `idempotency_key` | `varchar(200)` | NO | Format: `recon:{exception_id}:correction` — UNIQUE INDEX |
| `correction_type` | `varchar(50)` | NO | e.g. `amount_mismatch_correction`, `fee_correction`, `reversal_correction` |
| `description` | `text` | NO | Human-readable reason for correction |
| `evidence_note` | `text` | NO | Required free-text rationale (minimum 10 chars); enforced at service layer |
| `evidence_reference` | `varchar(500)` | YES | Optional external URL/document reference |
| `debit_account` | `varchar(100)` | YES | COA debit leg (populated on submission) |
| `credit_account` | `varchar(100)` | YES | COA credit leg (populated on submission) |
| `amount_minor` | `bigint` | NO | Correction amount in minor units |
| `currency_code` | `char(3)` | NO | |
| `status` | `varchar(30)` | NO | Enum: `pending_approval`, `approved`, `rejected`, `posted`, `posting_failed` |
| `requested_by` | `uuid` | NO | Actor who submitted the correction request |
| `reviewed_by` | `uuid` | YES | Actor who approved or rejected (must differ from `requested_by`) |
| `reviewed_at` | `timestamptz` | YES | |
| `rejection_reason` | `text` | YES | Populated on rejection |
| `ledger_entry_id` | `varchar(200)` | YES | Returned by `LedgerModuleContract::postEntry()` after posting |
| `posted_at` | `timestamptz` | YES | |
| `created_at` | `timestamptz` | NO | |
| `updated_at` | `timestamptz` | NO | |

**Indexes**: UNIQUE `(idempotency_key)`, `(exception_id)`, `(status)`, `(requested_by)`, `(reviewed_by)`

**Constraint**: `reviewed_by != requested_by` — enforced in service layer (not DB constraint) with audit logging

**State Transitions** (`CorrectionRequestStatus` enum):
```
pending_approval → approved → posted
              ↓           → posting_failed
              → rejected
```

---

## Existing Entity ALTERED: ReconciliationException

**Table**: `payment.reconciliation_exceptions` — ALTER; add `owner` and `aged_at` columns.

**New columns**:
| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `owner` | `varchar(100)` | YES | Team or queue responsible for resolving the exception |
| `aged_at` | `timestamptz` | YES | Timestamp when the ageing threshold was breached |

**DB CHECK constraint repair** — migration must:
1. `DROP CONSTRAINT IF EXISTS recon_exceptions_type_check`
2. Recreate with all 16 values: `missing_callback`, `unknown_outcome`, `amount_mismatch`, `duplicate_transaction`, `orphan_provider`, `failed_refund`, `unmatched_ledger`, `contradictory_evidence`, `excess_capture`, `duplicate_capture`, `accounting_failure`, `unsupported_refund_method`, `definitive_refund_failure`, `unmatched_dispute_event`, `orphan_fee`, `partial_settlement`

---

## Value Object: PayoutQualificationSummary

Not persisted directly — computed on demand and included in `PayoutQualifiedEvent` payload.

| Field | Type | Description |
|-------|------|-------------|
| `statement_id` | `string` (UUID) | Source statement |
| `provider_code` | `string` | |
| `currency_code` | `string` | |
| `period_start` | `Carbon` | |
| `period_end` | `Carbon` | |
| `gross_cleared_minor` | `int` | Sum of all `qualified` clearing records |
| `fee_deductions_minor` | `int` | Sum of matched fee lines against qualified captures |
| `dispute_hold_deductions_minor` | `int` | Sum of open `DisputeHold` amounts against qualified captures |
| `net_qualified_minor` | `int` | `gross_cleared - fees - dispute_holds` (never < 0) |
| `excluded_amount_minor` | `int` | Sum of `pending_ledger_config` clearing records (AD-08/AD-09) |
| `open_exception_count` | `int` | Unresolved exceptions at time of computation |
| `computed_at` | `Carbon` | |
| `version_hash` | `string` | Hash of match state at computation time (for idempotency key) |

---

## Enum Definitions

### ProviderStatementStatus
`pending_match | matching | matched | partially_matched | exception_raised | closed`

### StatementLineType
`capture | fee | refund | reversal | chargeback | internal_transfer | adjustment`

### StatementLineMatchStatus
`pending | matched | amount_mismatch | no_platform_record | internal_transfer | exception_raised`

### ClearingRecordStatus
Two independent status dimensions on `ClearingRecord`:

**clearing_status** (provider-bank lifecycle): `cleared | bank_settled`

**accounting_status** (qualification readiness): `pending_ledger_config | qualified | excluded`

### CorrectionRequestStatus
`pending_approval | approved | rejected | posted | posting_failed`

### ReconciliationExceptionType (EXTENDED)
Existing 14 + 2 new:
- `orphan_fee` (**NEW P17**) — fee line in statement with no matching capture on platform
- `partial_settlement` (**NEW P17**) — clearing record exists but bank settlement batch has not arrived within ageing window

---

## Entity Relationships

```
ProviderStatement ──< ProviderStatementLine
                         │
                         ├── matched_entity_id → PaymentAttempt (capture/fee)
                         │                     → PaymentRefund (refund)
                         │                     → PaymentDisputeCase (chargeback/reversal)
                         │
                         ├── clearing_record_id → ClearingRecord >── payment_attempt_id → PaymentAttempt
                         │                                      └── statement_id → ProviderStatement
                         │
                         └── exception_id → ReconciliationException
                                              └──< ReconciliationCorrectionRequest
```

---

## Migration Order

1. `2026_10_25_000014_create_provider_statements_table`
2. `2026_10_25_000015_create_provider_statement_lines_table` (FK → statements)
3. `2026_10_25_000016_create_clearing_records_table` (FK → statements, lines, attempts)
4. `2026_10_25_000017_create_reconciliation_correction_requests_table` (FK → exceptions)
5. `2026_10_25_000018_alter_reconciliation_exceptions_add_owner_ageing` (ALTER + constraint repair)
