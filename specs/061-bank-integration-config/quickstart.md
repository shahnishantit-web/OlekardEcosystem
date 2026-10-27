# Quickstart: P12 — Dynamic Bank Integration Configuration and Certification

**Date**: 2026-10-24
**Prerequisites**: Docker running, `docker compose up -d`, API seeded, admin user `admin@olekard.local` / `Admin123!`

---

## Scenario SC-001: Add a Bank and Complete Full Lifecycle to Active

**Goal**: Verify a bank can be registered, configured, certified, and activated without code changes.

### Steps

```bash
# 1. Authenticate as admin
TOKEN=$(curl -s -X POST http://localhost:8000/v1/admin/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@olekard.local","password":"Admin123!"}' | jq -r '.data.token')

# 2. Create bank
BANK_ID=$(curl -s -X POST http://localhost:8000/v1/admin/payments/banks \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"code":"FNBZ","display_name":"First National Bank","adapter_identifier":"http-redirect-v1","adapter_version":"1.0.0","environment":"sandbox","supported_countries":["BW"]}' \
  | jq -r '.data.id')
echo "Bank ID: $BANK_ID"

# 3. Create integration account
ACCOUNT_ID=$(curl -s -X POST "http://localhost:8000/v1/admin/payments/banks/$BANK_ID/accounts" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"merchant_account_reference":"TEST-MERCHANT-001","environment":"sandbox","adapter_profile_identifier":"http-redirect-v1","supported_currencies":["BWP"],"display_label":"First National Bank (Sandbox)"}' \
  | jq -r '.data.id')
echo "Account ID: $ACCOUNT_ID"

# 4. Configure API operations (initiate + query)
curl -s -X PUT "http://localhost:8000/v1/admin/payments/banks/$BANK_ID/accounts/$ACCOUNT_ID/operations" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "operations": [
      {
        "operation_type": "initiate",
        "base_url": "https://sandbox.fnb.co.bw",
        "endpoint_path": "/api/v1/payments/initiate",
        "http_method": "POST",
        "auth_profile": "bearer_token",
        "secret_key_references": {"api_key": "payments/fnbz/sandbox/api_key"},
        "request_field_mappings": [
          {"source_field": "payment_intent_id", "target_field": "merchant_reference", "transform_type": "literal", "required": true},
          {"source_field": "amount_minor", "target_field": "amount", "transform_type": "amount_minor", "required": true},
          {"source_field": "currency_code", "target_field": "currency", "transform_type": "currency_code", "required": true}
        ],
        "response_field_mappings": [
          {"source_field": "transactionId", "target_field": "provider_reference", "transform_type": "literal", "required": true}
        ],
        "provider_txn_ref_mapping": "data.transactionId",
        "success_status_values": ["COMPLETED"],
        "pending_status_values": ["PENDING","PROCESSING"],
        "failure_status_values": ["FAILED","DECLINED","CANCELLED"],
        "timeout_ms": 30000
      },
      {
        "operation_type": "query",
        "base_url": "https://sandbox.fnb.co.bw",
        "endpoint_path": "/api/v1/payments/status",
        "http_method": "GET",
        "auth_profile": "bearer_token",
        "secret_key_references": {"api_key": "payments/fnbz/sandbox/api_key"},
        "provider_txn_ref_mapping": "data.transactionId",
        "success_status_values": ["COMPLETED"],
        "pending_status_values": ["PENDING","PROCESSING"],
        "failure_status_values": ["FAILED","DECLINED","CANCELLED"],
        "timeout_ms": 15000
      }
    ]
  }' | jq '.data | length'
# Expected: 2 (two operations configured)

# 5. Submit for validation
curl -s -X POST "http://localhost:8000/v1/admin/payments/banks/$BANK_ID/accounts/$ACCOUNT_ID/lifecycle" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"action":"submit_for_validation"}' | jq '.data.new_status'
# Expected: "validated"

# 6. Initiate sandbox testing
curl -s -X POST "http://localhost:8000/v1/admin/payments/banks/$BANK_ID/accounts/$ACCOUNT_ID/lifecycle" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"action":"initiate_sandbox_testing"}' | jq '.data.new_status'
# Expected: "sandbox_testing"

# 7. Upload evidence
curl -s -X POST "http://localhost:8000/v1/admin/payments/banks/$BANK_ID/accounts/$ACCOUNT_ID/evidence" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"evidence_type":"documentation","title":"FNB API Reference v2.1"}' | jq '.data.id'

# 8. Submit evidence
curl -s -X POST "http://localhost:8000/v1/admin/payments/banks/$BANK_ID/accounts/$ACCOUNT_ID/lifecycle" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"action":"submit_evidence"}' | jq '.data.new_status'
# Expected: "evidence_review"

# 9. Approve by second admin (different token/actor)
TOKEN2=$(curl -s -X POST http://localhost:8000/v1/admin/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin2@olekard.local","password":"Admin123!"}' | jq -r '.data.token')

curl -s -X POST "http://localhost:8000/v1/admin/payments/banks/$BANK_ID/accounts/$ACCOUNT_ID/lifecycle" \
  -H "Authorization: Bearer $TOKEN2" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"action":"approve"}' | jq '.data.new_status'
# Expected: "approved"

# 10. Activate
curl -s -X POST "http://localhost:8000/v1/admin/payments/banks/$BANK_ID/accounts/$ACCOUNT_ID/lifecycle" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"action":"activate"}' | jq '.data.new_status'
# Expected: "active"
```

