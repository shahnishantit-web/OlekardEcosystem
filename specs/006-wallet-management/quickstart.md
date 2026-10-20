# Quickstart — E06 Wallet Management Validation Guide

This guide provides runnable validation scenarios to confirm E06 is correctly implemented. It does not contain implementation code — for that, see `tasks.md` and the implementation in `wallet-ecosystem/services/api/app/Modules/Wallet/`.

---

## Prerequisites

```
docker compose up -d
php artisan migrate --seed
php artisan horizon &
```

Seeded users:
- `admin@olekard.local` / `Admin123!` — ADMIN role
- Obtain a test CUSTOMER token by registering via E01 auth endpoints and completing KYC (E05) in the test environment.

---

## Scenario 1 — Auto-Wallet Creation via KYC Event (US-WALLET-001)

**Goal**: Confirm a wallet is auto-provisioned when `kyc.case.approved` is published.

```bash
# 1. Simulate a KYC approval event (or use the Admin API to approve a KYC case)
#    The kyc.case.approved event is published to platform.outbox_events.
#    The outbox relay dispatches ProcessKycApprovedForWalletJob.

# 2. Verify wallet creation
GET /v1/wallets/my
Authorization: Bearer <customer_token>

# Expected: HTTP 200, wallet.status = "pending", pockets contains BWP pocket
```

**What to check**:
- `wallet.wallets` has one row for the user
- `wallet.currency_pockets` has one BWP row linked to that wallet
- `wallet.wallet_status_history` has one row: `from_status = null`, `to_status = pending`
- `platform.outbox_events` has a `Wallet.Created` event

---

## Scenario 2 — Manual Wallet Creation Idempotency (FR-E06-007)

```bash
POST /v1/wallets
Authorization: Bearer <admin_token>
Idempotency-Key: idem-001
Content-Type: application/json

{ "user_id": "<customer_uuid>" }

# Call twice with same Idempotency-Key
# First: HTTP 201
# Second: HTTP 200 (idempotent replay — same response body, no duplicate wallet)
```

**What to check**:
- `wallet.wallets` still has exactly one row for the user after two calls
- `platform.idempotency_records` has one row for key `idem-001`

---

## Scenario 3 — Add Currency Pocket (US-WALLET-004)

```bash
POST /v1/wallets/{wallet_id}/pockets
Authorization: Bearer <customer_token>
Idempotency-Key: idem-002
Content-Type: application/json

{ "currency_code": "USD" }

# Expected: HTTP 201, pocket.currency_code = "USD", pocket.status = "active"

# Call again with same currency
POST /v1/wallets/{wallet_id}/pockets
Idempotency-Key: idem-003
{ "currency_code": "USD" }

# Expected: HTTP 409 POCKET_ALREADY_EXISTS
```

**What to check**:
- `wallet.currency_pockets` has two rows: BWP and USD
- `platform.outbox_events` has a `Wallet.Pocket.Added` event for USD

---

## Scenario 4 — Balance Formula Verification (US-WALLET-007, SC-E06-003)

```bash
# Requires E07 to be active or wallet.stub_ledger_balance = true (test env)

# Assume ledger_balance = 50000 (BWP minor units = 500.00 BWP)
# Assume held_amount = 10000 (holds totalling 100.00 BWP)
# Expected: available_balance = 40000

GET /v1/wallets/{wallet_id}/pockets/BWP/balance
Authorization: Bearer <customer_token>

# Expected response:
# {
#   "ledger_balance": 50000,
#   "held_amount": 10000,
#   "available_balance": 40000,
#   "pending_balance": 0,
#   "precision": 2,
#   "wallet_status": "active",
#   "as_of_timestamp": "<utc_rfc3339>"
# }
```

**Edge case — never negative**:
```bash
# With held_amount = 50000 and ledger_balance = 40000
# Expected: available_balance = 0 (not -10000)
```

---

## Scenario 5 — Concurrent Debit Safety (SC-E06-002, CC-E06-001)

Run this test from `tests/Concurrency/Modules/Wallet/`:

```bash
php artisan test tests/Concurrency/Modules/Wallet/ConcurrentDebitTest.php
```

**Manually verifiable**: Using two terminal sessions, issue simultaneous HTTP requests both debiting 60 BWP from a pocket with `available_balance = 100 BWP`:
- Exactly one returns HTTP 200 (hold placed, 60 BWP held)
- The other returns HTTP 422 `INSUFFICIENT_BALANCE`
- Final `available_balance = 40`, never negative
- `wallet.wallet_holds` has exactly one active hold

---

## Scenario 6 — Freeze and Transaction Blocking (US-WALLET-013, US-WALLET-016)

