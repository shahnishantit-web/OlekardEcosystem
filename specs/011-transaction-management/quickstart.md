# E11 Transaction Management — Quickstart & Validation Guide

## Prerequisites

- Docker stack running (`docker compose up -d`)
- Migrations run: `php artisan migrate`
- E08/E09/E10 modules deployed (event sources)
- Admin user seeded: `admin@olekard.local / Admin123!`
- `TRANSACTION_REVERSAL_REQUIRE_MAKER_CHECKER=true` in `.env`

Obtain a bearer token:
```bash
TOKEN=$(curl -s -X POST http://localhost:8080/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@olekard.local","password":"Admin123!"}' \
  | jq -r '.data.token')
```

---

## Scenario 1: Event Projection Population

Verify that E08/E09/E10 domain events populate the `transaction_projections` table.

### 1a. Simulate a Funding Completed event

Fire a funding event via queue (or seed directly for testing):
```bash
php artisan tinker --no-interaction <<'PHP'
use App\Modules\Funding\Domain\Events\FundingTransactionCompleted;
event(new FundingTransactionCompleted(
    fundingTransactionId: 'test-funding-id-001',
    correlationId: 'corr-001'
));
PHP
```

**Expected**: `transaction.transaction_projections` has one row:
```sql
SELECT id, reference, type, status, amount, source_module
FROM transaction.transaction_projections
WHERE id LIKE '%funding%' OR source_module = 'e08_funding'
LIMIT 5;
```
- `type = 'funding'`
- `status = 'completed'`
- `reference` starts with `TXN-`
- `transaction.transaction_status_history` has at least one row for the same `transaction_id`

### 1b. List transactions via API
```bash
curl -s -X GET "http://localhost:8080/api/v1/transactions" \
  -H "Authorization: Bearer $TOKEN" | jq '.data[0]'
```

**Expected**: JSON with `type`, `status`, `amount`, `currency_code`, `_links.status_history`.

---

## Scenario 2: Full-Text Search

```bash
curl -s -X GET "http://localhost:8080/api/v1/transactions?search=TXN-20260919" \
  -H "Authorization: Bearer $TOKEN" | jq '.meta.total'
```

**Expected**: Returns transactions whose `reference` or `description` matches the query.

---

## Scenario 3: IDOR Check (Cross-Customer 404)

Two customers: `customer-A` and `customer-B`. A transaction belonging to B should return 404 for A.

```bash
TX_B_ID="<id of a transaction owned by customer B>"
curl -s -X GET "http://localhost:8080/api/v1/transactions/$TX_B_ID" \
  -H "Authorization: Bearer $TOKEN_OF_A" | jq '.error.code'
```

**Expected**: `"TRANSACTION_NOT_FOUND"` (never 403 — IDOR-safe).

---

## Scenario 4: Status History

```bash
TX_ID="<a transaction uuid>"
curl -s -X GET "http://localhost:8080/api/v1/transactions/$TX_ID/status-history" \
  -H "Authorization: Bearer $TOKEN" | jq '.data | length'
```

**Expected**: At least 1 row. Rows are in ascending `occurred_at` order.

---

## Scenario 5: Reversal Lifecycle (Full End-to-End)

### 5a. Request a reversal
```bash
TX_ID="<a completed withdrawal or funding transaction uuid>"
IDEM_KEY=$(uuidgen)

curl -s -X POST "http://localhost:8080/api/v1/transactions/$TX_ID/reversals" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $IDEM_KEY" \
  -H "Content-Type: application/json" \
  -d '{"reason": "Customer requested reversal"}' | jq .
```

**Expected**: `201` with reversal `status = "pending_approval"`.

### 5b. Approve the reversal (different actor — maker-checker)
```bash
REVERSAL_ID="<reversal uuid from step 5a>"
APPROVER_TOKEN="<token for different admin>"
IDEM_KEY2=$(uuidgen)

curl -s -X POST "http://localhost:8080/api/v1/transactions/$TX_ID/reversals/$REVERSAL_ID/approve" \
  -H "Authorization: Bearer $APPROVER_TOKEN" \
  -H "Idempotency-Key: $IDEM_KEY2" | jq .
```

**Expected**: `200` with reversal `status = "approved"`.

Same actor as requester should return `422 REVERSAL_NOT_APPROVABLE` (maker-checker).

### 5c. Execute the reversal
```bash
curl -s -X POST "http://localhost:8080/api/v1/transactions/$TX_ID/reversals/$REVERSAL_ID/execute" \
  -H "Authorization: Bearer $APPROVER_TOKEN" | jq .
```