**Pass criteria**: Account reaches `active` status; `active_config_version_id` is non-null; version history shows 1 entry.

---

## Scenario SC-002: SSRF Rejection

**Goal**: Verify non-HTTPS and internal IP base URLs are rejected at save time.

```bash
# Attempt 1: http:// (not HTTPS)
curl -s -X PUT "http://localhost:8000/v1/admin/payments/banks/$BANK_ID/accounts/$ACCOUNT_ID/operations" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"operations":[{"operation_type":"initiate","base_url":"http://fnb.co.bw","endpoint_path":"/pay","http_method":"POST","auth_profile":"bearer_token","success_status_values":["OK"],"pending_status_values":[],"failure_status_values":[]}]}' \
  | jq '.error.code'
# Expected: "validation_failed" (SSRF: scheme must be https)

# Attempt 2: Internal IP
curl -s -X PUT "http://localhost:8000/v1/admin/payments/banks/$BANK_ID/accounts/$ACCOUNT_ID/operations" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"operations":[{"operation_type":"initiate","base_url":"https://192.168.1.100","endpoint_path":"/pay","http_method":"POST","auth_profile":"bearer_token","success_status_values":["OK"],"pending_status_values":[],"failure_status_values":[]}]}' \
  | jq '.error.code'
# Expected: "validation_failed" (SSRF: private IP range blocked)

# Attempt 3: Metadata endpoint
curl -s -X PUT "..." \
  -d '{"operations":[{"operation_type":"initiate","base_url":"https://169.254.169.254","endpoint_path":"/latest/meta-data","http_method":"GET","auth_profile":"api_key","success_status_values":[],"pending_status_values":[],"failure_status_values":[]}]}' \
  | jq '.error.code'
# Expected: "validation_failed" (SSRF: metadata endpoint blocked)
```

**Pass criteria**: All 3 attempts return `validation_failed`; no rows inserted in `api_operation_configs`.

---

## Scenario SC-003: Two-Person Approval — Same Actor Rejected

**Goal**: Verify that the actor who submitted for evidence review cannot approve.

```bash
# Submit evidence (as TOKEN / actor A)
curl -s -X POST ".../$ACCOUNT_ID/lifecycle" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"action":"submit_evidence"}' | jq '.data.new_status'
# → evidence_review

# Attempt approval as same actor (TOKEN)
curl -s -X POST ".../$ACCOUNT_ID/lifecycle" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"action":"approve"}' | jq '.error.code'
# Expected: "approval_conflict" (same actor cannot approve own submission)
```

**Pass criteria**: Approval by same actor returns `409 Conflict` with code `approval_conflict`.

---

## Scenario SC-004: Credentials Never in Response

**Goal**: Verify no raw credential values appear in any API response.

