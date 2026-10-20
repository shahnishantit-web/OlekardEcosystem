# Runbook: Payment Callback Dead-Letter Queue

**E48 | T160**
**Last reviewed**: 2026-10-18

---

## Overview

Payment callbacks that cannot be processed after 3 retries (`$tries = 3`) are marked with `status = 'failed'` in `payment.payment_callbacks` and a `ReconciliationException(type=missing_callback)` is created.

This runbook covers: how to find them, investigate, re-queue safely, and when to escalate.

---

## Step 1: Find Dead-Lettered Callbacks

```sql
-- Count by provider and age
SELECT
    provider_code,
    COUNT(*) AS failed_count,
    MIN(created_at) AS oldest
FROM payment.payment_callbacks
WHERE status = 'failed'
GROUP BY provider_code
ORDER BY oldest ASC;

-- Inspect individual callbacks
SELECT
    id,
    provider_code,
    raw_payload::text,
    failure_reason,
    retry_count,
    created_at
FROM payment.payment_callbacks
WHERE status = 'failed'
ORDER BY created_at DESC
LIMIT 20;
```

---

## Step 2: Investigate Each Callback

For each failed callback, check:

1. **Signature failure**: Was `WebhookSignatureException` thrown?
   - Check `failure_reason` column
   - If yes: This is likely a HMAC secret mismatch (see T162 secret rotation runbook)

2. **Intent not found**: Was the `platform_entity_id` (payment intent/attempt ID) valid?
   ```sql
   SELECT id, status FROM payment.payment_intents WHERE id = '{id_from_payload}';
   ```

3. **Out-of-order delivery**: Was the attempt already in a terminal state?
   - Check `payment_attempts.status` for the relevant attempt

4. **Parsing failure**: Was the raw payload malformed?
   - Check `failure_reason` for JSON parse errors

5. **Transient error**: DB connection, Redis timeout, etc.
   - Safe to re-queue

---

## Step 3: Re-queue a Callback (only when safe)

**Safety check before re-queuing**:
- Ensure the attempt is NOT already in `succeeded` status (re-queuing a success confirmation for an already-succeeded attempt is handled by idempotency — safe, but verify)
- Ensure the callback is not a duplicate of one already processed (check `idempotency_key` in `payment_callbacks`)

```sql
-- Check idempotency
SELECT id, status, processed_at FROM payment.payment_callbacks
WHERE idempotency_key = '{key_from_failed_callback}';
```

**Re-queue** by dispatching `PaymentCallbackProcessingJob` manually:

```bash
php artisan tinker
>>> App\Modules\Payment\Application\Jobs\PaymentCallbackProcessingJob::dispatch('{callback_id}');
```

Or via the admin API (if endpoint available):

```bash
curl -X POST /api/v1/admin/payment/callbacks/{callback_id}/requeue \
  -H "Authorization: Bearer {admin_token}"
```

---

## Step 4: Bulk Re-queue (use with caution)

Only re-queue in bulk when:
- Root cause is confirmed as transient (e.g., Redis was down for 30 minutes)
- No HMAC or parsing failures in the batch
- Volume is < 1000 callbacks

```bash
php artisan tinker
>>> $ids = DB::connection('payment')
...     ->table('payment.payment_callbacks')
...     ->where('status', 'failed')
...     ->where('provider_code', 'mock_bank')
...     ->pluck('id');
>>> foreach ($ids as $id) {
...     App\Modules\Payment\Application\Jobs\PaymentCallbackProcessingJob::dispatch($id);
... }
```

---

## Step 5: Check Reconciliation Exceptions

Dead-lettered callbacks generate `ReconciliationException(type=missing_callback)`.
After re-queue and processing, check if exceptions were auto-resolved:

```sql
SELECT id, type, status, resolved_at
FROM payment.reconciliation_exceptions
WHERE type = 'missing_callback'
  AND created_at > NOW() - INTERVAL '2 hours';
```

If exceptions remain open after successful re-queue, resolve manually via admin UI.

---

## When to Escalate

- **HMAC failures**: Escalate to payment engineering — indicates potential secret mismatch or provider API change
- **> 100 failed callbacks**: Escalate to on-call engineer
- **Callbacks from unknown provider_code**: Escalate — possible misconfigured provider or attack
- **Callbacks for amount ≠ DB amount**: Escalate to finance team — do NOT re-queue, create reconciliation note