**Expected**:
- `200` with reversal `status = "executed"`
- Transaction `status` is now `"reversed"` (verify via `GET /transactions/{id}`)
- `ledger.journals` has a compensating entry with `idempotency_key = "reversal_execute:{REVERSAL_ID}"`
- `transaction.reversal_audit_log` has 3 rows: `reversal.requested`, `reversal.approved`, `reversal.executed`

### 5d. Audit trail
```bash
curl -s -X GET "http://localhost:8080/api/v1/transactions/$TX_ID/reversals/$REVERSAL_ID/audit" \
  -H "Authorization: Bearer $TOKEN" | jq '.data | map(.action)'
```

**Expected**: `["reversal.requested", "reversal.approved", "reversal.executed"]`

---

## Scenario 6: CC-E11-001 — Duplicate Reversal Request

Two calls with different idempotency keys for the same transaction must produce exactly one Reversal row.

```bash
TX_ID="<another completed transaction>"

# Call 1 — succeeds
curl -s -X POST "http://localhost:8080/api/v1/transactions/$TX_ID/reversals" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: key-aaa" \
  -H "Content-Type: application/json" \
  -d '{}' | jq '.data.status'
# Expected: "pending_approval"

# Call 2 — blocked by active reversal guard
curl -s -X POST "http://localhost:8080/api/v1/transactions/$TX_ID/reversals" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: key-bbb" \
  -H "Content-Type: application/json" \
  -d '{}' | jq '.error.code'
# Expected: "TRANSACTION_NOT_REVERSIBLE"
```

**DB verification**:
```sql
SELECT count(*) FROM transaction.reversals
WHERE transaction_id = '<TX_ID>'
  AND status IN ('pending_approval', 'approved');
-- Expected: 1
```

---

## Scenario 7: CC-E11-002 — Concurrent Execute (Idempotency)

Execute the same approved reversal twice with the same `reversal_execute:{rid}` idempotency key.

```bash
# Approve reversal first (see Scenario 5b)
REVERSAL_ID="<approved reversal uuid>"

# Execute call 1
curl -s -X POST "http://localhost:8080/api/v1/transactions/$TX_ID/reversals/$REVERSAL_ID/execute" \
  -H "Authorization: Bearer $TOKEN" | jq '.data.status'
# Expected: "executed"

# Execute call 2 — reversal now in executed status
curl -s -X POST "http://localhost:8080/api/v1/transactions/$TX_ID/reversals/$REVERSAL_ID/execute" \
  -H "Authorization: Bearer $TOKEN" | jq '.error.code'
# Expected: "REVERSAL_NOT_EXECUTABLE" (status = executed, not approved)
```

**DB verification**:
```sql
SELECT count(*) FROM ledger.journals
WHERE idempotency_key = 'reversal_execute:<REVERSAL_ID>';
-- Expected: 1 (exactly one compensating journal entry)
```

---

## Scenario 8: Receipt

```bash
TX_ID="<a completed transaction>"

# Fetch receipt (created on-demand)
curl -s -X GET "http://localhost:8080/api/v1/transactions/$TX_ID/receipt" \
  -H "Authorization: Bearer $TOKEN" | jq '.data'
```

**Expected**: Receipt JSON with `reference`, `amount`, `currency_code`, `type`. No `wallet_id` or `ledger_journal_id` fields exposed.

```bash
# Share via email
curl -s -X POST "http://localhost:8080/api/v1/transactions/$TX_ID/receipt/share" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"channel":"email","recipient":"customer@example.com"}' | jq '.data.message'
# Expected: "Receipt shared successfully."
```

---

## Scenario 9: Refund

```bash
TX_ID="<a completed transaction>"
IDEM_KEY=$(uuidgen)

curl -s -X POST "http://localhost:8080/api/v1/transactions/$TX_ID/refunds" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $IDEM_KEY" \
  -H "Content-Type: application/json" \
  -d '{"reason":"Duplicate charge"}' | jq '.data'
```

**Expected**:
- `201` response with new transaction row of `type = "refund"`
- `parent_transaction_id` = original `TX_ID`
- Original transaction status is still `"completed"` (unchanged)
- `ledger.journals` has a compensating entry with `idempotency_key = "refund_process:{IDEM_KEY}"`

---

## Running Unit Tests

```bash
cd wallet-ecosystem/services/api
php vendor/bin/phpunit tests/Unit/Modules/Transaction/ --no-coverage --testdox
```

**Expected**: 52 tests, 0 failures.

## PHPStan Level 8

```bash
cd wallet-ecosystem/services/api
APP_ENV=testing php vendor/bin/phpstan analyse --no-progress
```

**Expected**: 1 error (pre-existing `WalletModuleContractAdapter.php:14`, unrelated to E11). Zero new errors from Transaction module.
