# Quickstart Validation Guide — E15 Service & Bill Payments

This guide provides runnable end-to-end validation scenarios for the Service & Bill Payments module. Run these after Docker stack is up and migrations executed.

---

## Prerequisites

```bash
# Docker stack running
docker compose up -d

# Migrations applied (includes E15 015000–015500)
docker compose exec api php artisan migrate

# Auth token (admin)
ADMIN_TOKEN=$(curl -s -X POST http://localhost:8000/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"admin@olekard.local","credential":"Admin123!","credential_type":"password","device_fingerprint":"admin-web"}' \
  | jq -r '.data.access_token')

# Auth token (customer — seed or register a customer wallet user)
CUSTOMER_TOKEN=<customer_bearer_token>
CUSTOMER_WALLET_ID=<uuid_of_customer_wallet>
```

---

## SC1 — Admin Creates a Service

```bash
SERVICE_ID=$(curl -s -X POST http://localhost:8000/v1/services/admin \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: sc1-create-bpc-$(date +%s)" \
  -d '{
    "name": "Botswana Power Corporation",
    "category": "UTILITIES",
    "description": "Electricity bill payment",
    "required_fields": [
      {"name":"meter_number","label":"Meter Number","placeholder":"Enter 10-digit meter number","validation_regex":"^[0-9]{10}$","max_length":10}
    ],
    "fee_type": "flat",
    "fee_value": 200,
    "min_amount": 1000,
    "max_amount": 10000000,
    "currency_code": "BWP",
    "provider_code": "mock"
  }' | jq -r '.data.id')

echo "Created service: $SERVICE_ID"
# Expected: HTTP 201; data.status = "active"; data.fee_type = "flat"; data.fee_value = 200
```

## SC2 — Customer Discovers Services

```bash
# List active services
curl -s "http://localhost:8000/v1/services" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  | jq '.data.items[] | {id, name, category, status}'
# Expected: HTTP 200; service appears with status "active"

# Search by name
curl -s "http://localhost:8000/v1/services?search=Botswana" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  | jq '.data.items | length'
# Expected: ≥ 1

# View service details
curl -s "http://localhost:8000/v1/services/$SERVICE_ID" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  | jq '.data | {name, required_fields, fee_type, min_amount, max_amount}'
# Expected: required_fields array with meter_number field
```

## SC3 — Customer Validates Reference

```bash
curl -s -X POST "http://localhost:8000/v1/services/$SERVICE_ID/validate-reference" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"customer_reference":"1234567890"}' \
  | jq '.data'
# Expected: {valid: true, account_name: "Test Account", customer_reference: "1234567890"}
# (MockServiceProviderAdapter returns valid=true for any 10-digit reference)

# Invalid reference
curl -s -X POST "http://localhost:8000/v1/services/$SERVICE_ID/validate-reference" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"customer_reference":"INVALID"}' \
  | jq '.error.code'
# Expected: "INVALID_CUSTOMER_REFERENCE"
```

## SC4 — Fee Calculation

```bash
curl -s -X POST "http://localhost:8000/v1/services/$SERVICE_ID/calculate-fee" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"amount": 10000}' \
  | jq '.data'
# Expected: {amount: 10000, fee_amount: 200, total_amount: 10200, currency_code: "BWP"}

# Amount below minimum
curl -s -X POST "http://localhost:8000/v1/services/$SERVICE_ID/calculate-fee" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"amount": 500}' \
  | jq '.error.code'
# Expected: "AMOUNT_OUT_OF_RANGE"
```

## SC5 — Successful Payment End-to-End

```bash
IDEMPOTENCY_KEY="sc5-pay-$(date +%s)"

TX_ID=$(curl -s -X POST "http://localhost:8000/v1/services/$SERVICE_ID/payments" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $IDEMPOTENCY_KEY" \
  -d "{
    \"wallet_id\": \"$CUSTOMER_WALLET_ID\",
    \"customer_reference\": \"1234567890\",
    \"account_name\": \"Test Account\",
    \"amount\": 10000,
    \"currency_code\": \"BWP\"
  }" | jq -r '.data.id')

echo "Transaction: $TX_ID"
# Expected: HTTP 201; data.status = "pending_provider" (or "completed" if mock resolves synchronously)

# Check status
curl -s "http://localhost:8000/v1/services/payments/$TX_ID" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  | jq '.data | {status, journal_id, provider_reference}'
# Expected: status = "completed"; journal_id non-null; provider_reference non-null
```

### QS-E15-001: Idempotent Re-submission (no duplicate)

