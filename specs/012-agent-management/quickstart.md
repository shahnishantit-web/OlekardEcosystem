# Quickstart Validation Guide — E12 Agent Management

## Prerequisites

```bash
# From wallet-ecosystem/services/api
php artisan migrate --path=app/Modules/Agent/Infrastructure/Migrations
php artisan db:seed --class=AgentPermissionSeeder  # covered by migration 001600

# Seed a test admin and agent applicant user
# Admin: use existing seeded admin@olekard.local / Admin123! (E01/E02)
# Obtain bearer tokens via POST /api/v1/auth/login

ADMIN_TOKEN="<admin JWT>"
AGENT_TOKEN="<applicant JWT>"
AGENT_USER_ID="<applicant user UUID>"
BASE="http://localhost:8080/api/v1"
```

---

## Scenario 1 — Complete 5-Step Agent Onboarding (US-AGENT-001 through 005)

```bash
# Step 1: Create application
APP=$(curl -s -X POST "$BASE/agent-applications" \
  -H "Authorization: Bearer $AGENT_TOKEN" \
  -H "Idempotency-Key: test-$(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"agent_type":"individual"}')
APP_ID=$(echo $APP | jq -r '.data.id')
echo "Application ID: $APP_ID"
# Expect: status=draft, 201

# Step 2: Personal information
curl -s -X PATCH "$BASE/agent-applications/$APP_ID/personal-information" \
  -H "Authorization: Bearer $AGENT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"first_name":"John","last_name":"Doe","national_id_number":"123456789","national_id_type":"national_id","date_of_birth":"1985-06-15","phone":"+26771234567","address":{"street":"123 Main St","city":"Gaborone","country":"BW"}}'
# Expect: status=in_progress, 200

# Step 3: Business information (optional for individual, skip or send minimal)
curl -s -X PATCH "$BASE/agent-applications/$APP_ID/business-information" \
  -H "Authorization: Bearer $AGENT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}'
# Expect: 200 (no required fields for individual)

# Step 4: Upload document
curl -s -X POST "$BASE/agent-applications/$APP_ID/documents" \
  -H "Authorization: Bearer $AGENT_TOKEN" \
  -H "Idempotency-Key: doc-$(uuidgen)" \
  -F "document_type=national_id" \
  -F "file=@/tmp/test_id.pdf"
# Expect: status=pending_review, 201

# Step 5: Trigger KYC
curl -s -X POST "$BASE/agent-applications/$APP_ID/kyc" \
  -H "Authorization: Bearer $AGENT_TOKEN" \
  -H "Idempotency-Key: kyc-$(uuidgen)"
# Expect: status=kyc_in_progress, 202

# DB verification
psql $DATABASE_URL -c "SELECT id, status, kyc_provider_reference FROM agent.agent_applications WHERE id = '$APP_ID';"
```

**Expected state**: `agent_applications.status = 'kyc_in_progress'`

---

## Scenario 2 — Admin Approve Application (US-AGENT-006)

```bash
# Simulate KYC callback (use stub: manually set status)
psql $DATABASE_URL -c "UPDATE agent.agent_applications SET status='kyc_passed', kyc_result='passed' WHERE id='$APP_ID';"

# Admin approves
AGENT=$(curl -s -X POST "$BASE/agent-applications/$APP_ID/approve" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: approve-$(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"notes":"All documents verified."}')
AGENT_ID=$(echo $AGENT | jq -r '.data.id')
echo "Agent ID: $AGENT_ID"
# Expect: 200, Agent.status=active

# DB verification
psql $DATABASE_URL -c "SELECT id, status, approved_by, approved_at FROM agent.agents WHERE application_id='$APP_ID';"
psql $DATABASE_URL -c "SELECT * FROM agent.agent_status_history WHERE agent_id='$AGENT_ID';"
psql $DATABASE_URL -c "SELECT * FROM platform.outbox_events WHERE payload->>'agent_id'='$AGENT_ID' ORDER BY created_at DESC LIMIT 5;"
# Expect: outbox has agent.approved event
```

**Expected state**: Agent row with `status=active`; `agent.approved` in outbox; `agent` role in `identity.user_roles` for the user.

