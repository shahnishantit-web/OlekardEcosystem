# Runbook: Payment Reconciliation Exceptions

**E48 | T161**
**Last reviewed**: 2026-10-18

---

## Overview

Reconciliation exceptions are created when internal payment records diverge from provider transaction statements or the wallet ledger. They are managed via `ReconciliationExceptionService` and the admin UI. **No mark-as-paid path exists** — financial state changes only occur through verified provider evidence.

---

## Exception Types and Triage

### 1. `missing_callback`

**Meaning**: A `PaymentAttempt` was initiated but no callback arrived within the expected window.

**SQL**:
```sql
SELECT a.id, a.provider_code, a.status, a.initiated_at, a.timeout_at
FROM payment.payment_attempts a
LEFT JOIN payment.payment_callbacks c ON c.platform_entity_id = a.id
WHERE a.status = 'processing'
  AND a.timeout_at < NOW()
  AND c.id IS NULL;
```

**Resolution**:
- Contact provider to confirm transaction status
- If provider confirms SUCCESS: manually queue a synthetic callback (engineering-only via `PaymentCallbackProcessingJob` with crafted payload — requires manager approval)
- If provider confirms FAILURE: mark attempt as timed_out via admin UI (available transition)
- If provider unreachable: leave open, escalate to finance

**Can auto-resolve**: No

---

### 2. `unknown_outcome`

**Meaning**: A callback was received but the `status` field did not map to a known `ProviderStatus`.

**SQL**:
```sql
SELECT id, provider_code, raw_payload, failure_reason, created_at
FROM payment.payment_callbacks
WHERE status = 'failed'
  AND failure_reason ILIKE '%unknown%outcome%';
```

**Resolution**:
- Inspect `raw_payload` to determine provider's actual intent
- If provider added a new status code: update `MockBankCallbackParser` (or real bank parser) to map it
- Re-queue the callback after parser fix

**Can auto-resolve**: After parser fix and re-queue

---

### 3. `amount_mismatch`

**Meaning**: Provider reported a transaction amount different from the `PaymentAttempt.amount_minor`.

**SQL**:
```sql
SELECT e.id, e.amount_minor AS exception_amount, a.amount_minor AS attempt_amount,
       e.provider_reference_id, e.created_at
FROM payment.reconciliation_exceptions e
JOIN payment.payment_attempts a ON a.id = e.payment_attempt_id
WHERE e.type = 'amount_mismatch';
```

**Resolution**:
- NEVER auto-resolve: requires finance team review
- Contact provider to confirm which amount is authoritative
- If provider amount differs: create internal ticket for finance reconciliation
- Do NOT adjust DB amounts — amounts are immutable after confirmation

**Can auto-resolve**: No — always requires human review

---

### 4. `duplicate_transaction`

**Meaning**: Two separate `PaymentAttempt` records succeeded for the same `PaymentIntent`.

**SQL**:
```sql
SELECT payment_intent_id, COUNT(*) AS succeeded_count
FROM payment.payment_attempts
WHERE status = 'succeeded'
GROUP BY payment_intent_id
HAVING COUNT(*) > 1;
```

**Resolution**:
- One attempt is the correct one; the other is an error
- Identify which attempt has a matching provider transaction in the bank statement
- Initiate refund on the duplicate via `RefundService` with `type = system_correction`
- Requires `payment.refunds.approve` + manager sign-off

**Can auto-resolve**: No

---

### 5. `orphan_provider`

**Meaning**: Provider has a transaction record with no matching `PaymentAttempt` in the DB.

**Resolution**:
- Check if the attempt was created but DB write failed (DB outage during initiation)
- If so: verify the provider reference ID, create a `PaymentAttempt` record (engineering-only operation)
- If origin is unknown: escalate to finance + security (potential unauthorised payment)

**Can auto-resolve**: No

---

### 6. `failed_refund`

**Meaning**: `RefundService.confirm()` was called but the provider reported a failure.

**SQL**:
```sql
SELECT r.id, r.amount_minor, r.status, r.provider_refund_reference, e.created_at
FROM payment.payment_refunds r
JOIN payment.reconciliation_exceptions e ON e.payment_attempt_id = r.payment_attempt_id
WHERE r.status = 'failed' AND e.type = 'failed_refund';
```

**Resolution**:
- Contact provider to determine if refund was applied
- If provider confirms refund succeeded: update `payment_refunds.status = 'succeeded'` via admin (engineering-only; requires manager approval)
- If provider confirms refund failed: retry via `RefundService.initiate()` after root cause fix

**Can auto-resolve**: No

---

### 7. `unmatched_ledger`

**Meaning**: A `PaymentAttempt` succeeded but no matching double-entry journal entry was created in the Ledger module.

**SQL**:
```sql
SELECT a.id, a.payment_intent_id, a.ledger_journal_id, a.confirmed_at
FROM payment.payment_attempts a
WHERE a.status = 'succeeded'
  AND a.ledger_journal_id IS NULL
  AND a.confirmed_at < NOW() - INTERVAL '5 minutes';
```

**Resolution**:
- This is a critical integrity failure — escalate immediately to payment engineering
- Do NOT process new payments from this provider until ledger posting is confirmed working
- Engineering must replay the `PaymentConfirmedEvent` via outbox replay (see `OutboxEventPublisher` docs)

**Can auto-resolve**: No — escalate immediately

---

## Resolving an Exception

Resolve via admin UI or API. All resolutions are audited.

```bash
curl -X PATCH /api/v1/admin/payment/reconciliation/exceptions/{id} \
  -H "Authorization: Bearer {admin_token}" \
  -d '{
    "status": "resolved",
    "resolution_reason": "Provider confirmed SUCCESS via bank statement ref #12345",
    "notes": "Manually verified with bank ops team 2026-10-18"
  }'
```

No financial state changes occur through this endpoint. It records the investigation outcome only.

---

## Escalation Path

| Severity | Exception Types | Escalate To |
|----------|----------------|-------------|
| P1 | `amount_mismatch`, `unmatched_ledger`, `duplicate_transaction` | Payment Engineering + Finance + On-call |
| P2 | `orphan_provider`, `failed_refund` | Payment Engineering |
| P3 | `missing_callback`, `unknown_outcome` | On-call engineer |
