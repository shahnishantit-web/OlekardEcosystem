# P17 Reconciliation Clearing & Provider Settlement — Operator Runbook

## Overview

P17 provides end-to-end reconciliation between OleKard's platform records and provider settlement statements. The flow is:

```
Provider Statement → StatementMatchingJob → ClearingRecords → PayoutQualificationJob → PayoutQualifiedEvent
```

Corrections and exceptions surface in the Admin UI under Reconciliation.

---

## Flows

### 1. Provider Statement Ingestion

**Trigger**: Provider system POSTs to `POST /api/v1/payment/provider/statement-callback`

**Prerequisites**: Provider must have `payment.statement.ingest` permission.

**States**: `pending_match → matching → matched / partially_matched / exception_raised`

**Admin responsibilities**: Monitor statement status; investigate stalled statements in `matching` state.

**Mobile responsibilities**: None — this is a back-office flow.

**Success path**:
1. SHA-256 source hash computed and verified unique (409 if duplicate).
2. Statement + lines inserted in `payment.provider_statements` and `payment.provider_statement_lines`.
3. `StatementIngestionCompletedEvent` published to outbox.
4. `StatementMatchingJob` dispatched.

**Failure / Recovery**:
- Duplicate hash → 409 with `existing_statement_id`; caller can query the existing record.
- Transaction failure → DB rolls back; no outbox event; no job; safe to retry.
- `StatementMatchingJob` fails after 3 attempts → statement stays in `matching` status; manually re-dispatch via artisan or re-ingest the statement.

**Completion evidence**: Statement status = `matched` or `partially_matched`; `matching_completed_at` is set.

---

### 2. Statement Line Matching

**Trigger**: `StatementMatchingJob` dispatched after ingestion.

**States**: Lines transition from `pending` to `matched / amount_mismatch / no_platform_record / internal_transfer / exception_raised`.

**Success path**:
- Capture lines → matched PaymentAttempt → ClearingRecord created (`clearing_status=cleared`).
- Fee lines → matched to parent attempt.
- Internal transfers → `match_status=internal_transfer`; no ClearingRecord (no fictitious bank entry).
- Refund/Reversal/Chargeback → matched to existing platform records.
- Bottom-up MissingCallback detection: any succeeded attempt in the period not appearing in a statement line raises a `missing_callback` ReconciliationException.

**Failure / Recovery**:
- AmountMismatch → `amount_mismatch` exception created; line marked `exception_raised`.
- NoPlatformRecord → `orphan_provider` exception created.
- OrphanFee → `orphan_fee` exception created.
- Job dead letter → check Horizon queue; re-queue via `php artisan queue:retry`.

---

### 3. Capture Clearing vs Provider Settlement

**Capture clearing** (`clearing_status=cleared`) = OleKard confirmed the provider charged the customer.

**Bank settlement** (`clearing_status=bank_settled`) = Provider paid OleKard the net proceeds.

**These are INDEPENDENT** — a clearing record can be cleared-but-not-yet-settled.

**Trigger**: `bank.settlement.batch` inbox event → `BankSettlementBatchHandler`.

**Settlement ageing**: `SettlementAgeingCheckJob` runs daily. Any `cleared` record older than `payment.reconciliation.settlement_ageing_days` (default 7) with no bank settlement gets an `aged_at` timestamp and a `partial_settlement` ReconciliationException.

---

### 4. Exception Management and Corrections

**Exception types** (P17 additions): `orphan_fee`, `partial_settlement`, `acknowledged_external`.

**Correction approval (dual-gate)**:
- Submit: any admin with `payment.reconciliation.view_corrections`.
- Approve: any admin with `payment.reconciliation.approve_correction` **who is not the submitter**.
- Self-approval → HTTP 403.

**AcknowledgeExternal** (for `orphan_provider` exceptions):
- Sets status = `acknowledged_external`.
- **No ledger entry is created** — this is a deliberate exclusion of a confirmed valid external charge.
- Requires `evidence_note` ≥ 10 chars.

---

### 5. Payout Qualification

**Trigger**: Admin POSTs `POST /api/v1/admin/payment/provider-statements/{id}/qualify` (or auto-triggered after matching completes for `matched`/`partially_matched` statements).

**Formula**:
```
net_qualified_minor = max(0, gross_cleared_minor - fee_deductions_minor - dispute_hold_deductions_minor)
```

- `gross_cleared_minor` = sum of `cleared_amount_minor` for `accounting_status=qualified` clearing records.
- `fee_deductions_minor` = sum of matched fee statement lines.
- `dispute_hold_deductions_minor` = sum of `held_amount_minor` from open/under_review/awaiting_evidence dispute cases.
- `pending_ledger_config` clearing records are excluded (reported as `excluded_amount_minor`).

**PayoutQualifiedEvent** published to outbox with `versionHash` for idempotency.

---

## Key Tables

| Table | Purpose |
|---|---|
| `payment.provider_statements` | One row per statement import |
| `payment.provider_statement_lines` | One row per statement line |
| `payment.clearing_records` | One row per matched capture attempt |
| `payment.reconciliation_correction_requests` | Correction approvals with dual-gate |

---

## Alert Conditions

| Condition | Action |
|---|---|
| Statement stuck in `matching` > 30 min | Check Horizon queue; re-queue `StatementMatchingJob` |
| Clearing record `cleared_at < now() - 7 days` AND `bank_settled_at IS NULL` | Check `partial_settlement` exceptions; contact provider |
| `orphan_provider` exceptions > 0 | Investigate missing platform records; use AcknowledgeExternal if confirmed valid |
| `PayoutQualifiedEvent` not published after qualify | Check outbox table; verify no DB connectivity issue on platform connection |

---

## Security / Finance Evidence

- All correction approvals: audit action `payment.reconciliation.correction_approved`
- All external acknowledgements: audit action `payment.reconciliation.exception_acknowledged_external`
- Ledger entries: created via `LedgerModuleContract::postEntry()` — idempotent; NOT posted for `acknowledged_external`
- No manual mark-as-paid path exists — financial state changes only via verified provider evidence

---

## Runbook Scenarios

### Re-trigger matching for a stuck statement

```bash
php artisan tinker
>>> \App\Modules\Payment\Application\Jobs\StatementMatchingJob::dispatch('STATEMENT-UUID-HERE');
```

### Re-trigger qualification

```bash
POST /api/v1/admin/payment/provider-statements/STATEMENT-UUID/qualify
Authorization: Bearer <admin-token>
Idempotency-Key: manual-requalify-STATEMENT-UUID
```

### Check ageing job last run

```bash
php artisan schedule:list
# Look for: App\Modules\Payment\Application\Jobs\SettlementAgeingCheckJob
```

### View orphan exceptions

```bash
GET /api/v1/admin/payment/reconciliation/exceptions?type=orphan_provider&status=open
Authorization: Bearer <admin-token>
```