---

## Scenario 3 — Maker-Checker Violation (FR-E12-009)

```bash
# Admin B sets status to kyc_passed and last_updated_by = ADMIN_B_ID
# Then Admin B attempts to approve — should fail if maker-checker enabled
psql $DATABASE_URL -c "UPDATE agent.agent_applications SET status='kyc_passed', last_updated_by='$ADMIN_B_ID' WHERE id='$APP_ID';"

curl -s -X POST "$BASE/agent-applications/$APP_ID/approve" \
  -H "Authorization: Bearer $ADMIN_B_TOKEN" \
  -H "Idempotency-Key: maker-check-$(uuidgen)" \
  -d '{}'
# Expect: 422 MAKER_CHECKER_VIOLATION
```

---

## Scenario 4 — Reject Application (US-AGENT-007)

```bash
# Reset application to kyc_passed for rejection test
psql $DATABASE_URL -c "UPDATE agent.agent_applications SET status='kyc_passed', last_updated_by=NULL WHERE id='$APP_ID';"

curl -s -X POST "$BASE/agent-applications/$APP_ID/reject" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: reject-$(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"reason":"Submitted documents are expired and cannot be verified."}'
# Expect: 200, application.status=rejected

psql $DATABASE_URL -c "SELECT status, rejection_reason, rejected_at FROM agent.agent_applications WHERE id='$APP_ID';"
```

---

## Scenario 5 — Suspend and Reactivate Agent (US-AGENT-008/009)

```bash
# Suspend active agent
curl -s -X POST "$BASE/agents/$AGENT_ID/suspend" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: suspend-$(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"reason":"Suspected fraudulent activity. Pending investigation."}'
# Expect: 200, Agent.status=suspended

# Verify agent.suspended event in outbox (E03 will consume to revoke sessions)
psql $DATABASE_URL -c "SELECT event_name, payload->>'user_id' FROM platform.outbox_events WHERE event_name='agent.suspended' ORDER BY created_at DESC LIMIT 1;"

# Suspended agent attempts customer search — should fail
curl -s -X GET "$BASE/agents/$AGENT_ID/customers?search=John" \
  -H "Authorization: Bearer $AGENT_TOKEN"
# Expect: 403 AGENT_SUSPENDED

# Reactivate
curl -s -X POST "$BASE/agents/$AGENT_ID/reactivate" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: reactivate-$(uuidgen)"
# Expect: 200, Agent.status=active

psql $DATABASE_URL -c "SELECT previous_status, new_status, actor_type, occurred_at FROM agent.agent_status_history WHERE agent_id='$AGENT_ID' ORDER BY occurred_at DESC;"
```

---

## Scenario 6 — Agent Registers Customer (US-AGENT-011)

```bash
# Register a new customer via agent
CUSTOMER=$(curl -s -X POST "$BASE/agents/$AGENT_ID/customers" \
  -H "Authorization: Bearer $AGENT_TOKEN" \
  -H "Idempotency-Key: reg-$(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"phone":"+26772000001","first_name":"Jane","last_name":"Smith","id_type":"national_id","id_number":"987654321"}')
CUSTOMER_ID=$(echo $CUSTOMER | jq -r '.data.id')
echo "Customer ID: $CUSTOMER_ID"
# Expect: 201, customer created and linked

# Verify relationship
psql $DATABASE_URL -c "SELECT * FROM agent.agent_customer_relationships WHERE agent_id='$AGENT_ID' AND customer_id='$CUSTOMER_ID';"
# Expect: one row with registered_by = AGENT_ID

# Idempotency: duplicate request
curl -s -X POST "$BASE/agents/$AGENT_ID/customers" \
  -H "Authorization: Bearer $AGENT_TOKEN" \
  -H "Idempotency-Key: reg-same-key" \  # same key as above
  -H "Content-Type: application/json" \
  -d '{"phone":"+26772000001","first_name":"Jane","last_name":"Smith","id_type":"national_id","id_number":"987654321"}'
# Expect: 200 (replay), no duplicate customer created
```

---

## Scenario 7 — IDOR / Unauthorized Customer Access (SC-E12-004)

