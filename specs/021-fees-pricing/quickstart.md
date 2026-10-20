# Quickstart Validation Guide — E21 Fees & Pricing

## Prerequisites

```bash
# Docker stack running
docker compose up -d

# API base URL
API=http://localhost:8080

# Obtain admin JWT
TOKEN=$(curl -s -X POST $API/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@olekard.local","password":"Admin123!"}' \
  | jq -r '.data.access_token')
```

---

## SC-1 — Create a global percentage fee rule

**Story**: US-FEE-001, US-FEE-002

```bash
curl -s -X POST $API/v1/fees/rules \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{
    "transaction_type": "transfer",
    "fee_type": "percentage",
    "rate": 0.5,
    "currency": "BWP",
    "subject_type": "global",
    "valid_from": "2026-01-01T00:00:00Z"
  }' | jq '.data.status'
```

**Expected**: `"active"`

---

## SC-2 — Create a global fixed fee rule

**Story**: US-FEE-001, US-FEE-003

```bash
curl -s -X POST $API/v1/fees/rules \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{
    "transaction_type": "bill_payment",
    "fee_type": "fixed",
    "amount_minor": 150,
    "currency": "BWP",
    "subject_type": "global",
    "valid_from": "2026-01-01T00:00:00Z"
  }' | jq '.data.id'
```

**Expected**: non-null UUID

---

## SC-3 — Conflict on duplicate active global rule

**Story**: US-FEE-001 scenario 3

```bash
# Create second transfer percentage rule (same transaction_type, currency, subject_type)
curl -s -o /dev/null -w "%{http_code}" -X POST $API/v1/fees/rules \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{
    "transaction_type": "transfer",
    "fee_type": "percentage",
    "rate": 1.0,
    "currency": "BWP",
    "subject_type": "global",
    "valid_from": "2026-01-01T00:00:00Z"
  }'
```

**Expected**: `409`

---

## SC-4 — Add min/max bounds to percentage rule

**Story**: US-FEE-004, US-FEE-005

```bash
# First, get the transfer rule ID from SC-1
RULE_ID=$(curl -s "$API/v1/fees/rules?transaction_type=transfer&subject_type=global" \
  -H "Authorization: Bearer $TOKEN" | jq -r '.data[0].id')

curl -s -X PATCH "$API/v1/fees/rules/$RULE_ID" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"min_fee_minor": 100, "max_fee_minor": 5000}' \
  | jq '{min: .data.min_fee_minor, max: .data.max_fee_minor}'
```

**Expected**: `{"min": 100, "max": 5000}`

---

## SC-5 — Fee preview: percentage with min floor applied

**Story**: US-FEE-008 scenario 2

```bash
# Transfer BWP 100 (0.5% = 50 minor units) → min floor 100 applies
curl -s -X POST $API/v1/fees/calculate \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "transaction_type": "transfer",
    "amount_minor": 10000,
    "currency": "BWP",
    "subject_type": "global"
  }' | jq '{fee: .data.fee_amount_minor, min_applied: .data.min_fee_applied}'
```

**Expected**: `{"fee": 100, "min_applied": true}`

---

## SC-6 — Fee preview: percentage with max ceiling applied

**Story**: US-FEE-008 scenario 1 (large amount)

```bash
# Transfer BWP 50,000 (0.5% = 25,000 minor units) → max ceiling 5000 applies
curl -s -X POST $API/v1/fees/calculate \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "transaction_type": "transfer",
    "amount_minor": 5000000,
    "currency": "BWP",
    "subject_type": "global"
  }' | jq '{fee: .data.fee_amount_minor, max_applied: .data.max_fee_applied}'
```

**Expected**: `{"fee": 5000, "max_applied": true}`

---

## SC-7 — Fee preview: no applicable rule returns zero

**Story**: US-FEE-008 scenario 3

```bash
# funding transaction has no fee rule configured
curl -s -X POST $API/v1/fees/calculate \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "transaction_type": "funding",
    "amount_minor": 100000,
    "currency": "BWP",
    "subject_type": "global"
  }' | jq '{fee: .data.fee_amount_minor, has_rule: .data.has_applicable_rule}'
```

