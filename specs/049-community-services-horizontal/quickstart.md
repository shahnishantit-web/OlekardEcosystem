# Quickstart Validation Guide: E49 — Community Services Horizontal Foundation

**Purpose**: Prove the horizontal foundation works end-to-end before E50/E51/E52 proceed.
**Environment**: Local Docker (existing `wallet-ecosystem` docker-compose stack).
**Prerequisites**: The existing OleKard API stack must be running (`docker compose up` from `wallet-ecosystem/`).

---

## Setup

```bash
# From wallet-ecosystem/services/api/
php artisan migrate --path=database/migrations/community_services
php artisan db:seed --class=CommunityServicesSeeder
```

Expected: no migration errors; seeder creates two test organisations (one approved, one pending) and two staff roles.

---

## Scenario SC-01 — Tenant Isolation (AT01)

**Goal**: Confirm an Organiser Admin of Organisation A cannot read Organisation B's data.

```bash
# 1. Login as Organiser Admin of Organisation A
TOKEN_A=$(curl -s -X POST /v1/auth/login -d '{"email":"org-a-admin@test.local","password":"Test123!"}' | jq -r .data.token)

# 2. Get Organisation B's ID from seeder output (ORG_B_ID)
# 3. Attempt to access Organisation B
curl -s -H "Authorization: Bearer $TOKEN_A" /v1/community/organisations/$ORG_B_ID
```

**Expected**: HTTP 403 with error code `insufficient_scope`. Response contains no data from Organisation B.

---

## Scenario SC-02 — Gate Operator Role Isolation (AT01)

**Goal**: A Gate Operator can validate tickets but cannot access any finance endpoint.

```bash
GATE_TOKEN=$(curl -s -X POST /v1/auth/login -d '{"email":"gate-op@test.local","password":"Test123!"}' | jq -r .data.token)

# Attempt finance endpoint
curl -s -H "Authorization: Bearer $GATE_TOKEN" /v1/community/admin/orders
curl -s -H "Authorization: Bearer $GATE_TOKEN" /v1/community/admin/refunds
```

**Expected**: Both return HTTP 403. No financial data returned.

---

## Scenario SC-03 — Order Idempotency (AT02)

**Goal**: Submitting the same create-order request 5 times concurrently produces exactly one order and one inventory hold.

```bash
# Run 5 concurrent requests with the same Idempotency-Key
CONSUMER_TOKEN=$(curl -s -X POST /v1/auth/login -d '{"email":"consumer1@test.local","password":"Test123!"}' | jq -r .data.token)
IDEM_KEY="test-idem-$(uuidgen)"

for i in {1..5}; do
  curl -s -X POST /v1/community/orders \
    -H "Authorization: Bearer $CONSUMER_TOKEN" \
    -H "Idempotency-Key: $IDEM_KEY" \
    -H "Content-Type: application/json" \
    -d '{"organisation_id":"'$ORG_A_ID'","purpose_type":"event_ticket","purpose_id":"'$EVENT_ID'","currency":"BWP","lines":[{"item_ref":"cat-general","description":"General Admission","quantity":1,"unit_price":10000}]}' &
done
wait
```

**Expected**: All 5 responses return the same order ID and same status. Database contains exactly one `community_services.orders` row for this idempotency key.

```sql
SELECT COUNT(*) FROM community_services.idempotency_records WHERE idempotency_key = '<IDEM_KEY>';
-- Expected: 1
SELECT COUNT(*) FROM community_services.orders WHERE idempotency_key = '<IDEM_KEY>';
-- Expected: 1
```

---

## Scenario SC-04 — Payment Callback Deduplication (AT02)

**Goal**: The same provider success callback delivered twice produces exactly one ledger posting and one receipt.

```bash
# Simulate payment confirmation via internal test endpoint
curl -X POST /v1/internal/test/payment-callback \
  -H "X-Internal-Test: true" \
  -d '{"order_id":"'$ORDER_ID'","event":"payment.success","provider_ref":"prov-001","amount":10000,"currency":"BWP"}'

# Send again
curl -X POST /v1/internal/test/payment-callback \
  -H "X-Internal-Test: true" \
  -d '{"order_id":"'$ORDER_ID'","event":"payment.success","provider_ref":"prov-001","amount":10000,"currency":"BWP"}'
```