```bash
# Re-submit with same idempotency key
curl -s -X POST "http://localhost:8000/v1/services/$SERVICE_ID/payments" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $IDEMPOTENCY_KEY" \
  -d "{\"wallet_id\":\"$CUSTOMER_WALLET_ID\",\"customer_reference\":\"1234567890\",\"amount\":10000,\"currency_code\":\"BWP\"}" \
  | jq '.data.id'
# Expected: same transaction ID as SC5; no new row; HTTP 201

# Verify count
# SELECT COUNT(*) FROM service.service_transactions WHERE idempotency_key = '$IDEMPOTENCY_KEY';
# Expected: 1
```

## SC6 — Receipt Retrieval

```bash
curl -s "http://localhost:8000/v1/services/payments/$TX_ID/receipt" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  | jq '.data'
# Expected: receipt_reference non-null; service_name = "Botswana Power Corporation";
#           amount = 10000; fee_amount = 200; total_amount = 10200; issued_at non-null

# Re-fetch same receipt
curl -s "http://localhost:8000/v1/services/payments/$TX_ID/receipt" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  | jq '.data.receipt_reference'
# Expected: same receipt_reference as first fetch
```

## SC7 — Failed Payment (insufficient balance)

```bash
SC7_KEY="sc7-insufficient-$(date +%s)"

curl -s -X POST "http://localhost:8000/v1/services/$SERVICE_ID/payments" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $SC7_KEY" \
  -d "{
    \"wallet_id\": \"$CUSTOMER_WALLET_ID\",
    \"customer_reference\": \"1234567890\",
    \"amount\": 999999999,
    \"currency_code\": \"BWP\"
  }" | jq '.error.code'
# Expected: "INSUFFICIENT_BALANCE"; HTTP 402

# Verify: initiated record WAS created (captures the attempt), but no ledger entry exists
# SELECT id, status FROM service.service_transactions
# WHERE idempotency_key = '$SC7_KEY';
# Expected: 1 row; status = 'initiated' (no debit; eligible for stale cleanup after timeout)

# Verify: no ledger entry created for this idempotency key
# SELECT COUNT(*) FROM ledger.journals
# WHERE correlation_id IN (
#   SELECT correlation_id FROM service.service_transactions WHERE idempotency_key = '$SC7_KEY'
# );
# Expected: 0
```

## SC8 — Provider Callback (failure path)

```bash
# Simulate failure callback (mock provider)
curl -s -X POST "http://localhost:8000/v1/services/callback/mock" \
  -H "Content-Type: application/json" \
  -d "{
    \"transaction_id\": \"$TX_ID\",
    \"status\": \"failure\",
    \"provider_reference\": \"PROV-FAIL-001\",
    \"reason\": \"PROVIDER_DECLINED\"
  }" \
  -o /dev/null -w "%{http_code}"
# Expected: 200 (or if TX already completed, idempotent 200)

# Check balance restored
# SELECT balance FROM wallet.wallets WHERE id = '$CUSTOMER_WALLET_ID';
# Expected: balance restored to pre-payment value
```

## SC9 — Admin Deactivates Service

```bash
curl -s -X POST "http://localhost:8000/v1/services/admin/$SERVICE_ID/deactivate" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: sc9-deactivate-$(date +%s)" \
  | jq '.success'
# Expected: true

# Deactivated service not returned in customer list
curl -s "http://localhost:8000/v1/services?search=Botswana" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  | jq '.data.items | length'
# Expected: 0 (service excluded because inactive)

# Payment rejected for inactive service
curl -s -X POST "http://localhost:8000/v1/services/$SERVICE_ID/payments" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: sc9-pay-$(date +%s)" \
  -d "{\"wallet_id\":\"$CUSTOMER_WALLET_ID\",\"customer_reference\":\"1234567890\",\"amount\":10000,\"currency_code\":\"BWP\"}" \
  | jq '.error.code'
# Expected: "SERVICE_NOT_AVAILABLE"
```

---

## QS-E15-001 — Idempotency (no duplicate debit)

Covered in SC5 above (maps to spec SC-E15-004). Additional SQL verification:

```sql
-- Run against platform DB
SELECT COUNT(*) AS tx_count
FROM service.service_transactions
WHERE idempotency_key = '<your-idempotency-key>';
-- Expected: 1 (not 2)

SELECT COUNT(*) AS journal_count
FROM ledger.journals
WHERE correlation_id = '<correlation-id-from-tx>';
-- Expected: 1 debit journal (not 2)
```

## QS-E15-002 — Ledger Balance Integrity (maps to spec SC-E15-005)