```bash
# 1. Freeze wallet
POST /v1/wallets/{wallet_id}/freeze
Authorization: Bearer <admin_token>
Idempotency-Key: idem-freeze-001
{ "reason": "Compliance investigation" }
# Expected: HTTP 200, status = "frozen"

# 2. Check audit
# platform.audit_events has: action=wallet.frozen, resource_id={wallet_id}

# 3. Attempt debit (via any transaction endpoint in E08 scope — or call WalletHoldService directly in test)
# Expected: HTTP 422 WALLET_FROZEN

# 4. Balance read still works
GET /v1/wallets/{wallet_id}/pockets/BWP/balance
# Expected: HTTP 200 (read is permitted on frozen wallets)

# 5. Same freeze request with same Idempotency-Key
POST /v1/wallets/{wallet_id}/freeze
Idempotency-Key: idem-freeze-001
# Expected: HTTP 200 (idempotent replay)

# 6. Different Idempotency-Key — freeze an already-frozen wallet
POST /v1/wallets/{wallet_id}/freeze
Idempotency-Key: idem-freeze-002
# Expected: HTTP 422 INVALID_WALLET_TRANSITION
```

---

## Scenario 7 — Wallet Closure (US-WALLET-015)

```bash
# Attempt to close wallet with balance
POST /v1/wallets/{wallet_id}/close
Authorization: Bearer <admin_token>
Idempotency-Key: idem-close-001
{ "reason": "Test closure" }
# Expected: HTTP 422 WALLET_HAS_BALANCE

# Zero out balance (E07 required for real settlement; in test env use DB seed)
# Then:
POST /v1/wallets/{wallet_id}/close
Idempotency-Key: idem-close-002
{ "reason": "Zero balance confirmed" }
# Expected: HTTP 200, status = "closed"
# All pockets transition to status = "disabled"
# wallet_status_history has closure entry
```

---

## Scenario 8 — IDOR Prevention (SC-E06-009)

```bash
# Customer A's token attempting to access Customer B's wallet_id
GET /v1/wallets/{customer_b_wallet_id}
Authorization: Bearer <customer_a_token>
# Expected: HTTP 404 (not 403 — IDOR prevention; SR-E06-003)

# Same for balance:
GET /v1/wallets/{customer_b_wallet_id}/pockets/BWP/balance
# Expected: HTTP 404
```

---

## Scenario 9 — Status History Immutability (US-WALLET-017, SC-E06-007)

```bash
# Create → Activate → Freeze → Unfreeze lifecycle
# Then:
GET /v1/wallets/{wallet_id}/status-history
# Expected: 4 entries in ascending occurred_at order:
# 1. null → pending
# 2. pending → active
# 3. active → frozen
# 4. frozen → active

# Verify DB: no row in wallet_status_history has been updated or deleted
SELECT * FROM wallet.wallet_status_history WHERE wallet_id = '...' ORDER BY occurred_at;
```

---

## Scenario 10 — WalletModuleContract (SC-E06-012, FR-E06-049)

```bash
# Unit test: verify WalletModuleContract methods are wired to real implementation
php artisan test tests/Unit/Modules/Wallet/WalletModuleContractTest.php

# Architecture test: no cross-module direct model access
php artisan test tests/Architecture/Modules/WalletBoundaryTest.php
```

---

## Scenario 11 — Hold Expiry Job (FR-E06-048)

```bash
# Seed an expired hold manually (expires_at in the past, status = active)
# Then run:
php artisan wallet:expire-holds

# Verify:
# wallet_holds row status = 'expired', expired_at is set
# platform.outbox_events has Wallet.Hold.Expired event
# Running the command again is idempotent (no double-expiry)
```

---

## Scenario 12 — Domain Events in Outbox (SC-E06-011)

```bash
# After any wallet mutation (create, freeze, add pocket, etc.):
SELECT event_name, payload, status FROM platform.outbox_events
WHERE aggregate_type LIKE 'Wallet%'
ORDER BY created_at DESC LIMIT 10;

# Verify: every mutation has a corresponding pending/dispatched outbox event
# No wallet mutation completes without a matching outbox entry (transactional guarantee)
```

---

## Running the Full E06 Test Suite

```bash
# Unit + integration
php artisan test tests/Unit/Modules/Wallet/ tests/Integration/Modules/Wallet/

# API tests
php artisan test tests/Feature/Api/V1/Wallets/

# Security (IDOR, auth, permission)
php artisan test tests/Security/Modules/Wallet/

# Concurrency
php artisan test tests/Concurrency/Modules/Wallet/

# Architecture boundary
php artisan test tests/Architecture/Modules/WalletBoundaryTest.php
```

All tests must pass green. Concurrency tests require a real PostgreSQL instance (not SQLite in-memory).
