# Quickstart Validation Guide — E20 Reconciliation & Settlement

## Prerequisites

```bash
cd wallet-ecosystem
docker compose up -d

# Seed test data
docker compose exec api php artisan db:seed --class=ReconciliationTestSeeder

# Admin credentials (reuse E19 seeded users)
ADMIN_EMAIL=admin@olekard.local
ADMIN_PASSWORD=Admin123!
CHECKER_EMAIL=checker@olekard.local

# Get admin auth token
export TOKEN=$(curl -s -X POST http://localhost:8000/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@olekard.local","password":"Admin123!"}' | jq -r '.data.token')

export CHECKER_TOKEN=$(curl -s -X POST http://localhost:8000/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"checker@olekard.local","password":"Admin123!"}' | jq -r '.data.token')
```

---

## SC-1: Import a provider transaction file and trigger matching

**Goal:** Verify file upload creates a batch, items are parsed, and matching runs.

```bash
# Create a minimal CSV file
cat > /tmp/provider_txns.csv << 'EOF'
provider_reference,amount,currency_code,transaction_date
PROV-REF-001,150.00,BWP,2026-09-27
PROV-REF-002,75.50,BWP,2026-09-27
PROV-REF-NOMATCH,999.99,BWP,2026-09-27
EOF

export IDEM_KEY="batch-$(date +%s)"

# Upload file
curl -s -X POST http://localhost:8000/v1/reconciliation/batches/file \
  -H "Authorization: Bearer $TOKEN" \
  -F "idempotency_key=$IDEM_KEY" \
  -F "batch_date=2026-09-27" \
  -F "file=@/tmp/provider_txns.csv" | jq .

# Assertions:
# - HTTP 201; .data.status == "draft" or "processing"
# - .data.total_count == 3

export BATCH_ID="<id from above>"

# Wait for async processing (or poll)
sleep 5

# Check batch status
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/reconciliation/batches/$BATCH_ID" | jq '.data.status'
# Expected: "matched" (processing complete)

# Verify counts
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/reconciliation/batches/$BATCH_ID" | jq '{matched: .data.matched_count, unmatched: .data.unmatched_count}'
# Expected: matched >= 0, unmatched >= 0, total = 3
```

---

## SC-2: Idempotent file import — same key returns existing batch

**Goal:** Confirm duplicate submission returns the same batch record, not a new one.

```bash
# Re-submit with same idempotency_key
curl -s -X POST http://localhost:8000/v1/reconciliation/batches/file \
  -H "Authorization: Bearer $TOKEN" \
  -F "idempotency_key=$IDEM_KEY" \
  -F "batch_date=2026-09-27" \
  -F "file=@/tmp/provider_txns.csv" | jq '.data.id'
# Expected: same $BATCH_ID (HTTP 200, not 201)
```

---

## SC-3: Detect and manage unmatched items; create and resolve an exception

**Goal:** Verify unmatched detection, exception lifecycle (open → resolved).

```bash
# List unmatched items from SC-1 batch
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/reconciliation/batches/$BATCH_ID/unmatched" | jq '.data | length'
# Expected: >= 1 (PROV-REF-NOMATCH should be unmatched)

export ITEM_ID=$(curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/reconciliation/batches/$BATCH_ID/unmatched" | jq -r '.data[0].id')

# List exceptions (auto-created for unmatched items)
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/reconciliation/exceptions?status=open" | jq '.data | length'
# Expected: >= 1

export EXCEPTION_ID=$(curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/reconciliation/exceptions?status=open" | jq -r '.data[0].id')

# Resolve the exception (write it off)
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/v1/reconciliation/exceptions/$EXCEPTION_ID/resolve" \
  -d '{"resolution_type":"written_off","resolution":"Test provider reference not found in system — written off for quickstart validation"}' | jq '.data.status'
# Expected: "written_off"

# Attempt to resolve again → 409
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/v1/reconciliation/exceptions/$EXCEPTION_ID/resolve" \
  -d '{"resolution_type":"written_off","resolution":"Duplicate attempt"}' | jq .
# Expected: 409 Conflict
```

---

## SC-4: Manually mark an item as reconciled

**Goal:** Verify authorized admin can manually reconcile an unmatched item.

```bash
# Get an unmatched item
export UNMATCHED_ITEM_ID=$(curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/reconciliation/batches/$BATCH_ID/items?status=unmatched" | jq -r '.data[0].id // empty')

if [ -n "$UNMATCHED_ITEM_ID" ]; then
  curl -s -X POST \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    "http://localhost:8000/v1/reconciliation/items/$UNMATCHED_ITEM_ID/mark-reconciled" \
    -d '{"reason":"Manually verified against bank records — confirmed correct transaction"}' | jq '.data.status'
  # Expected: "matched"
fi
```

---

## SC-5: Import bank statement and identify unmatched deposits/withdrawals

**Goal:** Verify bank statement import and unmatched identification (US-RECON-008–011).