**Expected**: `{"fee": 0, "has_rule": false}`

---

## SC-8 — Customer-specific fee override

**Story**: US-FEE-006

```bash
# Assumes a customer with known ID exists
CUSTOMER_ID="<uuid-of-test-customer>"

curl -s -X POST $API/v1/fees/rules \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d "{
    \"transaction_type\": \"transfer\",
    \"fee_type\": \"percentage\",
    \"rate\": 0.1,
    \"currency\": \"BWP\",
    \"subject_type\": \"customer\",
    \"subject_id\": \"$CUSTOMER_ID\",
    \"valid_from\": \"2026-01-01T00:00:00Z\"
  }" | jq '.data.subject_type'
```

**Expected**: `"customer"`

```bash
# Fee preview for that customer uses the override (0.1%), not the global (0.5%)
curl -s -X POST $API/v1/fees/calculate \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"transaction_type\": \"transfer\",
    \"amount_minor\": 100000,
    \"currency\": \"BWP\",
    \"subject_type\": \"customer\",
    \"subject_id\": \"$CUSTOMER_ID\"
  }" | jq '.data.fee_amount_minor'
```

**Expected**: `100` (0.1% of BWP 1,000 = BWP 1.00 = 100 minor units, assuming min_fee_minor is not set on the customer-specific rule)

---

## SC-9 — Fee ledger recording (integration test)

**Story**: US-FEE-009

This scenario requires a full transfer to be executed via E09. After the transfer completes:

```bash
TRANSFER_ID="<uuid-from-transfer-response>"

# Look up the Fee record for this transaction
curl -s "$API/v1/fees?transaction_id=$TRANSFER_ID" \
  -H "Authorization: Bearer $TOKEN" \
  | jq '{status: .data.status, amount: .data.amount_minor}'
```

**Expected**: `{"status": "posted", "amount": <non-zero>}`

See: [E09 quickstart.md](../../009-wallet-to-wallet-transfer/quickstart.md) for transfer execution setup.

---

## SC-10 — Deactivate a fee rule

**Story**: US-FEE-001 scenario 2

```bash
curl -s -o /dev/null -w "%{http_code}" -X DELETE "$API/v1/fees/rules/$RULE_ID" \
  -H "Authorization: Bearer $TOKEN"
```

**Expected**: `204`

```bash
# Verify the rule is deactivated
curl -s "$API/v1/fees/rules/$RULE_ID" \
  -H "Authorization: Bearer $TOKEN" | jq '.data.status'
```

**Expected**: `"deactivated"`

---

## SC-11 — Unauthorized access denied

**Story**: US-FEE-001 scenario 4

```bash
curl -s -o /dev/null -w "%{http_code}" -X POST $API/v1/fees/rules \
  -H "Authorization: Bearer <customer-jwt>" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"transaction_type":"transfer","fee_type":"percentage","rate":0.5,"currency":"BWP","subject_type":"global","valid_from":"2026-01-01T00:00:00Z"}'
```

**Expected**: `403`

---

## SC-12 — Settlement fee rule (E20 default_fee_rate migration)

**Story**: US-FEE-001 scenario 6

```bash
# Seed a settlement fee rule replacing config('reconciliation.default_fee_rate') = '0'
curl -s -X POST $API/v1/fees/rules \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{
    "transaction_type": "settlement",
    "fee_type": "fixed",
    "amount_minor": 1,
    "currency": "BWP",
    "subject_type": "global",
    "valid_from": "2026-01-01T00:00:00Z"
  }' | jq '.data.status'
```

**Note**: `amount_minor: 1` is the minimum value satisfying both the API validation rule (`amount_minor > 0`) and the DB CHECK constraint — no constraint bypass is needed. This rule is a bootstrap placeholder only; the admin must configure the real settlement fee amount before activating the settlement adapter.

---

## Reference

- Fee Rules contract: [contracts/fees-rules.yaml](contracts/fees-rules.yaml)
- Fee Calculate contract: [contracts/fees-calculate.yaml](contracts/fees-calculate.yaml)
- Data model: [data-model.md](data-model.md)