**Expected**: Exactly one `community_services.receipts` row; exactly one `community_services.domain_events` row of type `Payment.Confirmed`; exactly one ledger posting.

```sql
SELECT COUNT(*) FROM community_services.receipts WHERE order_id = '<ORDER_ID>';
-- Expected: 1
SELECT COUNT(*) FROM community_services.domain_events WHERE aggregate_id = '<ORDER_ID>' AND event_type = 'Payment.Confirmed';
-- Expected: 1
```

---

## Scenario SC-05 — Refund Race Condition (AT03)

**Goal**: Two concurrent refund requests for the same order totalling more than the captured amount do not over-refund.

```bash
# Order has captured BWP 100.00 (10000 thebe)
# Each refund requests BWP 80.00 (8000 thebe) — total would be 16000 > 10000

for i in {1..2}; do
  curl -s -X POST /v1/community/orders/$ORDER_ID/refunds \
    -H "Authorization: Bearer $FINANCE_TOKEN" \
    -H "Idempotency-Key: refund-race-$i" \
    -H "Content-Type: application/json" \
    -d '{"amount":8000,"currency":"BWP","reason":"test race"}' &
done
wait
```

**Expected**: Exactly one refund request succeeds (HTTP 201); the second returns HTTP 409. Total approved refund amount ≤ 10000 thebe.

```sql
SELECT SUM(amount) FROM community_services.refund_requests 
WHERE order_id = '<ORDER_ID>' AND status NOT IN ('rejected', 'failed');
-- Expected: <= 10000
```

---

## Scenario SC-06 — Maker-Checker Self-Approval Rejection (AT07)

**Goal**: A Finance Approver cannot approve a refund request they submitted.

```bash
# Finance Approver submits a refund
REFUND_ID=$(curl -s -X POST /v1/community/orders/$ORDER_ID/refunds \
  -H "Authorization: Bearer $FINANCE_TOKEN" \
  -H "Idempotency-Key: mc-test-$(uuidgen)" \
  -d '{"amount":5000,"currency":"BWP","reason":"maker-checker test"}' | jq -r .data.id)

# Same user tries to approve their own refund
curl -s -X POST /v1/community/admin/refunds/$REFUND_ID/approve \
  -H "Authorization: Bearer $FINANCE_TOKEN" \
  -H "Idempotency-Key: mc-approve-$(uuidgen)"
```

**Expected**: HTTP 403 with error code `self_approval_prohibited`.

---

## Scenario SC-07 — Invitation Expiry (HZ03)

**Goal**: An expired invitation fails safely; accepted invitations cannot be reused.

```bash
# Create an invitation with a 1-second expiry (test override)
INV_TOKEN=$(curl -s -X POST /v1/community/organisations/$ORG_A_ID/staff \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -d '{"intended_email":"newstaff@test.local","role":"gate_operator"}' | jq -r .data.token)

sleep 2  # Wait for expiry

# Try to accept the expired invitation
curl -s -X POST /v1/community/invitations/$INV_TOKEN/accept \
  -H "Authorization: Bearer $NEW_USER_TOKEN"
```

**Expected**: HTTP 404 with error code `invitation_expired`.

---

## Scenario SC-08 — Marketing Consent Withdrawal (HZ04)

**Goal**: Withdrawing marketing consent stops future marketing notification jobs.

```bash
# Withdraw marketing consent
curl -s -X POST /v1/community/consent \
  -H "Authorization: Bearer $CONSUMER_TOKEN" \
  -d '{"purpose":"marketing","notice_id":"'$NOTICE_ID'","action":"withdraw"}'

# Check notification job filter
php artisan tinker --execute="
  echo App\Modules\CommunityServices\Consent\Application\ConsentService::hasConsent(
    '$CONSUMER_UUID', 'marketing'
  ) ? 'GRANTED' : 'WITHDRAWN';
"
```

**Expected**: Returns `WITHDRAWN`. Marketing notification jobs dispatched after this point must exclude this consumer.

---

## Scenario SC-09 — Webhook Signature Verification (HZ12)

**Goal**: An unsigned or tampered webhook payload is rejected before any processing.

