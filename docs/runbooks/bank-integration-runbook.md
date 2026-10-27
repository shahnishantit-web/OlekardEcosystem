# Bank Integration Configuration Runbook

**Feature**: P12 — Dynamic Bank Integration Configuration and Certification
**Scope**: Add, configure, lifecycle-manage, credential-rotate, disable, and retire bank integration accounts.

---

## Prerequisites

- Admin user with `payment.configuration` permission.
- Access to the secrets manager (for key name references only — never raw values).
- Access to the bank's sandbox API documentation and credentials.

---

## 1. Add a Bank

```http
POST /api/v1/admin/payments/banks
Idempotency-Key: <uuid>
Authorization: Bearer <admin-token>

{
  "code": "FNBZ",
  "display_name": "FNB Botswana",
  "environment": "sandbox",
  "supported_countries": ["BW"],
  "description": "First National Bank Botswana — sandbox configuration"
}
```

**Expected**: `201 Created` with bank object. `bank_lifecycle_status = "draft"`.

---

## 2. Add an Integration Account

```http
POST /api/v1/admin/payments/banks/{bankId}/accounts
Idempotency-Key: <uuid>

{
  "merchant_account_reference": "OLEKARD_SANDBOX_001",
  "supported_currencies": ["BWP"],
  "supported_payment_methods": ["bank_redirect"],
  "callback_secret_reference": "payments/fnbz/sandbox/callback_secret",
  "display_label": "Pay via FNB"
}
```

**Expected**: `201 Created`. `account_lifecycle_status = "draft"`.

---

## 3. Configure API Operations

```http
PUT /api/v1/admin/payments/banks/{bankId}/accounts/{accountId}/operations
Idempotency-Key: <uuid>

{
  "operations": [
    {
      "operation_type": "initiate",
      "base_url": "https://api.fnb.co.bw",
      "endpoint_path": "/v1/payments",
      "http_method": "POST",
      "timeout_ms": 30000,
      "secret_key_references": {
        "api_key": "payments/fnbz/sandbox/api_key"
      },
      "success_status_values": ["COMPLETED", "APPROVED"],
      "failure_status_values": ["DECLINED", "FAILED"]
    },
    {
      "operation_type": "query",
      "base_url": "https://api.fnb.co.bw",
      "endpoint_path": "/v1/payments/{reference}",
      "http_method": "GET",
      "timeout_ms": 15000
    }
  ]
}
```

**Security**: Only HTTPS URLs accepted. Non-HTTPS → `422 ssrf_violation`. Payout operations → `422 payout_not_allowed`.

---

## 4. Drive Account Through Lifecycle

### Submit for Validation
```http
POST /api/v1/admin/payments/banks/{bankId}/accounts/{accountId}/lifecycle
Idempotency-Key: <uuid>
{ "action": "submit_for_validation" }
```

### Start Sandbox Testing
```http
POST .../lifecycle
{ "action": "initiate_sandbox_testing" }
```

### Run Sandbox Test (SC-007: no payment rows created)
```http
POST /api/v1/admin/payments/banks/{bankId}/accounts/{accountId}/sandbox-test
Idempotency-Key: <uuid>
{}
```
Returns `{ "result": "pass|fail", "duration_ms": <int>, "evidence_id": "..." }`.

### Upload Required Evidence
```http
POST /api/v1/admin/payments/banks/{bankId}/accounts/{accountId}/evidence
Idempotency-Key: <uuid>
{
  "evidence_type": "documentation",
  "description": "FNB API v3.2 documentation reviewed",
  "reference_document_key": "evidence/fnbz/api-docs-v3.2.pdf"
}
```
Repeat for `merchant_ownership`.

### Submit Evidence for Review
```http
POST .../lifecycle
{ "action": "submit_evidence" }
```
**Gate**: Requires at least one `documentation` and one `merchant_ownership` evidence record.

### Two-Person Approval (FR-013)
```http
POST .../lifecycle
Authorization: Bearer <different-admin-token>
{ "action": "approve" }
```
**Critical**: Approver must NOT be the same actor who submitted. Returns `409 approval_conflict` if violated.

### Activate
```http
POST .../lifecycle
Idempotency-Key: <uuid>
{ "action": "activate" }
```
Creates a `ConfigurationVersion` snapshot. Sets `active_config_version_id`.

---

## 5. Credential Rotation

```http
POST /api/v1/admin/payments/banks/{bankId}/accounts/{accountId}/rotate-credential
Idempotency-Key: <uuid>

{
  "secret_role": "api_key",
  "new_key_name": "payments/fnbz/sandbox/api_key_v2",
  "reason": "Scheduled quarterly rotation"
}
```

**Returns**: Rotation log metadata only — no key names or raw values in response.

**FR-024 in-flight safety**: In-flight payment attempts retain the key that was active at initiation, resolved via `credential_rotation_log.effective_from`.

---

## 6. Disable (Suspend) an Account

```http
POST .../lifecycle
{ "action": "suspend" }
```

- Blocks **new** payment intents.
- Existing attempts (callbacks, refunds) continue processing.
- Disable takes effect within 5 seconds (SC-003).

---

## 7. Retire an Account

```http
POST .../lifecycle
{
  "action": "retire",
  "resolution_route_recorded": true
}
```

**Gate**: `resolution_route_recorded = true` required if there are non-terminal payment attempts. Without it: `409 outstanding_obligations`.

---

## 8. Common Errors

| HTTP | Code | Cause |
|------|------|-------|
| 422 | `ssrf_violation` | Non-HTTPS URL or private IP range |
| 422 | `payout_not_allowed` | Attempt to configure payout operation |
| 409 | `approval_conflict` | Approver is the same actor as submitter |
| 409 | `invalid_lifecycle_transition` | Transition not allowed from current status |
| 409 | `outstanding_obligations` | Retire blocked by non-terminal attempts |
| 409 | `missing_required_evidence` | Submit evidence without required evidence records |

---

## Performance Acceptance Checks

Manual verification steps per success criteria:

| SC | Target | Check |
|----|--------|-------|
| SC-001 | Full lifecycle < 30 min | Time a complete Add Bank → Activate flow as new operator using only the UI |
| SC-003 | Disable takes effect within 5 s | Issue suspend API call; POST new intent immediately; confirm 422 within 5 s |
| SC-007 | Sandbox test completes < 10 s | Check `duration_ms` in sandbox test response; must be < 10000 |
| SC-008 | Audit log entry within 1 s | After any config change, query `/api/v1/admin/audit`; entry must appear immediately |

---

## Recovery Procedures

### Rollback Configuration Change
If a newly activated configuration causes failures, the previous `ConfigurationVersion` is not automatically reverted. Recovery path:
1. Suspend the account: `POST .../lifecycle { "action": "suspend" }`.
2. Existing attempts continue processing via their committed `configuration_version_id`.
3. Create a new Draft, correct the configuration, and go through the full lifecycle again.

### Key Rotation Recovery
If a rotated key causes failures:
1. Rotate back: `POST .../rotate-credential` with the previous key name.
2. In-flight attempts resolve via `credential_rotation_log` using their initiation timestamp.

### Locked Account (Approval Conflict)
If all available approvers are the submitter:
1. Assign a second admin with `payment.configuration` permission.
2. Have the second admin approve.
