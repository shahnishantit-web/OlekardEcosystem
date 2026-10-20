# Quickstart Validation — E34 QA & Validation

## Prerequisites

```bash
# Start Docker dev stack
cd wallet-ecosystem && docker compose up -d

# Seed test cases (must run once before any SC)
docker compose exec api php artisan qa:seed

# Create a QA engineer user with qa.execute permission
# Use: admin@olekard.local / Admin123! with role = qa_engineer
# or seed with:
docker compose exec api php artisan db:seed --class=QaEngineerSeeder

# Obtain a bearer token for the QA engineer
TOKEN=$(curl -s -X POST http://localhost:8000/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"qa@olekard.local","password":"Qa123!"}' \
  | jq -r '.data.access_token')
```

---

## SC-1 — Trigger a QA test run (US-QA-001)

**What it validates:** POST `/v1/qa/unit-test-authentication` → 202; run dispatched; job completes; `TestRun.status = completed`.

```bash
IDEM_KEY=$(uuidgen)

RESPONSE=$(curl -s -X POST http://localhost:8000/v1/qa/unit-test-authentication \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $IDEM_KEY" \
  -H "Content-Type: application/json" \
  -d '{}')

RUN_ID=$(echo $RESPONSE | jq -r '.data.run_id')
echo "Run created: $RUN_ID"
```

**Expected:** `HTTP 202`, `data.status = "pending"`.

Wait for the Horizon worker to process (~ 5 s), then:

```bash
curl -s http://localhost:8000/v1/qa/runs/$RUN_ID \
  -H "Authorization: Bearer $TOKEN" \
  | jq '.data.status, (.data.results | length)'
```

**Expected:** `"completed"`, `8` results (one per scenario_type).

---

## SC-2 — Idempotency (same Idempotency-Key returns existing run)

```bash
RESPONSE2=$(curl -s -w "\n%{http_code}" \
  -X POST http://localhost:8000/v1/qa/unit-test-authentication \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $IDEM_KEY" \
  -H "Content-Type: application/json" \
  -d '{}')

HTTP_CODE=$(echo "$RESPONSE2" | tail -1)
RUN_ID2=$(echo "$RESPONSE2" | head -1 | jq -r '.data.run_id')
```

**Expected:** `HTTP 200`, `$RUN_ID2 == $RUN_ID`.

---

## SC-3 — Authorization: missing permission is rejected

```bash
# Obtain token for a user WITHOUT qa.execute
NO_QA_TOKEN=$(curl -s -X POST http://localhost:8000/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"agent@olekard.local","password":"Agent123!"}' \
  | jq -r '.data.access_token')

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
  -X POST http://localhost:8000/v1/qa/unit-test-authentication \
  -H "Authorization: Bearer $NO_QA_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{}')
echo $HTTP_CODE
```

**Expected:** `403`, error code `QA_FORBIDDEN`.

---

## SC-4 — Dashboard shows aggregate status

```bash
curl -s http://localhost:8000/v1/qa/dashboard \
  -H "Authorization: Bearer $TOKEN" \
  | jq '.data.summary, (.data.categories | length)'
```

**Expected:** `data.categories` has exactly 18 entries (one per story); `data.summary.total_categories = 18`. The category triggered in SC-1 shows `latest_status = "completed"`.

---

## SC-5 — Financial test run produces no ledger side-effects (US-QA-004)

Verify that triggering wallet-balance-testing does not create real wallet balance changes.

```bash
# Record current balance of seeded test wallet
BALANCE_BEFORE=$(curl -s http://localhost:8000/v1/wallets/qa-test-wallet-id \
  -H "Authorization: Bearer $TOKEN" \
  | jq '.data.balance_minor_units')

# Trigger wallet balance test run
curl -s -X POST http://localhost:8000/v1/qa/wallet-balance-testing \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{}' > /dev/null

# Wait for run
sleep 8

BALANCE_AFTER=$(curl -s http://localhost:8000/v1/wallets/qa-test-wallet-id \
  -H "Authorization: Bearer $TOKEN" \
  | jq '.data.balance_minor_units')

[ "$BALANCE_BEFORE" = "$BALANCE_AFTER" ] && echo "PASS: balance unchanged" || echo "FAIL: balance mutated"
```

**Expected:** `PASS: balance unchanged`.

---

## SC-6 — List runs with category filter

```bash
curl -s "http://localhost:8000/v1/qa/runs?category=unit_auth&per_page=5" \
  -H "Authorization: Bearer $TOKEN" \
  | jq '.data | length, .[0].category'
```

**Expected:** at least 1 result, all with `category = "unit_auth"`.

---

## Next step after validation

All 6 SCs passing → the QA module is end-to-end verified. Proceed to `/speckit-analyze` then `/speckit-converge`.
