# Runbook: Payment Provider Secret Rotation

**E48 | T162**
**Last reviewed**: 2026-10-18

---

## Overview

Provider callback secrets (HMAC signing keys) are stored as Vault/Secrets Manager path references in `payment.payment_provider_accounts.callback_secret_reference`. They are **never stored as raw values** in the database or logs.

This runbook covers: rotating a callback HMAC secret without service interruption.

---

## When to Rotate

- Provider instructs you to rotate (scheduled security requirement)
- Suspected secret compromise
- Staff departure with secret access
- Post-incident remediation
- Regular rotation schedule (recommended: every 90 days)

---

## Step 1: Generate New Secret

```bash
# Generate a cryptographically strong secret (32 bytes, base64-encoded)
openssl rand -base64 32
# Example output: xK7JzP+8vQmN1oRaT3sWdYcFhEbGpXiL...
```

Store the new secret in your secrets manager (Vault, AWS Secrets Manager, etc.):

```bash
# Example: HashiCorp Vault
vault kv put secret/payment/providers/mock_bank/callback_secret value="<new_secret>"
# Note the new path/version reference
```

---

## Step 2: Configure Dual-Validation Window (Zero-Downtime Rotation)

Most providers support a transition period where both old and new secrets are accepted.

**If provider supports dual-secret window**:
1. Update provider-side to accept both old and new secret
2. Proceed to Step 3

**If provider does NOT support dual-secret window**:
- Schedule a brief maintenance window
- Notify on-call team before proceeding
- Accept possible `WebhookSignatureException` failures during switch-over (< 30 seconds)

---

## Step 3: Update the Secret Reference in DB

```bash
curl -X PUT /api/v1/admin/payment/providers/{provider_id} \
  -H "Authorization: Bearer {admin_token}" \
  -H "Idempotency-Key: {uuid}" \
  -d '{
    "callback_secret_reference": "secret/payment/providers/mock_bank/callback_secret@v2"
  }'
```

This update triggers the approval workflow (high-risk config change). Requires `payment.config.approve_high_risk` approver.

After approval, the change is audited and applied.

---

## Step 4: Verify HMAC Verification Passes With New Secret

Send a test webhook from the provider (or use the mock bank's checkout endpoint in dev):

```bash
curl -X POST /api/v1/payment/callbacks/mock_bank \
  -H "X-Signature: <hmac_with_new_secret>" \
  -d '{"transaction_ref":"test-001","status":"success","timestamp":1730000000}'
```

Expected: `202 Accepted`

If `400 Bad Request` or HMAC failure logged: Do not proceed — the secret reference path is wrong. Roll back (see Step 6).

---

## Step 5: Remove Old Secret From Vault (After Confirmation)

Wait at least **10 minutes** after successful test to ensure no in-flight callbacks use the old secret.

```bash
# Disable old secret version in Vault (do not delete immediately)
vault kv metadata delete secret/payment/providers/mock_bank/callback_secret@v1
```

---

## Step 6: Rollback Procedure

If the new secret causes verification failures:

1. Revert the `callback_secret_reference` to the previous value via admin API (same high-risk approval workflow)
2. Re-enable old secret version in Vault
3. Clear Redis circuit breaker state if it opened during the failure window:
   ```bash
   redis-cli del "payment.circuit.mock_bank.open"
   redis-cli del "payment.circuit.mock_bank.failures"
   ```
4. Re-queue any dead-lettered callbacks from the failure window (see payment-callback-dead-letter.md)

---

## Verification Checklist

- [ ] New secret stored in Vault with new version reference
- [ ] DB `callback_secret_reference` updated and approved
- [ ] Test callback with new secret returns `202`
- [ ] No `WebhookSignatureException` in logs for 10 minutes post-rotation
- [ ] Old secret version disabled in Vault
- [ ] Audit trail: `payment.provider.config.changed` event visible in audit log
- [ ] No dead-lettered callbacks created during rotation

---

## Related Runbooks

- [Provider Suspension](payment-provider-suspension.md) — if rotation causes extended outage
- [Callback Dead-Letter](payment-callback-dead-letter.md) — if callbacks fail during rotation window