```bash
# Get account detail
RESPONSE=$(curl -s "http://localhost:8000/v1/admin/payments/banks/$BANK_ID/accounts/$ACCOUNT_ID" \
  -H "Authorization: Bearer $TOKEN")

echo $RESPONSE | jq '.data.callback_secret_reference'
# Expected: {"key": "payments/fnbz/sandbox/callback_secret", "masked": "****"}
# NOT: the actual secret value

# Verify no raw secret patterns in response
echo $RESPONSE | grep -E '"sk_|Bearer |password|secret_value"' && echo "FAIL: credential found" || echo "PASS: no raw credentials"
```

**Pass criteria**: `callback_secret_reference` is masked; no raw secret patterns found.

---

## Scenario SC-005: Consumer Payment Options — Dynamic Discovery

**Goal**: Verify active bank appears in mobile options without hard-coded list; disabled bank disappears.

```bash
# Customer auth
CUST_TOKEN=$(curl -s -X POST http://localhost:8000/v1/customer/auth/login \
  -d '{"phone":"+26771000001","pin":"123456"}' | jq -r '.data.token')

# Options request
curl -s "http://localhost:8000/v1/payments/options?currency_code=BWP&amount_minor=10000&purpose_type=merchant_payment&organisation_id=..." \
  -H "Authorization: Bearer $CUST_TOKEN" \
  | jq '.data[] | select(.display_label == "First National Bank (Sandbox)") | .payment_method'
# Expected: "bank_redirect"

# Disable account
curl -s -X POST ".../$ACCOUNT_ID/lifecycle" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"action":"suspend"}' | jq '.data.new_status'
# → suspended

# Options request again — bank should be absent
curl -s "http://localhost:8000/v1/payments/options?currency_code=BWP&amount_minor=10000&purpose_type=merchant_payment&organisation_id=..." \
  -H "Authorization: Bearer $CUST_TOKEN" \
  | jq '.data[] | select(.display_label == "First National Bank (Sandbox)") | .display_label'
# Expected: (empty — no result)
```

**Pass criteria**: Active bank appears; suspended bank absent; no credentials in response.

---

## Scenario SC-006: Two Banks — Same Profile — No Code Change

**Goal**: Demonstrate two banks using `http-redirect-v1` profile can be activated without any code change.

```bash
# Create second bank using same adapter profile
BANK2_ID=$(curl -s -X POST http://localhost:8000/v1/admin/payments/banks \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"code":"STANBZ","display_name":"Standard Bank Botswana","adapter_identifier":"http-redirect-v1","adapter_version":"1.0.0","environment":"sandbox","supported_countries":["BW"]}' \
  | jq -r '.data.id')

# Configure and drive through lifecycle to Active ...
# (same steps as SC-001 with different base_url and credentials)

# Verify both banks appear in payment options
curl -s "http://localhost:8000/v1/payments/options?..." -H "Authorization: Bearer $CUST_TOKEN" \
  | jq '[.data[] | .display_label]'
# Expected: ["First National Bank (Sandbox)", "Standard Bank Botswana (Sandbox)"]
# No payment domain code was modified
```

**Pass criteria**: Both banks appear; git diff shows zero changes to Payment module domain services.

---

## Scenario SC-007: Sandbox Test Does Not Initiate Payment

```bash
curl -s -X POST "http://localhost:8000/v1/admin/payments/banks/$BANK_ID/accounts/$ACCOUNT_ID/sandbox-test" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  | jq '{result: .result, duration_ms: .duration_ms}'
# Expected: result = "pass" or "fail" (test connectivity only; never creates payment_intents or payment_attempts)
```

**Pass criteria**: No new rows in `payment.payment_intents` or `payment.payment_attempts` after test run.

---

## Scenario SC-008: Edit Active Config Creates Draft

```bash
# Account is Active — edit operation config
curl -s -X PUT "http://localhost:8000/v1/admin/payments/banks/$BANK_ID/accounts/$ACCOUNT_ID/operations" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"operations":[...updated config...]}' \
  | jq '{draft_created: .draft_created}'
# Expected: {"draft_created": true}

# Original Active account is still Active
curl -s "http://localhost:8000/v1/admin/payments/banks/$BANK_ID/accounts/$ACCOUNT_ID" \
  -H "Authorization: Bearer $TOKEN" \
  | jq '.data.account_lifecycle_status'
# Expected: "active" (unchanged)
```

**Pass criteria**: `draft_created = true`; original account remains `active`.
