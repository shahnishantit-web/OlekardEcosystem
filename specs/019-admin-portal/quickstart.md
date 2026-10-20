# Quickstart Validation Guide — E19 Admin Portal

## Prerequisites

```bash
# Start the Docker stack
cd wallet-ecosystem
docker compose up -d

# Seed test data (includes an admin user, customers, wallets, transactions)
docker compose exec api php artisan db:seed --class=AdminPortalTestSeeder

# Admin credentials (seeded)
ADMIN_EMAIL=admin@olekard.local
ADMIN_PASSWORD=Admin123!
ADMIN_ID=<UUID printed by seeder>

# Second admin for maker-checker scenarios
CHECKER_EMAIL=checker@olekard.local
CHECKER_ID=<UUID printed by seeder>

# Get admin auth token
curl -X POST http://localhost:8000/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@olekard.local","password":"Admin123!"}' \
  | jq -r '.data.token'

export TOKEN="<token from above>"
export CHECKER_TOKEN="<token for checker@olekard.local>"
```

---

## SC-1: Dashboard loads all KPI sections

**Goal:** Verify that the dashboard endpoint returns all 7 KPI groups populated.

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/admin/dashboard?period=today" | jq .

# Assertions:
# - HTTP 200
# - .data.users.total > 0
# - .data.wallets.total_wallets > 0
# - .data.transactions is present
# - .data.kyc.total_submitted >= 0
# - .data.risk.open_alerts >= 0
# - X-Stats-Age header present (value 0 on first request, > 0 on second)
```

---

## SC-2: Search and suspend a user; verify audit trail

**Goal:** Suspend a user and confirm: (a) status changes, (b) AdminAction recorded.

```bash
# Search for user by phone
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/admin/users?q=+26771000001" | jq '.data[0].id'

export USER_ID="<id from above>"

# Suspend the user
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/v1/admin/users/$USER_ID/suspend" \
  -d '{"reason":"Test suspension for quickstart validation"}' | jq .

# Assertions:
# - HTTP 200
# - .data.action_type == "suspend_user"
# - .data.action_id is UUID

# Verify user status changed
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/admin/users/$USER_ID" | jq '.data.status'
# Expected: "suspended"

# Attempt second suspension → should 409
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/v1/admin/users/$USER_ID/suspend" \
  -d '{"reason":"Duplicate suspension attempt"}' | jq .message
# Expected: 409 with "User already suspended" message
```

---

## SC-3: Reactivate a suspended user

**Goal:** Reactivate the user suspended in SC-2.

```bash
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/v1/admin/users/$USER_ID/reactivate" \
  -d '{"reason":"Resolved suspension reason"}' | jq .

# Assertions:
# - HTTP 200
# - User status returns to "active" on subsequent GET
```

---

## SC-4: Search wallet, view balance, view ledger

**Goal:** Confirm admin can locate a wallet, check balance, and browse ledger.

```bash
# Search wallet by customer phone
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/admin/wallets?q=+26771000002" | jq '.data[0]'

export WALLET_ID="<id from above>"

# View wallet balance
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/admin/wallets/$WALLET_ID" | jq '.data.balance'
# Expected: decimal string > "0.00"

