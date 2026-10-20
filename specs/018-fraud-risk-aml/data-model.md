# Data Model — E18 Fraud, Risk & AML

Module: `App\Modules\Risk\`
Schema: `risk` (PostgreSQL schema-per-module convention)

---

## Entities

### RiskRule

Configures a single evaluation rule (limit, velocity threshold, device policy, etc.). Rules are versioned and auditable.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID v7 | PK | |
| name | VARCHAR(100) | NOT NULL, UNIQUE | Machine-readable key, e.g. `customer.per_tx_limit` |
| description | TEXT | nullable | Human-readable label |
| rule_type | ENUM | NOT NULL | `amount_limit`, `velocity_count`, `velocity_amount`, `device_score`, `ip_score`, `beneficiary_score`, `auth_failure_count` |
| applies_to | ENUM | NOT NULL | `global`, `customer`, `agent`, `wallet` |
| subject_id | UUID | nullable, indexed | Specific customer/agent/wallet ID when applies_to ≠ global |
| threshold_amount | NUMERIC(19,4) | nullable | Used for limit-type rules |
| threshold_count | INTEGER | nullable | Used for count-type rules |
| window_seconds | INTEGER | nullable | Rolling window for velocity checks (NULL = per-transaction) |
| risk_score_weight | NUMERIC(5,2) | NOT NULL, DEFAULT 1.0 | Score contribution multiplier |
| action | ENUM | NOT NULL | `allow`, `block`, `review` — outcome when this rule triggers |
| is_active | BOOLEAN | NOT NULL, DEFAULT true | Soft disable without deleting |
| effective_from | TIMESTAMPTZ | NOT NULL, DEFAULT NOW() | |
| effective_until | TIMESTAMPTZ | nullable | Rule expiry |
| created_by | UUID | NOT NULL | User ID of creator |
| updated_by | UUID | NOT NULL | User ID of last updater |
| created_at | TIMESTAMPTZ | NOT NULL | |
| updated_at | TIMESTAMPTZ | NOT NULL | |

**Indexes:** `(rule_type, applies_to, is_active)`, `(applies_to, subject_id)` partial where `subject_id IS NOT NULL`
**Unique constraint:** `UNIQUE(name)` for global rules; `UNIQUE(name, subject_id)` for subject-scoped rules

---

### RiskEvaluation

Immutable record of a single synchronous risk evaluation run for a transaction. One evaluation per inbound `checkPreTransaction` call.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID v7 | PK | |
| idempotency_key | VARCHAR(128) | NOT NULL, UNIQUE | Caller-supplied; prevents duplicate evaluations |
| transaction_reference | VARCHAR(128) | NOT NULL, indexed | Source transaction ID or correlation ID |
| transaction_type | VARCHAR(64) | NOT NULL | `funding`, `transfer`, `withdrawal`, `bill_payment`, `qr_payment` |
| amount | NUMERIC(19,4) | NOT NULL | |
| currency | CHAR(3) | NOT NULL, DEFAULT 'BWP' | |
| customer_id | UUID | NOT NULL, indexed | |
| wallet_id | UUID | nullable | |
| agent_id | UUID | nullable | |
| device_id | VARCHAR(128) | nullable | From E03 session context |
| ip_address | INET | nullable | |
| beneficiary_id | UUID | nullable | |
| kyc_tier | VARCHAR(20) | nullable | Snapshot of KYC tier at evaluation time |
| overall_score | NUMERIC(5,2) | NOT NULL | Aggregated risk score 0.00–100.00 |
| risk_level | ENUM | NOT NULL | `low`, `medium`, `high`, `critical` |
| decision | ENUM | NOT NULL | `allow`, `block`, `review` |
| evaluation_ms | INTEGER | NOT NULL | Wall-clock duration in milliseconds |
| evaluated_at | TIMESTAMPTZ | NOT NULL | |
| rules_applied | JSONB | NOT NULL | Array of `{rule_id, score_contribution, triggered}` |
| metadata | JSONB | nullable | |

**Indexes:** `(customer_id, evaluated_at DESC)`, `(transaction_reference)`, `(risk_level, evaluated_at DESC)`
**Constraint:** `overall_score BETWEEN 0 AND 100`, `evaluation_ms >= 0`

---

### RiskEvent

Append-only audit log of risk-relevant occurrences (async pattern detections, watchlist hits, auth failures). Never updated or deleted.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID v7 | PK | |
| event_type | VARCHAR(100) | NOT NULL | e.g. `velocity.exceeded`, `watchlist.match`, `auth.repeated_failure`, `pattern.unusual`, `pattern.rapid_movement` |
| severity | ENUM | NOT NULL | `low`, `medium`, `high`, `critical` |
| subject_type | ENUM | NOT NULL | `customer`, `agent`, `wallet`, `device`, `ip` |
| subject_id | UUID | NOT NULL, indexed | |
| transaction_reference | VARCHAR(128) | nullable | Associated transaction if applicable |
| evaluation_id | UUID | nullable, FK → RiskEvaluation | |
| correlation_id | VARCHAR(128) | NOT NULL, indexed | Trace correlation |
| context | JSONB | NOT NULL | Raw detection signals |
| detected_at | TIMESTAMPTZ | NOT NULL | |
| created_at | TIMESTAMPTZ | NOT NULL | |

**Indexes:** `(subject_id, detected_at DESC)`, `(event_type, detected_at DESC)`, `(severity, detected_at DESC)`

---

### RiskAlert

An actionable alert raised for a compliance officer or risk team to investigate. Created from one or more `RiskEvent` records.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID v7 | PK | |
| alert_number | BIGSERIAL | UNIQUE | Human-readable reference |
| alert_type | VARCHAR(100) | NOT NULL | e.g. `velocity`, `watchlist`, `pattern`, `auth_failure`, `threshold_breach` |
| status | ENUM | NOT NULL | `open`, `under_investigation`, `escalated`, `resolved_true_positive`, `resolved_false_positive`, `closed` |
| severity | ENUM | NOT NULL | `low`, `medium`, `high`, `critical` |
| subject_type | ENUM | NOT NULL | `customer`, `agent`, `wallet` |
| subject_id | UUID | NOT NULL, indexed | |
| title | VARCHAR(255) | NOT NULL | |
| description | TEXT | NOT NULL | |
| assigned_to | UUID | nullable | Risk officer user ID |
| sla_due_at | TIMESTAMPTZ | NOT NULL | Configurable SLA deadline |
| escalated_at | TIMESTAMPTZ | nullable | |
| resolved_at | TIMESTAMPTZ | nullable | |
| resolution_notes | TEXT | nullable | |
| created_by | UUID | NOT NULL | `system` UUID for auto-generated |
| updated_by | UUID | NOT NULL | |
| version | INTEGER | NOT NULL, DEFAULT 1 | Optimistic locking |
| created_at | TIMESTAMPTZ | NOT NULL | |
| updated_at | TIMESTAMPTZ | NOT NULL | |

**Indexes:** `(status, severity, sla_due_at)`, `(subject_id, status)`, `(assigned_to, status)` partial where `assigned_to IS NOT NULL`
**Constraint:** `resolved_at IS NOT NULL WHEN status IN ('resolved_true_positive', 'resolved_false_positive', 'closed')`

---

### RiskAlertEvent

Join table linking RiskAlert → RiskEvent (many-to-many).

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| alert_id | UUID | NOT NULL, FK → RiskAlert | |
| event_id | UUID | NOT NULL, FK → RiskEvent | |
| linked_at | TIMESTAMPTZ | NOT NULL | |

**Primary key:** `(alert_id, event_id)`

---

### RiskDecision

Records the outcome when a risk officer makes a manual determination on an alert or review-flagged transaction.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID v7 | PK | |
| alert_id | UUID | NOT NULL, FK → RiskAlert, indexed | |
| decision | ENUM | NOT NULL | `clear`, `escalate`, `freeze_account`, `file_sar`, `false_positive` |
| rationale | TEXT | NOT NULL | Mandatory explanation |
| decided_by | UUID | NOT NULL | Officer user ID |
| decided_at | TIMESTAMPTZ | NOT NULL | |
| maker_id | UUID | nullable | For maker-checker workflows |
| checker_id | UUID | nullable | Approver user ID |
| approved_at | TIMESTAMPTZ | nullable | |
| created_at | TIMESTAMPTZ | NOT NULL | |

**Constraint:** `checker_id IS NOT NULL WHEN decision IN ('freeze_account', 'file_sar')` (maker-checker enforced for high-impact decisions)

---

### WatchlistEntry

Locally managed sanctions and PEP (politically exposed person) entries. Fed from FIA lists and admin uploads.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID v7 | PK | |
| list_type | ENUM | NOT NULL | `sanctions`, `pep`, `adverse_media`, `internal_blocklist` |
| entity_type | ENUM | NOT NULL | `individual`, `organization` |
| full_name | VARCHAR(500) | NOT NULL | |
| normalized_name | VARCHAR(500) | NOT NULL, indexed | Lowercased, stripped of diacritics — used for fuzzy matching |
| aliases | JSONB | nullable | Array of alternative names |
| date_of_birth | DATE | nullable | |
| nationality | CHAR(2) | nullable | ISO 3166-1 alpha-2 |
| id_numbers | JSONB | nullable | `{type, value}` array |
| source | VARCHAR(255) | NOT NULL | e.g. `FIA Botswana`, `OFAC`, `manual` |
| source_ref | VARCHAR(255) | nullable | External reference ID |
| is_active | BOOLEAN | NOT NULL, DEFAULT true | |
| listed_at | TIMESTAMPTZ | nullable | When entity was originally listed |
| expires_at | TIMESTAMPTZ | nullable | |
| created_at | TIMESTAMPTZ | NOT NULL | |
| updated_at | TIMESTAMPTZ | NOT NULL | |

**Indexes:** `(normalized_name)` with `pg_trgm` GIN index for fuzzy matching, `(list_type, is_active)`

---

### WatchlistMatch

Records a screening hit between a customer/agent and a WatchlistEntry.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID v7 | PK | |
| watchlist_entry_id | UUID | NOT NULL, FK → WatchlistEntry | |
| subject_type | ENUM | NOT NULL | `customer`, `agent` |
| subject_id | UUID | NOT NULL, indexed | |
| match_score | NUMERIC(5,2) | NOT NULL | 0.00–100.00 confidence |
| match_method | ENUM | NOT NULL | `exact`, `fuzzy`, `alias`, `retrospective` |
| screened_at | TIMESTAMPTZ | NOT NULL | |
| alert_id | UUID | nullable, FK → RiskAlert | Alert generated from this match |
| cleared_at | TIMESTAMPTZ | nullable | If cleared as false positive |
| created_at | TIMESTAMPTZ | NOT NULL | |

**Unique constraint:** `UNIQUE(watchlist_entry_id, subject_type, subject_id)` — one active match per pair (soft: cleared_at IS NULL)

---

### AccountHold

A hold placed on an account restricting transaction processing. Holds are separate from wallet balance — they block new transactions but do not affect the ledger.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID v7 | PK | |
| subject_type | ENUM | NOT NULL | `customer`, `agent` |
| subject_id | UUID | NOT NULL, indexed | |
| wallet_id | UUID | nullable | If null, applies to all wallets |
| hold_type | ENUM | NOT NULL | `risk_freeze`, `compliance_hold`, `aml_investigation` |
| status | ENUM | NOT NULL | `active`, `lifted`, `expired` |
| reason | TEXT | NOT NULL | |
| alert_id | UUID | nullable, FK → RiskAlert | |
| decision_id | UUID | nullable, FK → RiskDecision | |
| placed_by | UUID | NOT NULL | |
| lifted_by | UUID | nullable | |
| placed_at | TIMESTAMPTZ | NOT NULL | |
| expires_at | TIMESTAMPTZ | nullable | Auto-lift if set |
| lifted_at | TIMESTAMPTZ | nullable | |
| lift_reason | TEXT | nullable | |
| created_at | TIMESTAMPTZ | NOT NULL | |
| updated_at | TIMESTAMPTZ | NOT NULL | |

**Indexes:** `(subject_id, status)`, `(status, expires_at)` partial where `expires_at IS NOT NULL`

---

### SuspiciousActivityReport (SAR)

Draft and submitted SARs filed with Botswana FIA per the Financial Intelligence Act, 2009.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID v7 | PK | |
| report_number | BIGSERIAL | UNIQUE | Internal reference |
| status | ENUM | NOT NULL | `draft`, `under_review`, `approved`, `submitted`, `submission_failed`, `acknowledged` |
| subject_type | ENUM | NOT NULL | `customer`, `agent` |
| subject_id | UUID | NOT NULL, indexed | |
| alert_id | UUID | NOT NULL, FK → RiskAlert | |
| decision_id | UUID | NOT NULL, FK → RiskDecision | |
| narrative | TEXT | NOT NULL | Required FIA field |
| suspected_offences | JSONB | NOT NULL | Array of offence codes |
| transaction_references | JSONB | NOT NULL | Array of related transaction IDs |
| amount_involved | NUMERIC(19,4) | NOT NULL | |
| currency | CHAR(3) | NOT NULL, DEFAULT 'BWP' | |
| reporting_period_start | DATE | NOT NULL | |
| reporting_period_end | DATE | NOT NULL | |
| filer_id | UUID | NOT NULL | Compliance officer user ID |
| approver_id | UUID | nullable | Second approver user ID |
| approved_at | TIMESTAMPTZ | nullable | |
| submitted_at | TIMESTAMPTZ | nullable | |
| fia_reference | VARCHAR(100) | nullable | FIA acknowledgement ref |
| fia_acknowledged_at | TIMESTAMPTZ | nullable | |
| submission_attempts | INTEGER | NOT NULL, DEFAULT 0 | |
| last_submission_error | TEXT | nullable | |
| created_at | TIMESTAMPTZ | NOT NULL | |
| updated_at | TIMESTAMPTZ | NOT NULL | |

**Constraint:** `reporting_period_end >= reporting_period_start`, `amount_involved > 0`

---

## State Transitions

### RiskAlert.status

```
open → under_investigation → escalated → resolved_true_positive
                           ↘ resolved_false_positive
                           ↘ closed
