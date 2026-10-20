# Quickstart Validation Guide — E22 Support & Dispute Management

## Prerequisites

1. Docker environment running (from `wallet-ecosystem/`):
   ```
   docker compose up -d
   docker compose exec api php artisan migrate
   docker compose exec api php artisan db:seed --class=IdentityAdminSeeder
   ```
2. Seeded test data: at least one customer, one agent, one admin user, one completed wallet transaction.
3. Admin user credentials for `admin@olekard.local` with roles: `admin` (has `support.*` and `dispute.*` permissions seeded in migration).
4. Customer bearer token from `POST /v1/auth/login`.
5. Admin bearer token from `POST /v1/auth/login`.
6. API accessible on `http://localhost:8000` (default `API_PORT`).

---

## Scenario SC-1: Support Ticket Lifecycle (US-SUPPORT-001 to US-SUPPORT-004)

**Validates:** Create, view, assign, and resolve a support ticket.

### Steps

```bash
# 1. Customer creates a support ticket
curl -s -X POST http://localhost:8000/v1/support/tickets \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"subject":"Transfer not received","description":"I sent funds but recipient did not receive them.","category":"transaction"}' \
  | jq '{id: .data.id, ticket_number: .data.ticket_number, status: .data.status}'
```

**Expected:** `status = "open"`, `ticket_number` starts with `TKT-`.

```bash
# 2. Admin lists all open tickets
curl -s "http://localhost:8000/v1/support/tickets?status=open" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)" \
  | jq '.data.items | length'
```

**Expected:** At least 1 result.

```bash
# 3. Admin assigns ticket to support agent
TICKET_ID="<id from step 1>"
curl -s -X PATCH "http://localhost:8000/v1/support/tickets/$TICKET_ID/assign" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -d "{\"assignee_id\":\"$AGENT_USER_ID\"}" \
  | jq '{status: .data.status, assignee_id: .data.assignee_id}'
```

**Expected:** `status = "in_progress"`, `assignee_id` is set.

```bash
# 4. Admin resolves ticket
curl -s -X PATCH "http://localhost:8000/v1/support/tickets/$TICKET_ID/status" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -d '{"status":"resolved","resolution_note":"Confirmed transaction was delayed; funds arrived after 24h."}' \
  | jq '{status: .data.status, resolved_at: .data.resolved_at}'
```

**Expected:** `status = "resolved"`, `resolved_at` is populated.

---

## Scenario SC-2: Add Internal and Public Notes (US-SUPPORT-005)

```bash
# Support agent adds internal investigation note
curl -s -X POST "http://localhost:8000/v1/support/tickets/$TICKET_ID/notes" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -d '{"content":"Checked ledger — transfer confirmed completed on our end.","is_internal":true}' \
  | jq '{is_internal: .data.is_internal}'
```

**Expected:** `is_internal = true`.

```bash
# Customer fetches ticket — internal note NOT visible
curl -s "http://localhost:8000/v1/support/tickets/$TICKET_ID" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)" \
  | jq '[.data.notes[] | select(.is_internal == true)] | length'
```

**Expected:** `0` — no internal notes visible to customer.

---

## Scenario SC-3: Dispute Lifecycle — Raise through Refund (US-DISPUTE-001 to US-DISPUTE-007)

```bash
# 0. Customer creates a support ticket first (disputes are linked to tickets)
TICKET_RESP=$(curl -s -X POST http://localhost:8000/v1/support/tickets \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"subject":"Unauthorized charge","description":"I did not authorise this transaction.","category":"transaction"}')
DISPUTE_TICKET_ID=$(echo $TICKET_RESP | jq -r '.data.id')

# 1. Customer raises dispute against a completed transaction
curl -s -X POST http://localhost:8000/v1/disputes \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d "{\"ticket_id\":\"$DISPUTE_TICKET_ID\",\"transaction_id\":\"$COMPLETED_TX_ID\"}" \
  | jq '{id: .data.id, dispute_number: .data.dispute_number, status: .data.status}'
```

**Expected:** `status = "raised"`, `dispute_number` starts with `DSP-`.

```bash
DISPUTE_ID="<id from above>"

# 2. Customer selects dispute reason
curl -s -X PATCH "http://localhost:8000/v1/disputes/$DISPUTE_ID/reason" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -d '{"reason":"unauthorized_transaction"}' \
  | jq '.data.reason_code'
```

**Expected:** `"unauthorized_transaction"`.

```bash
# 3. Customer attaches evidence (PNG)
curl -s -X POST "http://localhost:8000/v1/disputes/$DISPUTE_ID/evidence" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -H "Idempotency-Key: $(uuidgen)" \
  -F "file=@/tmp/screenshot.png" \
  | jq '{id: .data.id, file_type: .data.file_type, dispute_status: "evidence_submitted"}'
```

**Expected:** `file_type = "image_png"`; subsequent GET on dispute shows `status = "evidence_submitted"`.

```bash
# 4. Admin starts investigation
curl -s -X PATCH "http://localhost:8000/v1/disputes/$DISPUTE_ID/investigate" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)" \
  | jq '.data.status'
```

**Expected:** `"under_investigation"`.