# View last 5 ledger entries
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/admin/wallets/$WALLET_ID/ledger?per_page=5" | jq '.data | length'
# Expected: > 0
```

---

## SC-5: Freeze wallet and verify subsequent transaction is blocked; then unfreeze

**Goal:** Confirm that freezing a wallet via admin blocks evaluation; lift restores access.

```bash
# Freeze wallet (maker = ADMIN, checker = CHECKER_ID)
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/v1/admin/wallets/$WALLET_ID/freeze" \
  -d "{\"reason\":\"AML investigation test\",\"checker_id\":\"$CHECKER_ID\",\"hold_type\":\"aml_investigation\"}" | jq .

# Assertions:
# - HTTP 201
# - AdminAction with action_type == "freeze_wallet" exists

# Verify evaluation returns block for frozen wallet
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/v1/risk/evaluate-transaction-risk" \
  -d "{\"idempotency_key\":\"test-freeze-$(date +%s)\",\"transaction_reference\":\"REF-TEST\",\"transaction_type\":\"transfer\",\"amount\":\"100.00\",\"currency\":\"BWP\",\"customer_id\":\"<wallet owner id>\"}" | jq '.data.decision'
# Expected: "block"

# Attempt duplicate freeze → 409
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/v1/admin/wallets/$WALLET_ID/freeze" \
  -d "{\"reason\":\"Duplicate\",\"checker_id\":\"$CHECKER_ID\",\"hold_type\":\"risk_freeze\"}" | jq .
# Expected: 409

# Unfreeze wallet
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/v1/admin/wallets/$WALLET_ID/unfreeze" \
  -d '{"reason":"Investigation cleared"}' | jq .
# Expected: HTTP 200
```

---

## SC-6: Controlled wallet adjustment — maker creates, checker approves, ledger verified

**Goal:** Confirm the maker-checker adjustment flow posts a correct double-entry journal.

```bash
export IDEM_KEY="adj-$(date +%s)"

# Step 1: Maker creates credit adjustment
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/v1/admin/wallets/$WALLET_ID/adjustments" \
  -d "{\"idempotency_key\":\"$IDEM_KEY\",\"adjustment_type\":\"credit\",\"amount\":\"50.00\",\"currency\":\"BWP\",\"reason\":\"Compensatory credit for service outage\"}" | jq .

export ADJ_ID="<id from above>"

# Assertions: status == "pending_approval"

# Step 2: Same maker cannot approve (maker-checker violation)
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/v1/admin/adjustments/$ADJ_ID/approve" \
  -d '{"reason":"Self-approving"}' | jq .message
# Expected: 422 maker-checker violation

# Step 3: Checker approves
curl -s -X POST \
  -H "Authorization: Bearer $CHECKER_TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/v1/admin/adjustments/$ADJ_ID/approve" \
  -d '{"reason":"Verified outage; approved"}' | jq '.data.status'
# Expected: "posted"

# Step 4: Verify ledger entry posted
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/admin/wallets/$WALLET_ID/ledger?per_page=1" | jq '.data[0].entry_type'
# Expected: "admin_adjustment"

# Step 5: Duplicate idempotency key → returns existing record (not 409 error)
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/v1/admin/wallets/$WALLET_ID/adjustments" \
  -d "{\"idempotency_key\":\"$IDEM_KEY\",\"adjustment_type\":\"credit\",\"amount\":\"50.00\",\"currency\":\"BWP\",\"reason\":\"Duplicate\"}" | jq '.data.id'
# Expected: same $ADJ_ID (idempotent)
```

---

## SC-7: Search and view a transaction; investigate a failed transaction

**Goal:** Verify search, detail view, and investigation endpoint.

```bash
# Search failed transactions
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/admin/transactions?status=failed&per_page=5" | jq '.data | length'
# Expected: ≥ 1

export TXN_ID="<id from above>"

# View transaction detail
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/admin/transactions/$TXN_ID" | jq '.data.status'
# Expected: "failed"

# Investigate
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/admin/transactions/$TXN_ID/investigate" | jq '.data.failure_reason'
# Expected: non-empty string
```

---

## SC-8: Reverse a completed transaction; verify compensating ledger entries

**Goal:** Confirm reversal posts compensating ledger entries and marks original transaction as reversed.

```bash
# Find a completed transfer transaction
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/admin/transactions?status=completed&type=transfer&per_page=1" | jq '.data[0].id'

export TRANSFER_ID="<id from above>"

# Reverse it (Idempotency-Key required — use a stable key so retries are safe)
export REV_IDEM_KEY="rev-$TRANSFER_ID"
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $REV_IDEM_KEY" \
  "http://localhost:8000/v1/admin/transactions/$TRANSFER_ID/reverse" \
  -d '{"reason":"Erroneous transfer — customer dispute"}' | jq .

# Assertions:
# - HTTP 200
# - .data.action_type == "transaction_reversed"
# - .data.ledger_transaction_id is UUID

# Verify original transaction status
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/admin/transactions/$TRANSFER_ID" | jq '.data.status'
# Expected: "reversed"

# Attempt second reversal with a NEW key → 409 (already reversed)
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: rev-$TRANSFER_ID-retry" \
  "http://localhost:8000/v1/admin/transactions/$TRANSFER_ID/reverse" \
  -d '{"reason":"Duplicate reversal"}' | jq .
# Expected: 409

# Replay original key → 200 same response (idempotency; no new ledger entry)
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $REV_IDEM_KEY" \
  "http://localhost:8000/v1/admin/transactions/$TRANSFER_ID/reverse" \
  -d '{"reason":"Erroneous transfer — customer dispute"}' | jq .data.action_id
# Expected: same action_id as the first response
```

---

## SC-9: Export transactions; verify CSV output

**Goal:** Confirm export endpoint returns CSV for small result sets.

```bash
# Export last 7 days of transactions (should be ≤ 10,000 rows in test environment)
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/v1/admin/transactions/export" \
  -d "{\"from\":\"$(date -d '-7 days' +%Y-%m-%d)\",\"to\":\"$(date +%Y-%m-%d)\"}" \
  -o /tmp/txn_export.csv

# Assertions:
# - HTTP 200
# - Content-Type: text/csv
# - /tmp/txn_export.csv has header row and ≥ 1 data row
head -2 /tmp/txn_export.csv
```

---

## SC-10: Unauthorized access is rejected; permission scoping is enforced

**Goal:** Verify that requests without a token or with insufficient permissions return 401/403.

```bash
# No token → 401
curl -s "http://localhost:8000/v1/admin/dashboard" | jq .
# Expected: HTTP 401

# Customer token (non-admin) → 403
export CUSTOMER_TOKEN="<token from a regular customer login>"
curl -s -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  "http://localhost:8000/v1/admin/dashboard" | jq .
# Expected: HTTP 403

# Admin without freeze_wallet permission trying to freeze → 403
# (Create a limited admin user without admin.freeze_wallet permission in test setup)
export LIMITED_ADMIN_TOKEN="<token for limited admin>"
curl -s -X POST \
  -H "Authorization: Bearer $LIMITED_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/v1/admin/wallets/$WALLET_ID/freeze" \
  -d "{\"reason\":\"Unauthorized attempt\",\"checker_id\":\"$CHECKER_ID\",\"hold_type\":\"risk_freeze\"}" | jq .
# Expected: HTTP 403
```

---

## Pass/Fail Criteria

| Scenario | Pass Condition |
|----------|----------------|
| SC-1 | HTTP 200; all 7 KPI sections present; X-Stats-Age header |
| SC-2 | User status → suspended; 409 on duplicate; AdminAction recorded |
| SC-3 | User status → active; AdminAction recorded |
| SC-4 | Wallet found; balance string; ledger entries returned |
| SC-5 | Wallet frozen; subsequent evaluation → block; 409 on duplicate; unfreeze succeeds |
| SC-6 | Adjustment pending; maker cannot approve; checker approves → posted; ledger entry = admin_adjustment; idempotent |
| SC-7 | Transaction found; investigation report returned |
| SC-8 | Reversal posted; compensating ledger entries visible; 409 on new-key duplicate; 200 idempotent replay on same key |
| SC-9 | CSV returned; header + data rows present |
| SC-10 | 401 without token; 403 with insufficient permission |
