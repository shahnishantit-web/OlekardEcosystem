# Quickstart Validation Guide — E16 QR & Merchant Payments

Runnable scenarios that prove the feature works end-to-end.  
See [data-model.md](data-model.md) for entity definitions and [contracts/](contracts/) for full API shapes.

## Prerequisites

- Docker stack running: `docker compose up -d`
- Seeded merchant with active wallet: `merchant_id = $MERCHANT_ID`, `merchant_wallet_id = $MERCHANT_WALLET`
- Seeded customer with BWP 500.00 balance: `customer_id = $CUSTOMER_ID`, `customer_wallet_id = $CUSTOMER_WALLET`
- Merchant auth token: `$MERCHANT_TOKEN`
- Customer auth token: `$CUSTOMER_TOKEN`

---

## SC-1 — Static QR Generation

**Goal**: Merchant generates a static QR; payload is valid and retrievable.

```bash
# Generate static QR
curl -s -X POST http://localhost/v1/qr-codes \
  -H "Authorization: Bearer $MERCHANT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type":"static","label":"Main Counter","currency":"BWP"}' | jq .
```

**Expected**:
- HTTP 201
- `data.type = "static"`, `data.status = "active"`, `data.expires_at = null`
- `data.payload` is a non-empty Base64URL string
- `data.amount = null`

```bash
QR_CODE_ID=$(curl -s ... | jq -r '.data.id')
QR_PAYLOAD=$(curl -s ... | jq -r '.data.payload')
```

---

## SC-2 — Dynamic QR Generation

**Goal**: Merchant generates a dynamic QR with pre-set amount and expiry.

```bash
curl -s -X POST http://localhost/v1/qr-codes \
  -H "Authorization: Bearer $MERCHANT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type":"dynamic","amount":50.00,"currency":"BWP","merchant_ref":"INV-001","expiry_seconds":300}' | jq .
```

**Expected**:
- HTTP 201
- `data.amount = "50.00"`, `data.expires_at` is a future timestamp
- `data.status = "active"`

---

## SC-3 — QR Resolve & Fee Quote (Static)

**Goal**: Customer scans a static QR, gets merchant details, then gets a fee quote.

```bash
# Resolve
curl -s -X POST http://localhost/v1/qr-payments/resolve \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"payload\":\"$QR_PAYLOAD\"}" | jq .
```

**Expected**:
- HTTP 200
- `data.amount_required = true`, `data.merchant.display_name` populated
- `data.type = "static"`

```bash
# Fee quote
curl -s -X POST http://localhost/v1/qr-payments/quote \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"qr_code_id\":\"$QR_CODE_ID\",\"amount\":20.00,\"currency\":\"BWP\"}" | jq .
```

**Expected**:
- HTTP 200
- `data.amount = "20.00"`, `data.fee` reflects configured rate, `data.total = amount + fee`

---

## SC-4 — Static QR Payment (Happy Path)

**Goal**: Customer pays via static QR; wallets update; receipt generated; ledger balanced.

```bash
curl -s -X POST http://localhost/v1/qr-payments \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d "{\"qr_code_id\":\"$QR_CODE_ID\",\"amount\":20.00,\"currency\":\"BWP\"}" | jq .
```

**Expected**:
- HTTP 201, `data.status = "completed"`
- `data.receipt_ref` is non-empty

**Verify ledger balance**:
```bash
# Customer wallet debited (20.00 + fee)
# Merchant wallet credited 20.00
# Fee wallet credited fee amount
# journal debit sum = credit sum
```

**Verify receipt**:
```bash
TX_ID=$(curl -s ... | jq -r '.data.id')
curl -s http://localhost/v1/qr-payments/$TX_ID/receipt \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" | jq .
```

**Expected**: HTTP 200, `data.total_debited = "21.00"` (if fee = 1.00)

---

## SC-5 — Idempotency (Duplicate Key)

**Goal**: Submitting the same `Idempotency-Key` twice returns the original response without a second debit.

```bash
KEY=$(uuidgen)
# First request
curl -s -X POST http://localhost/v1/qr-payments \
  -H "Idempotency-Key: $KEY" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"qr_code_id\":\"$STATIC_QR_ID\",\"amount\":20.00,\"currency\":\"BWP\"}" | jq .data.id

# Second request (same key)
curl -s -X POST http://localhost/v1/qr-payments \
  -H "Idempotency-Key: $KEY" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"qr_code_id\":\"$STATIC_QR_ID\",\"amount\":20.00,\"currency\":\"BWP\"}" | jq .data.id
```

**Expected**: Both return the same transaction ID. Customer wallet debited exactly once.

---

## SC-6 — Dynamic QR Single-Use Enforcement

**Goal**: A dynamic QR used for a completed payment cannot be used again.

```bash
# Generate dynamic QR
DYN_PAYLOAD=$(curl -s -X POST ... -d '{"type":"dynamic","amount":30.00,"currency":"BWP"}' | jq -r '.data.payload')

# First payment (succeeds)
curl -s -X POST http://localhost/v1/qr-payments \
  -H "Idempotency-Key: $(uuidgen)" ... -d "{\"payload\":\"$DYN_PAYLOAD\"}" | jq .data.status
# → "completed"

# Second payment attempt (same QR)
curl -s -X POST http://localhost/v1/qr-payments/resolve ... \
  -d "{\"payload\":\"$DYN_PAYLOAD\"}" | jq .errors
# → QR_ALREADY_USED
```

---

## SC-7 — Tampered Payload Rejected

**Goal**: A modified QR payload is rejected and audit-logged.

```bash
# Corrupt the payload
TAMPERED=$(echo "$QR_PAYLOAD" | sed 's/./X/50')

curl -s -X POST http://localhost/v1/qr-payments/resolve \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"payload\":\"$TAMPERED\"}" | jq .errors
```

**Expected**: HTTP 422, `INVALID_QR_PAYLOAD`. Audit log entry created.

---

## SC-8 — Insufficient Balance

**Goal**: Payment fails cleanly when customer has insufficient balance; no merchant credit.

```bash
# Use a customer wallet with BWP 5.00 balance
curl -s -X POST http://localhost/v1/qr-payments \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Authorization: Bearer $LOW_BALANCE_CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"qr_code_id\":\"$QR_CODE_ID\",\"amount\":200.00,\"currency\":\"BWP\"}" | jq .errors
```

**Expected**: HTTP 422, `INSUFFICIENT_BALANCE`. Merchant wallet unchanged. Dynamic QR returns to `active`.

---

## SC-9 — QR Deactivation

**Goal**: Merchant deactivates a QR; subsequent scan attempts fail.

```bash
# Deactivate
curl -s -X DELETE http://localhost/v1/qr-codes/$QR_CODE_ID \
  -H "Authorization: Bearer $MERCHANT_TOKEN" | jq .data.status
# → "inactive"

# Scan attempt
curl -s -X POST http://localhost/v1/qr-payments/resolve \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"payload\":\"$QR_PAYLOAD\"}" | jq .errors
# → QR_NOT_ACTIVE
```