open → closed (auto-expire if no activity within SLA + grace period)
```

### AccountHold.status

```
active → lifted (by officer with RiskDecision)
active → expired (by scheduled job when expires_at passes)
```

### SuspiciousActivityReport.status

```
draft → under_review → approved → submitted → acknowledged
                                 ↘ submission_failed → submitted (retry)
```

---

## Relationships

```
RiskRule            (1)──evaluations──(N) RiskEvaluation
RiskEvaluation      (1)──events──(N)      RiskEvent (via evaluation_id)
RiskEvent           (N)──alerts──(N)      RiskAlert (via RiskAlertEvent)
RiskAlert           (1)──decisions──(N)   RiskDecision
RiskAlert           (1)──holds──(N)       AccountHold
RiskAlert           (1)──sar──(0..1)      SuspiciousActivityReport
WatchlistEntry      (1)──matches──(N)     WatchlistMatch
WatchlistMatch      (0..1)──alert──(1)    RiskAlert
```

---

## Notes

- All monetary amounts stored as `NUMERIC(19,4)` — never floating point.
- `RiskEvent` is append-only. No UPDATE or DELETE operations are permitted after insertion.
- `WatchlistEntry.normalized_name` requires `pg_trgm` extension enabled in the `risk` schema for similarity search.
- `RiskRule` effective-date range enforces that only rules where `effective_from <= NOW() AND (effective_until IS NULL OR effective_until > NOW())` are applied during evaluation.
- `AccountHold` does not modify the ledger. The wallet/transaction module checks `AccountHold` existence before posting.
