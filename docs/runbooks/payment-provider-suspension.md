# Runbook: Payment Provider Suspension

**E48 | T159**
**Last reviewed**: 2026-10-18

---

## When to Suspend a Provider

Suspend a provider when:
- The provider is experiencing consistent errors (HMAC failures, 5xx responses, payout failures)
- Fraud or compliance incident originating from a provider
- Scheduled maintenance (provider-initiated or planned)
- Circuit breaker has opened automatically and root cause is not yet resolved
- Regulatory or security instruction to halt a specific provider

Do **not** suspend a provider to resolve an in-progress reconciliation exception — those are resolved through the reconciliation workflow, not suspension.

---

## Effect of Suspension

- **New attempts**: `503 PROVIDER_UNAVAILABLE` returned to callers; no new `PaymentAttempt` records created
- **In-flight payments**: Not affected — existing `PaymentAttempt` records in non-terminal states continue to await callbacks
- **Callback processing**: Continues — `POST /api/v1/payment/callbacks/{providerCode}` is NOT blocked
- **Polling jobs**: Continue — `PaymentAttemptTimeoutJob` runs normally
- **Admin UI**: Provider shown as `SUSPENDED` with suspension timestamp

---

## Steps to Suspend

### 1. Verify the need to suspend

```bash
# Check current circuit breaker state in Redis
redis-cli get "payment.circuit.{provider_code}.open"
redis-cli get "payment.circuit.{provider_code}.failures"

# Check recent callback processing errors in DB
psql -c "SELECT status, COUNT(*) FROM payment.payment_callbacks
          WHERE provider_code = '{provider_code}' AND created_at > NOW() - INTERVAL '1 hour'
          GROUP BY status;"
```

### 2. Suspend via admin API

```bash
curl -X POST /api/v1/admin/payment/providers/{provider_id}/suspend \
  -H "Authorization: Bearer {admin_token}" \
  -H "Idempotency-Key: {uuid}"
```

This sets `is_suspended = true` in `payment.payment_providers` and emits an audit event.

### 3. Verify suspension

```bash
psql -c "SELECT id, code, is_suspended, updated_at FROM payment.payment_providers WHERE id = '{provider_id}';"
```

### 4. Notify relevant teams

- Alert on-call team via incident management channel
- Notify customer-facing teams of potential payment disruption
- If automated circuit-open: include `consecutive_failures` count in notification

### 5. Drain the queue (if callback backlog exists)

```bash
# Check dead-lettered callbacks
psql -c "SELECT COUNT(*) FROM payment.payment_callbacks WHERE status = 'failed' AND provider_code = '{provider_code}';"
# If > 0, see docs/runbooks/payment-callback-dead-letter.md
```

---

## How to Re-enable a Suspended Provider

### 1. Confirm root cause resolved

- Provider support confirms fix is deployed
- HMAC test passes: send a test callback with known signature and verify `202 Accepted`
- OR internal issue resolved (e.g., config fix applied)

### 2. Re-enable via admin API

```bash
curl -X PUT /api/v1/admin/payment/providers/{provider_id} \
  -H "Authorization: Bearer {admin_token}" \
  -H "Idempotency-Key: {uuid}" \
  -d '{"is_suspended": false}'
```

### 3. Clear Redis circuit breaker state (if applicable)

```bash
redis-cli del "payment.circuit.{provider_code}.open"
redis-cli del "payment.circuit.{provider_code}.failures"
```

### 4. Monitor for 15 minutes

Watch for callback receipt rate and `payment_attempts` success rate to normalise.

---

## Escalation

If suspension is required for > 2 hours, escalate to:
- Payment Engineering lead
- CTO (if customer-facing impact > 10% of transaction volume)