```sql
SELECT
    j.id,
    j.transaction_reference,
    SUM(CASE WHEN jl.direction = 'debit'  THEN jl.amount ELSE 0 END) AS debits,
    SUM(CASE WHEN jl.direction = 'credit' THEN jl.amount ELSE 0 END) AS credits,
    SUM(CASE WHEN jl.direction = 'debit'  THEN jl.amount ELSE 0 END) =
    SUM(CASE WHEN jl.direction = 'credit' THEN jl.amount ELSE 0 END) AS balanced
FROM ledger.journals j
JOIN ledger.journal_lines jl ON jl.journal_id = j.id
WHERE j.entry_type IN ('service_payment', 'service_payment_reversal')
GROUP BY j.id, j.transaction_reference;
-- Expected: balanced = true for ALL rows
```

## QS-E15-003 — Balance Recovery on Failure (maps to spec SC-E15-006)

```sql
-- After a failed payment, verify compensating entry exists
SELECT jl.direction, jl.amount, j.entry_type
FROM ledger.journal_lines jl
JOIN ledger.journals j ON j.id = jl.journal_id
WHERE j.transaction_reference LIKE 'service_payment_reversal:%'
ORDER BY j.created_at DESC
LIMIT 4;
-- Expected: two pairs (debit service_payable / credit wallet) matching the original debit amount
```

## QS-E15-004 — Admin Force-Outcome (covers FR-E15-013)

```bash
# Manually place a tx in timeout_pending_review for testing
# (In test: use a special mock flag or directly update status in DB)

# Then force success
curl -s -X POST "http://localhost:8000/v1/services/admin/transactions/$TX_ID/resolve" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: sc-e15-004-resolve-$(date +%s)" \
  -d '{"outcome":"success","rationale":"Provider confirmed via phone","provider_reference":"PROV-MANUAL-001"}' \
  | jq '.data | {status, provider_reference}'
# Expected: status = "completed"; provider_reference = "PROV-MANUAL-001"

# Verify receipt generated
curl -s "http://localhost:8000/v1/services/payments/$TX_ID/receipt" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  | jq '.data.receipt_reference'
# Expected: non-null receipt reference
```

---

## Performance Benchmarks

| Scenario | Target | Check |
|----------|--------|-------|
| Service list (500 services) | < 2 s | `time curl /v1/services` |
| Reference validation (mock) | < 1 s | time the validate-reference call |
| Payment initiation (wallet debit + submit) | < 3 s | time the payment POST |
| Receipt fetch | < 0.5 s | time the receipt GET |

---

## SC-E15-001 — Catalog Performance (< 2 s for 500 active services)

```bash
# Seed 500 active services (run once; requires admin token)
for i in $(seq 1 500); do
  curl -s -X POST http://localhost:8000/v1/services/admin \
    -H "Authorization: Bearer $ADMIN_TOKEN" \
    -H "Content-Type: application/json" \
    -H "Idempotency-Key: seed-svc-$i" \
    -d "{\"name\":\"Service $i\",\"category\":\"OTHER\",\"required_fields\":[],\"fee_type\":\"flat\",\"fee_value\":100,\"min_amount\":1000,\"max_amount\":1000000,\"currency_code\":\"BWP\",\"provider_code\":\"mock\"}" \
    > /dev/null
done

# Measure catalog list time
time curl -s "http://localhost:8000/v1/services" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" | jq '.data.meta.total'
# Expected: response time < 2 s; total >= 500
```

## SC-E15-002 — Reference Validation Performance (< 5 s)

```bash
time curl -s -X POST "http://localhost:8000/v1/services/$SERVICE_ID/validate-reference" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"customer_reference":"1234567890"}' | jq '.data.valid'
# Expected: valid = true; response time < 5 s (includes mock provider round-trip)
```

## SC-E15-003 — Payment Initiation Performance (< 3 s)

```bash
time curl -s -X POST "http://localhost:8000/v1/services/$SERVICE_ID/payments" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: sc-perf-pay-$(date +%s%N)" \
  -d "{\"wallet_id\":\"$CUSTOMER_WALLET_ID\",\"customer_reference\":\"1234567890\",\"account_name\":\"Test Account\",\"amount\":10000,\"currency_code\":\"BWP\"}" \
  | jq '.data.status'
# Expected: status = "pending_provider" or "completed"; response time < 3 s
# (measures wallet debit + ledger post + provider submission)
```

## SC-E15-007 — Receipt Availability (< 1 s after completion)

```bash
# Use the TX_ID from a completed SC5 payment
time curl -s "http://localhost:8000/v1/services/payments/$TX_ID/receipt" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" | jq '.data.receipt_reference'
# Expected: receipt_reference non-null; response time < 1 s
# Re-fetch should return same reference without regeneration
```