```bash
# Agent B (different agent) attempts to access Agent A's customer
AGENT_B_TOKEN="<second agent JWT>"
AGENT_B_ID="<second agent UUID>"

# AGENT_B tries to access CUSTOMER_ID registered to AGENT_A
curl -s -X GET "$BASE/agents/$AGENT_B_ID/customers/$CUSTOMER_ID/wallet" \
  -H "Authorization: Bearer $AGENT_B_TOKEN"
# Expect: 403 UNAUTHORIZED_CUSTOMER_ACCESS — never 404

curl -s -X GET "$BASE/agents/$AGENT_B_ID/customers/$CUSTOMER_ID/transactions" \
  -H "Authorization: Bearer $AGENT_B_TOKEN"
# Expect: 403 UNAUTHORIZED_CUSTOMER_ACCESS

# Verify security audit event in outbox
psql $DATABASE_URL -c "SELECT event_name, payload FROM platform.outbox_events WHERE event_name='security.unauthorized_access_attempt' ORDER BY created_at DESC LIMIT 2;"
# Expect: 2 events with agent_id=AGENT_B_ID and target_customer_id=CUSTOMER_ID
```

---

## Scenario 8 — Document Virus Scan Quarantine (FR-E12-024)

```bash
# Configure stub to simulate virus detection
# In .env: AGENT_VIRUS_SCAN_STUB_RESULT=infected

curl -s -X POST "$BASE/agent-applications/$APP_ID/documents" \
  -H "Authorization: Bearer $AGENT_TOKEN" \
  -H "Idempotency-Key: virus-doc-$(uuidgen)" \
  -F "document_type=national_id" \
  -F "file=@/tmp/test_infected.pdf"
# Expect: 422 DOCUMENT_VIRUS_DETECTED

# Verify quarantined record (no storage_reference)
psql $DATABASE_URL -c "SELECT id, status, storage_reference, rejection_reason FROM agent.agent_documents WHERE application_id='$APP_ID' ORDER BY uploaded_at DESC LIMIT 1;"
# Expect: status=quarantined, storage_reference=NULL, rejection_reason=VIRUS_SCAN_FAILED

# Verify audit event
psql $DATABASE_URL -c "SELECT event_name, payload->>'rejection_reason', payload->>'actor_type' FROM platform.outbox_events WHERE event_name='agent.application.document_rejected' ORDER BY created_at DESC LIMIT 1;"
# Expect: rejection_reason=VIRUS_SCAN_FAILED, actor_type=system
```

---

## Scenario 9 — Concurrent Approval (CC-E12-001)

```bash
# Two concurrent requests to approve the same application
# Use GNU parallel or two terminals

psql $DATABASE_URL -c "UPDATE agent.agent_applications SET status='kyc_passed', last_updated_by=NULL WHERE id='$APP_ID';"

# Terminal 1:
curl -s -X POST "$BASE/agent-applications/$APP_ID/approve" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: concurrent-approve-1" &

# Terminal 2 (different idempotency key):
curl -s -X POST "$BASE/agent-applications/$APP_ID/approve" \
  -H "Authorization: Bearer $ADMIN_TOKEN_2" \
  -H "Idempotency-Key: concurrent-approve-2" &

wait
# Expect: exactly one 200 (Agent created); the other returns 422 APPLICATION_NOT_APPROVABLE
# Verify: only one Agent row exists for this application
psql $DATABASE_URL -c "SELECT COUNT(*) FROM agent.agents WHERE application_id='$APP_ID';"
# Expect: count = 1
```

---

## Scenario 10 — Agent Customer Wallet View (US-AGENT-013)

```bash
curl -s -X GET "$BASE/agents/$AGENT_ID/customers/$CUSTOMER_ID/wallet" \
  -H "Authorization: Bearer $AGENT_TOKEN"
# Expect: 200 with wallet_id, status, tier, is_frozen; balance_disclosure=null (TBD-E12-001)

# Verify audit event
psql $DATABASE_URL -c "SELECT event_name FROM platform.outbox_events WHERE event_name='agent.customer.wallet_viewed' ORDER BY created_at DESC LIMIT 1;"
```
