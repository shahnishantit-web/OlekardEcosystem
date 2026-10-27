# Runbook: Refund Unknown-State Resolution

**Module**: Payment / Shared Refund Engine (P15)  
**Last updated**: 2026-10-24  
**On-call permission required**: `payment.refunds.manage`

---

## 1. Investigate UNKNOWN Refund

An `unknown` refund means the provider query loop exhausted its maximum attempts
(`payment.refund_max_query_attempts`, default 24 × 5 min = 2 h) without receiving a
definitive response. Funds remain reserved (counted by `sumReservedRefundsForAttempt`).

### Prerequisites

- Admin portal access or API access with `payment.refunds.view` + `payment.refunds.manage`
- `payment_id` or `refund_id` from the alert / monitoring dashboard

### Steps

1. **List unknown refunds via Admin API**

   ```http
   GET /api/v1/admin/payment/refund-management?status=unknown
   Authorization: Bearer <admin-token>
   ```

   Response includes `provider_submitted_ref`, `query_attempts`, `unknown_at`.

2. **Get refund detail**

   ```http
   GET /api/v1/admin/payment/refund-management/{refund_id}
   ```

3. **Check provider dashboard** using `provider_submitted_ref` to determine actual status
   in the bank's system (confirmed / declined / still pending).

4. **Trigger a fresh status query** (dispatches `QueryRefundStatusJob` immediately):

   ```http
   POST /api/v1/admin/payment/refund-management/{refund_id}/query-status
   Authorization: Bearer <admin-token>
   ```

   Returns `202 Accepted`. Check logs within 60 s for outcome.

5. **Verify metric drops**: `payment.refund.unknown_count` should decrease after resolution.

---

## 2. Resolve AD-16 Blocked Bank Refund Accounting

Bank refunds set `refund_accounting_status = pending_ledger_refund_fee_blocked` because
fee policy (AD-16) is unresolved. No ledger entry has been posted for these refunds.

> **Note**: AD-16 is a deferred accounting decision for bank refund fees. Until AD-16
> is resolved, do NOT manually post journal entries — doing so risks double-posting once
> the decision is implemented.

### Symptoms

- `payment.refund.accounting_blocked_count` metric is non-zero.
- `RefundAccountingRepairJob` logs: `"pending_ledger_refund_fee_blocked requires AD-16 resolution"`.

### Steps (AFTER AD-16 is formally resolved)

1. Update the `RefundService::confirm()` bank branch to post the correct journal once
   the fee policy is documented. Remove the `pending_ledger_refund_fee_blocked` path.

2. Backfill existing `pending_ledger_refund_fee_blocked` rows by running `RefundAccountingRepairJob`
   (it skips fee-blocked rows by design — the backfill logic needs to be added separately).

3. Verify `payment.refund.accounting_blocked_count` drops to 0.

> Until AD-16 is resolved, **do not** unblock these rows manually.

---

## 3. Force-Close a Permanently Ambiguous Refund

Use this procedure only when manual investigation has confirmed the bank definitively
declined or lost the refund, but no callback arrived.

### Audit requirements

All force-close actions MUST be recorded with:
- Actor ID (your admin user ID)
- Reason (reference to bank investigation ticket)
- Correlation ID

### Procedure

1. Confirm the bank has no record of the refund (obtain written evidence / ticket ref).

2. Call `definitivelyFail()` via internal tooling or a dedicated admin command:

   ```bash
   # From within the API container:
   php artisan refund:force-close {refund_id} --reason="Bank confirmed no record. Ref: TICKET-1234" --actor={your_id}
   ```

   This transitions `unknown → definitive_failure`, releases the reserved amount,
   creates a `ReconciliationException(DefinitiveRefundFailure)`, and publishes
   `RefundFailedEvent` to the outbox.

3. **Idempotency**: If the refund is already `definitive_failure`, the command returns
   without creating a duplicate exception or event.

4. Notify the customer via support ticket that the refund was unsuccessful.

5. If the original refund amount must be returned via another mechanism (e.g., bank
   transfer), create a separate support ticket — do NOT re-request via the refund API
   using the same `payment_intent_id` (the `sumReservedRefundsForAttempt` guard may
   block it if the original reservation has not cleared yet).

---

## Metric Reference

| Metric | Type | Description |
|--------|------|-------------|
| `payment.refund.unknown_count` | gauge | Unknown refunds older than `refund_unknown_alert_threshold_hours` |
| `payment.refund.accounting_blocked_count` | gauge | AD-16 fee-blocked refunds not yet journalled |
| `payment.refund.state_transition` | counter | State transitions, tagged `from_state`, `to_state`, `provider_code`, `is_wallet` |

## Related

- `specs/062-shared-refund-engine/spec.md` — P15 refund spec
- `docs/runbooks/payment-reconciliation-exceptions.md` — ReconciliationException handling
- `docs/architecture/` — AD-16 accounting decision (pending)