```bash
# Send webhook with invalid signature
curl -s -X POST /v1/community/webhooks/btc \
  -H "Content-Type: application/json" \
  -H "X-Partner-Signature: invalid-sig" \
  -H "X-Partner-Timestamp: $(date +%s)" \
  -d '{"event":"payment.success","id":"evt-001"}'
```

**Expected**: HTTP 400; no `community_services.webhook_receipts` row created; no domain processing initiated.

```sql
SELECT COUNT(*) FROM community_services.webhook_receipts WHERE processing_status = 'received';
-- Expected: 0 (no receipt written for invalid signature)
```

---

## Scenario SC-10 — CSV Export Formula Injection Prevention (HZ10)

**Goal**: A receipt with a description starting with `=` is safely escaped in the CSV export.

```bash
# Create an order with a potentially malicious item description
# Then export and inspect the CSV
curl -s -X POST /v1/community/admin/receipts/export \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -d '{"organisation_id":"'$ORG_A_ID'","from_date":"2026-10-01","to_date":"2026-10-31"}' \
  | jq -r .data.job_id

# Wait for job, then download and check
# ...
```

**Expected**: Any cell in the CSV whose original value begins with `=`, `+`, `-` or `@` is prefixed with a tab character or apostrophe to neutralise formula injection. This is validated by the automated test suite.

---

## Scenario SC-11 — Revoked Staff Loses Access (HZ01)

**Goal**: Revoking a staff membership invalidates their cached session.

```bash
# Revoke the staff membership
curl -s -X PATCH /v1/community/organisations/$ORG_A_ID/staff/$MEMBERSHIP_ID \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -d '{"status":"revoked"}'

# Staff member tries to use their existing token
curl -s -H "Authorization: Bearer $STAFF_TOKEN" /v1/community/organisations/$ORG_A_ID/staff
```

**Expected**: HTTP 403 with `membership_revoked` code. (Token revocation propagation follows the existing E01 session revocation contract.)

---

## Scenario SC-12 — Organisation Suspension Preserves History (HZ02)

**Goal**: Suspending an organisation blocks new purchases but preserves receipt access.

```bash
# Suspend organisation
curl -s -X POST /v1/community/organisations/$ORG_A_ID/status \
  -H "Authorization: Bearer $PLATFORM_ADMIN_TOKEN" \
  -d '{"to_status":"suspended","reason":"test suspension"}'

# Consumer attempts a new order
curl -s -X POST /v1/community/orders \
  -H "Authorization: Bearer $CONSUMER_TOKEN" \
  -H "Idempotency-Key: suspended-order-$(uuidgen)" \
  -d '{"organisation_id":"'$ORG_A_ID'",...}'

# Consumer accesses existing receipt
curl -s -H "Authorization: Bearer $CONSUMER_TOKEN" /v1/community/receipts/$RECEIPT_REF
```

**Expected**: New order returns HTTP 422 with `organisation_suspended`; existing receipt returns HTTP 200.

---

## PHPStan Validation

```bash
# From wallet-ecosystem/services/api/
./vendor/bin/phpstan analyse app/Modules/CommunityServices --level=8
```

**Expected**: 0 errors.

---

## Admin UI Smoke Test (HZ02 + HZ03)

1. Navigate to `/community-services/organisations` in the admin panel.
2. Create a new organisation. Confirm it appears in `draft` status.
3. Transition it to `submitted`, then `approved`. Confirm status history is shown.
4. Invite a staff member as `gate_operator`. Confirm invitation appears in pending state.
5. Confirm the approved organisation is visible to a consumer via the discovery API.

**Expected**: All state transitions reflected immediately; status history accurate; invitation token delivered (checked via email log or test mailbox).

---

## Flutter Consumer Smoke Test (HZ14 Navigation)

1. Launch the customer app in an emulator.
2. Confirm bottom navigation shows: Home, Community, Events, My Tickets, Activity, More.
3. Tap Community — confirm the community membership list screen loads.
4. Switch to a different user account — confirm previous user's community data is cleared.

**Expected**: All 6 navigation tabs present; screen reader labels present on each tab; user switch clears private cached data.

---

## Artefact References

- Data model: [data-model.md](./data-model.md)
- API contracts: [contracts/](./contracts/)
- Spec: [spec.md](./spec.md)
- Plan: [plan.md](./plan.md)