```bash
cat > /tmp/bank_statement.csv << 'EOF'
provider_reference,amount,currency_code,transaction_date
BANK-DEP-001,200.00,BWP,2026-09-27
BANK-DEP-NOMATCH,500.00,BWP,2026-09-27
EOF

export BANK_IDEM_KEY="bank-$(date +%s)"

curl -s -X POST http://localhost:8000/v1/reconciliation/batches/bank-statement \
  -H "Authorization: Bearer $TOKEN" \
  -F "idempotency_key=$BANK_IDEM_KEY" \
  -F "batch_date=2026-09-27" \
  -F "file=@/tmp/bank_statement.csv" | jq .

# Assertions: HTTP 201; source_type == "bank_statement"

export BANK_BATCH_ID="<id from above>"

sleep 5

# List unmatched bank items
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/reconciliation/batches/$BANK_BATCH_ID/unmatched?item_type=bank_transaction" | jq '.data | length'
# Expected: >= 1 (BANK-DEP-NOMATCH)
```

---

## SC-6: Generate settlement batch, approve (maker-checker), execute, verify ledger

**Goal:** Verify full settlement lifecycle including maker-checker and ledger posting (US-RECON-012–016).

```bash
export SETTLE_IDEM_KEY="settle-$(date +%s)"

# Step 1: Maker creates settlement batch
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $SETTLE_IDEM_KEY" \
  "http://localhost:8000/v1/reconciliation/settlements" \
  -d '{"settlement_type":"provider_settlement","period_start":"2026-09-27","period_end":"2026-09-27","currency_code":"BWP"}' | jq .

export SETTLEMENT_ID="<id from above>"
# Assertions: .data.status == "draft"; .data.net_amount is a decimal string

# Step 2: Submit for approval
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/reconciliation/settlements/$SETTLEMENT_ID/submit" | jq '.data.status'
# Expected: "pending_approval"

# Step 3: Same maker cannot approve (maker-checker violation)
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: approve-$(date +%s)-admin" \
  "http://localhost:8000/v1/reconciliation/settlements/$SETTLEMENT_ID/approve" \
  -d '{"reason":"Self-approving"}' | jq .
# Expected: 422 maker-checker violation

# Step 4: Checker approves
curl -s -X POST \
  -H "Authorization: Bearer $CHECKER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: approve-$(date +%s)-checker" \
  "http://localhost:8000/v1/reconciliation/settlements/$SETTLEMENT_ID/approve" \
  -d '{"reason":"Settlement figures verified against bank records — approved for execution"}' | jq '.data.status'
# Expected: "approved"

# Step 5: Execute settlement
export EXEC_IDEM_KEY="exec-$SETTLEMENT_ID"
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $EXEC_IDEM_KEY" \
  "http://localhost:8000/v1/reconciliation/settlements/$SETTLEMENT_ID/execute" | jq '.data.status'
# Expected: "executing" or "executed"

sleep 3

# Step 6: Verify final status + ledger reference
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/reconciliation/settlements/$SETTLEMENT_ID" | jq '{status: .data.status, ledger_transaction_id: .data.ledger_transaction_id}'
# Expected: status=="executed"; ledger_transaction_id is a non-null string (E07 reference)
```

---

## SC-7: Idempotent settlement creation

**Goal:** Confirm duplicate create returns the same settlement batch.

```bash
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $SETTLE_IDEM_KEY" \
  "http://localhost:8000/v1/reconciliation/settlements" \
  -d '{"settlement_type":"provider_settlement","period_start":"2026-09-27","period_end":"2026-09-27","currency_code":"BWP"}' | jq '.data.id'
# Expected: same $SETTLEMENT_ID (HTTP 200, not 201)
```

---

## SC-8: Unauthorized access is rejected

**Goal:** Verify 401/403 enforcement.

```bash
# No token → 401
curl -s "http://localhost:8000/v1/reconciliation/batches" | jq .
# Expected: HTTP 401

# Customer token → 403 (use any non-admin token)
export CUSTOMER_TOKEN="<customer token>"
curl -s -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  "http://localhost:8000/v1/reconciliation/batches" | jq .
# Expected: HTTP 403

# Missing Idempotency-Key on settlement create → 400
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/v1/reconciliation/settlements" \
  -d '{"settlement_type":"provider_settlement","period_start":"2026-09-27","period_end":"2026-09-27","currency_code":"BWP"}' | jq .
# Expected: HTTP 400 "Idempotency-Key header is required"

# Non-checker attempting settlement approve → 403
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: attempt-$(date +%s)" \
  "http://localhost:8000/v1/reconciliation/settlements/$SETTLEMENT_ID/approve" \
  -d '{"reason":"Unauthorized"}' | jq .
# Expected: 409 (already executed) or 403 (if a fresh settlement is used)
```

---

## Pass/Fail Criteria

| Scenario | Pass Condition |
|----------|----------------|
| SC-1 | HTTP 201; batch created; items parsed; async matching completes |
| SC-2 | Duplicate import returns same batch ID (HTTP 200) |
| SC-3 | Unmatched items detected; exception created; resolve returns resolved/written_off; 409 on re-resolve |
| SC-4 | Manual reconcile transitions item to matched |
| SC-5 | Bank statement imported with source_type=bank_statement; unmatched bank items returned |
| SC-6 | Settlement created→submitted→approved (with maker-checker)→executed; ledger_transaction_id non-null |
| SC-7 | Duplicate settlement create returns same ID (HTTP 200) |
| SC-8 | 401 without token; 403 with insufficient role; 400 missing Idempotency-Key |