```bash
# 5. Admin approves dispute
curl -s -X PATCH "http://localhost:8000/v1/disputes/$DISPUTE_ID/approve" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"resolution_note":"Verified transaction was unauthorized. Refund approved."}' \
  | jq '.data.status'
```

**Expected:** `"approved"` (or `"pending_confirmation"` if maker-checker is enabled).

```bash
# 6. Admin executes refund (must supply amount_minor + currency matching the transaction)
REFUND_IDEM="$(uuidgen)"
curl -s -X POST "http://localhost:8000/v1/disputes/$DISPUTE_ID/refund" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -H "Idempotency-Key: $REFUND_IDEM" \
  -d "{\"amount_minor\":$TX_AMOUNT_MINOR,\"currency\":\"$TX_CURRENCY\"}" \
  | jq '{status: .data.status, refunded_amount_minor: .data.refunded_amount_minor}'
```

**Expected:** `status = "refunded"`, `refunded_amount_minor` equals the original transaction amount.

```bash
# 7. Verify idempotency — same Idempotency-Key returns same result, no duplicate
curl -s -X POST "http://localhost:8000/v1/disputes/$DISPUTE_ID/refund" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -H "Idempotency-Key: $REFUND_IDEM" \
  -d "{\"amount_minor\":$TX_AMOUNT_MINOR,\"currency\":\"$TX_CURRENCY\"}" \
  | jq '.data.status'
```

**Expected:** `"refunded"` — idempotent replay; no new ledger entry created.

---

## Scenario SC-4: Dispute Escalation (US-DISPUTE-008)

```bash
# Create a support ticket for this dispute
TICKET2_RESP=$(curl -s -X POST http://localhost:8000/v1/support/tickets \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"subject":"Incorrect fee","description":"Fee was charged incorrectly.","category":"transaction"}')
TICKET2_ID=$(echo $TICKET2_RESP | jq -r '.data.id')

# Create a new dispute and escalate it before investigation
curl -s -X POST http://localhost:8000/v1/disputes \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d "{\"ticket_id\":\"$TICKET2_ID\",\"transaction_id\":\"$ANOTHER_TX_ID\"}" \
  | jq '.data.id' -r > /tmp/dispute2_id.txt

DISPUTE2_ID=$(cat /tmp/dispute2_id.txt)

curl -s -X PATCH "http://localhost:8000/v1/disputes/$DISPUTE2_ID/escalate" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -d '{"escalation_reason":"No response from support in 5 business days."}' \
  | jq '{escalated: .data.escalated, status: .data.status}'
```

**Expected:** `escalated = true`; `status` unchanged from before escalation.

```bash
# Escalating an already-escalated dispute returns 409
curl -s -X PATCH "http://localhost:8000/v1/disputes/$DISPUTE2_ID/escalate" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -d '{"escalation_reason":"Trying again."}' \
  | jq '.error.code'
```

**Expected:** `"DISPUTE_ALREADY_ESCALATED"`.

---

## Scenario SC-5: Authorization Guard Checks

```bash
# Customer cannot assign a ticket (403)
curl -s -X PATCH "http://localhost:8000/v1/support/tickets/$TICKET_ID/assign" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -d '{"assignee_id":"00000000-0000-0000-0000-000000000001"}' \
  | jq '.success'
```

**Expected:** `false` with HTTP 403.

```bash
# Customer cannot approve a dispute (403)
curl -s -X PATCH "http://localhost:8000/v1/disputes/$DISPUTE_ID/approve" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"resolution_note":"Self-approving."}' \
  | jq '.success'
```

**Expected:** `false` with HTTP 403.

```bash
# Second customer cannot view first customer's ticket (403)
curl -s "http://localhost:8000/v1/support/tickets/$TICKET_ID" \
  -H "Authorization: Bearer $OTHER_CUSTOMER_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)" \
  | jq '.success'
```

**Expected:** `false` with HTTP 403.

---

## Scenario SC-6: Evidence Limits

```bash
# Attempting to attach a 6th file returns 422 EVIDENCE_LIMIT_REACHED
for i in 1 2 3 4 5 6; do
  curl -s -X POST "http://localhost:8000/v1/disputes/$DISPUTE_ID/evidence" \
    -H "Authorization: Bearer $CUSTOMER_TOKEN" \
    -H "X-Correlation-ID: $(uuidgen)" \
    -H "Idempotency-Key: $(uuidgen)" \
    -F "file=@/tmp/screenshot.png" \
    | jq "{attempt: $i, code: .error.code}"
done
```

**Expected:** First 5 succeed; 6th returns `"EVIDENCE_LIMIT_REACHED"`.

---

## Verification Checklist

| Scenario | Pass Criteria |
|---|---|
| SC-1 | Ticket lifecycle: open → in_progress → resolved |
| SC-2 | Internal notes hidden from customer |
| SC-3 | Dispute: raised → evidence → investigate → approved → refunded; idempotent refund |
| SC-4 | Escalation sets flag; double-escalation returns 409 |
| SC-5 | 403 on unauthorized cross-reporter access and privileged actions |
| SC-6 | 6th evidence file returns EVIDENCE_LIMIT_REACHED |

---

## Key Artifacts

- OpenAPI: `contracts/support.yaml`
- Data model: `data-model.md`
- Module: `wallet-ecosystem/services/api/app/Modules/Support/`
- Schema: `support` (PostgreSQL)
